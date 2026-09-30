<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Models\Game;
use App\Models\Room;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\SeededPrf;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Frontières de la partie — spec 50 § 12.6, § 12.7 et § 14, contrat C6
| (lot L50-7b)
|--------------------------------------------------------------------------
|
| Trois propriétés que rien ne rendrait visibles en relecture :
|
| - UNE PARTIE NE NAÎT QUE D'`OpenGame` (E10-41). Le lancement multijoueur
|   et le solo l'appellent dans leur transaction : garde de drainage, garde
|   de vivier rejouée, graine, règle figée. Une seconde insertion dans `game`
|   contournerait les trois. Les fabriques de test (`database/factories`)
|   sont exceptées : elles ne servent qu'aux tests.
| - LE DRAPEAU DE DRAINAGE N'A QUE DEUX ÉCRIVAINS, les commandes
|   `deploy:drain` et `deploy:release` (et le hook, qui les appelle) : aucun
|   code applicatif ne le pose ni ne le lève (100 § 11.3, C18-bis). `50` le
|   lit, jamais plus.
| - LES COLONNES FIGÉES DE `game` NE CHANGENT JAMAIS (§ 12.7) : la garde
|   `updating` du modèle lève sur toute sauvegarde qui en changerait une.
|
| Les deux balayages lisent le CODE au tokeniseur PHP, jamais les
| commentaires : un docblock qui cite `new Game` pour l'expliquer n'insère
| rien. Chaque détecteur est prouvé dans les deux sens sur des témoins écrits
| en chaînes littérales — donc invisibles au balayage lui-même.
|
*/

/**
 * Racines balayées : tout le code d'application, sans les tests, sans les
 * fabriques et sans le cache compilé du framework.
 *
 * @return list<string>
 */
function gameBoundaryPhpFiles(): array
{
    $files = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));

            if (str_starts_with($relative, 'database/factories/') || str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }

            $files[] = $relative;
        }
    }

    sort($files);

    return $files;
}

/**
 * Jetons signifiants d'une source : ni espaces, ni commentaires, ni balise.
 *
 * @return list<PhpToken>
 */
function gameBoundaryTokens(string $source): array
{
    return array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));
}

/** Contenu d'un littéral de chaîne simple, sans ses guillemets ; `null` sinon. */
function gameBoundaryLiteral(PhpToken $token): ?string
{
    return $token->is(T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : null;
}

/**
 * Espace de noms déclaré par une source, en minuscules, sans `\` initial.
 *
 * @param  list<PhpToken>  $tokens
 */
function gameBoundaryNamespace(array $tokens): string
{
    foreach ($tokens as $index => $token) {
        if ($token->is(T_NAMESPACE) && ($tokens[$index + 1] ?? null)?->is([T_NAME_QUALIFIED, T_STRING])) {
            return strtolower($tokens[$index + 1]->text);
        }
    }

    return '';
}

/**
 * Les noms qui désignent la classe `$class` dans une source, en minuscules :
 * son nom pleinement qualifié, son import (alias compris, import groupé
 * compris) et son nom court dans son propre espace de noms.
 *
 * @param  list<PhpToken>  $tokens
 * @return list<string>
 */
function gameBoundaryClassNames(array $tokens, string $class): array
{
    $class = strtolower(ltrim($class, '\\'));
    $short = Str::afterLast($class, '\\');
    $names = ['\\'.$class];

    if (gameBoundaryNamespace($tokens) === Str::beforeLast($class, '\\')) {
        $names[] = $short;
    }

    foreach ($tokens as $index => $token) {
        $name = $tokens[$index + 1] ?? null;

        if (! $token->is(T_USE) || ! $name?->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $prefix = strtolower(ltrim($name->text, '\\'));

        // Import groupé : `use App\Models\{Game, Room as Salon};`.
        if (($tokens[$index + 2] ?? null)?->is(T_NS_SEPARATOR) && ($tokens[$index + 3] ?? null)?->text === '{') {
            for ($cursor = $index + 4; $cursor < count($tokens) && $tokens[$cursor]->text !== '}'; $cursor++) {
                if (! $tokens[$cursor]->is([T_STRING, T_NAME_QUALIFIED]) || ($tokens[$cursor - 1] ?? null)?->is(T_AS)) {
                    continue;
                }

                if ($prefix.'\\'.strtolower($tokens[$cursor]->text) === $class) {
                    $names[] = ($tokens[$cursor + 1] ?? null)?->is(T_AS)
                        ? strtolower($tokens[$cursor + 2]->text)
                        : strtolower(Str::afterLast($tokens[$cursor]->text, '\\'));
                }
            }

            continue;
        }

        if ($prefix === $class) {
            $names[] = ($tokens[$index + 2] ?? null)?->is(T_AS) ? strtolower($tokens[$index + 3]->text) : $short;
        }
    }

    return array_values(array_unique($names));
}

/** Le jeton est-il un nom de classe qui figure parmi `$names` ? */
function gameBoundaryNames(?PhpToken $token, array $names): bool
{
    return $token !== null
        && $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
        && in_array(strtolower($token->text), $names, true);
}

/**
 * Les méthodes d'une chaîne d'appels qui commence à `$start` (le nom de la
 * première méthode) : `m1(…)->m2(…)?->m3(…)`, parenthèses équilibrées. La
 * chaîne s'arrête au premier segment qui n'est pas un appel.
 *
 * @param  list<PhpToken>  $tokens
 * @return list<string> noms en minuscules
 */
function gameBoundaryChain(array $tokens, int $start): array
{
    $methods = [];
    $cursor = $start;

    while (($tokens[$cursor] ?? null)?->is(T_STRING) && ($tokens[$cursor + 1] ?? null)?->text === '(') {
        $methods[] = strtolower($tokens[$cursor]->text);
        $depth = 0;

        for ($cursor++; $cursor < count($tokens); $cursor++) {
            $text = $tokens[$cursor]->text;

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')' && --$depth === 0) {
                break;
            }
        }

        if (! ($tokens[$cursor + 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            break;
        }

        $cursor += 2;
    }

    return $methods;
}

/**
 * Méthodes qui insèrent une ligne, ou fabriquent le modèle à insérer, par
 * une classe de modèle, une requête ou une relation.
 *
 * @return list<string>
 */
function gameBoundaryInserters(): array
{
    return [
        'create', 'createmany', 'createquietly', 'createmanyquietly', 'createorfirst', 'forcecreate',
        'forcecreatequietly', 'firstorcreate', 'updateorcreate', 'updateorinsert', 'insert', 'insertgetid',
        'insertorignore', 'insertusing', 'insertorignoreusing', 'upsert', 'fillandinsert', 'fillandinsertgetid',
        'make', 'newmodelinstance', 'newinstance', 'factory', 'save', 'savemany', 'savequietly', 'savemanyquietly',
    ];
}

/**
 * Toutes les insertions dans `game` d'une source, sous sept formes :
 *
 * - `new` : `new Game`, par tout nom qui désigne `App\Models\Game` ;
 * - `self` : `new static` ou `new self` dans la classe `Game` elle-même ;
 * - `static` : chaîne qui part de la classe (`Game::create()`,
 *   `Game::query()->…->insert()`, `Game::factory()`) et nomme un inserteur ;
 * - `table` : `->table('game')` ou `::table('game')` suivi d'un inserteur ;
 * - `relation` : `->game()` ou `->games()` suivi d'un inserteur ;
 * - `replicate` : `$…game->replicate()` ;
 * - `sql` : une chaîne SQL `INSERT` ou `REPLACE` dans la table `game`.
 *
 * @return list<array{line: int, form: string}>
 */
function gameBoundaryInsertions(string $source): array
{
    $tokens = gameBoundaryTokens($source);
    $names = gameBoundaryClassNames($tokens, Game::class);
    $inserters = gameBoundaryInserters();
    $selfDeclared = false;
    $sites = [];

    foreach ($tokens as $index => $token) {
        if ($token->is(T_CLASS) && gameBoundaryNames($tokens[$index + 1] ?? null, $names)) {
            $selfDeclared = true;
        }
    }

    foreach ($tokens as $index => $token) {
        $next = $tokens[$index + 1] ?? null;
        $previous = $tokens[$index - 1] ?? null;

        if ($token->is(T_NEW) && gameBoundaryNames($next, $names)) {
            $sites[] = ['line' => $token->line, 'form' => 'new'];
        }

        if ($token->is(T_NEW) && $selfDeclared && $next !== null && in_array(strtolower($next->text), ['static', 'self'], true)) {
            $sites[] = ['line' => $token->line, 'form' => 'self'];
        }

        if (gameBoundaryNames($token, $names) && ! $previous?->is(T_NEW) && $next?->is(T_DOUBLE_COLON)
            && array_intersect(gameBoundaryChain($tokens, $index + 2), $inserters) !== []) {
            $sites[] = ['line' => $token->line, 'form' => 'static'];
        }

        if ($token->is(T_STRING) && strtolower($token->text) === 'table'
            && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            && ($tokens[$index + 2] ?? null) !== null && gameBoundaryLiteral($tokens[$index + 2]) === 'game'
            && ($tokens[$index + 3] ?? null)?->text === ')'
            && ($tokens[$index + 4] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            && array_intersect(gameBoundaryChain($tokens, $index + 5), $inserters) !== []) {
            $sites[] = ['line' => $token->line, 'form' => 'table'];
        }

        if ($token->is(T_STRING) && in_array(strtolower($token->text), ['game', 'games'], true)
            && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            && array_intersect(gameBoundaryChain($tokens, $index), $inserters) !== []) {
            $sites[] = ['line' => $token->line, 'form' => 'relation'];
        }

        if ($token->is(T_STRING) && in_array(strtolower($token->text), ['replicate', 'replicatequietly'], true)
            && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            && ($tokens[$index - 2] ?? null)?->is(T_VARIABLE) && preg_match('/games?$/i', $tokens[$index - 2]->text) === 1) {
            $sites[] = ['line' => $token->line, 'form' => 'replicate'];
        }

        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])
            && preg_match('/\b(?:insert|replace)\s+(?:ignore\s+)?into\s+[`"\']?game[`"\']?(?![\w.])/i', $token->text) === 1) {
            $sites[] = ['line' => $token->line, 'form' => 'sql'];
        }
    }

    return $sites;
}

/**
 * Toutes les écritures du drapeau de drainage d'une source, sous trois formes :
 *
 * - `call:<méthode>` : `start`, `openWindow` ou `release`, appelée par `->`
 *   ou `::` dans une source qui désigne `DeployDrain` ;
 * - `key` : la clé de configuration du drapeau (`deploy.cache_key`) ou sa
 *   valeur, l'entrée du cache, en littéral ;
 * - `command` : le nom d'une commande qui pose ou lève le drapeau
 *   (`deploy:drain`, `deploy:release`), pour l'appeler.
 *
 * @return list<array{line: int, form: string}>
 */
function gameBoundaryDrainWrites(string $source, string $cacheKey): array
{
    $tokens = gameBoundaryTokens($source);
    $names = gameBoundaryClassNames($tokens, 'App\\Support\\Deploy\\DeployDrain');
    $designates = array_filter($tokens, static fn (PhpToken $token): bool => gameBoundaryNames($token, $names)) !== [];
    $sites = [];

    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $literal = gameBoundaryLiteral($token);

        if ($designates && $token->is(T_STRING)
            && in_array(strtolower($token->text), ['start', 'openwindow', 'release'], true)
            && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            && ($tokens[$index + 1] ?? null)?->text === '(') {
            $sites[] = ['line' => $token->line, 'form' => 'call:'.$token->text];
        }

        if ($literal !== null && ($literal === 'deploy.cache_key' || $literal === $cacheKey)) {
            $sites[] = ['line' => $token->line, 'form' => 'key'];
        }

        if ($literal !== null && preg_match('/^deploy:(?:drain|release)(?:\s|$)/', $literal) === 1) {
            $sites[] = ['line' => $token->line, 'form' => 'command'];
        }
    }

    return $sites;
}

it("n'insère dans game que depuis OpenGame, fabriques exceptées", function (): void {
    // Chaque forme d'insertion est vue, par tout nom qui désigne la classe.
    $witnesses = [
        'new' => ['<?php use App\Models\Game; $game = new Game; $game->save();', 'new'],
        'new, alias' => ['<?php use App\Models\Game as Partie; $game = new Partie();', 'new'],
        'new, import groupé' => ['<?php use App\Models\{Room, Game}; new Game([]);', 'new'],
        'new, nom qualifié' => ['<?php $game = new \App\Models\Game;', 'new'],
        'new, espace de noms' => ['<?php namespace App\Models; function f() { return new Game; }', 'new'],
        'new static' => ['<?php namespace App\Models; class Game { public function copy() { return new static; } }', 'self'],
        'create' => ['<?php use App\Models\Game; Game::create([]);', 'static'],
        'forceCreate en chaîne' => ['<?php use App\Models\Game; Game::query()->where("a", 1)->forceCreate([]);', 'static'],
        'insert en chaîne' => ['<?php use App\Models\Game; Game::query()->insert([]);', 'static'],
        'firstOrCreate' => ['<?php use App\Models\Game; Game::firstOrCreate(["a" => 1]);', 'static'],
        'fabrique' => ['<?php use App\Models\Game; Game::factory()->create();', 'static'],
        'make' => ['<?php use App\Models\Game; Game::make([])->save();', 'static'],
        'table' => ["<?php DB::table('game')->insert([]);", 'table'],
        'table, connexion' => ["<?php DB::connection('mysql')->table('game')->insertGetId([]);", 'table'],
        'relation' => ['<?php $room->games()->create([]);', 'relation'],
        'relation, belongsTo' => ['<?php $round->game()->firstOrCreate([]);', 'relation'],
        'replicate' => ['<?php $lockedGame->replicate()->save();', 'replicate'],
        'sql' => ["<?php DB::insert('insert into game (mode) values (?)', ['solo']);", 'sql'],
        'sql, guillemets' => ['<?php DB::statement("INSERT INTO `game` (mode) VALUES (1)");', 'sql'],
    ];

    foreach ($witnesses as $label => [$source, $form]) {
        expect(in_array($form, array_column(gameBoundaryInsertions($source), 'form'), true))->toBeTrue($label);
    }

    // Rien d'autre n'est une insertion : mises à jour, autres tables, autres
    // modèles, commentaires, constantes et lectures.
    $innocents = [
        'mise à jour' => ['<?php use App\Models\Game; Game::query()->whereKey($id)->update(["status" => "x"]);'],
        'sauvegarde' => ['<?php $game->forceFill(["status" => "x"])->save();'],
        'autre modèle' => ['<?php use App\Models\GamePlayer; new GamePlayer; GamePlayer::query()->insert([]);'],
        'autre table' => ["<?php DB::table('game_player')->insert([]); DB::insert('insert into game_player (x) values (1)');"],
        'autre salon' => ['<?php use App\Models\Room; new Room; Room::create([]);'],
        'commentaires' => ['<?php /* new Game; Game::create([]); */ // DB::table(\'game\')->insert([]);'],
        'constantes et lectures' => ['<?php use App\Models\Game; $c = Game::class; $f = Game::FROZEN_COLUMNS; Game::query()->lockForUpdate()->first();'],
        'relation lue' => ['<?php $room->games()->where("x", 1)->first();'],
        'nom homonyme' => ['<?php use App\Other\Game; new Game; Game::create([]);'],
    ];

    foreach ($innocents as $label => [$source]) {
        expect(gameBoundaryInsertions($source))->toBe([], $label);
    }

    // Le code d'application : un seul site, l'INSERT d'`OpenGame` (O5).
    $sites = [];

    foreach (gameBoundaryPhpFiles() as $file) {
        foreach (gameBoundaryInsertions((string) file_get_contents(base_path($file))) as $site) {
            $sites[] = ['file' => $file, 'form' => $site['form']];
        }
    }

    expect($sites)->toBe([['file' => 'app/Actions/Game/OpenGame.php', 'form' => 'new']]);
});

it('ne pose ni ne lève le drapeau de drainage hors des commandes de déploiement', function (): void {
    $cacheKey = config()->string('deploy.cache_key');

    // Chaque forme d'écriture est vue.
    $witnesses = [
        'levée' => ['<?php use App\Support\Deploy\DeployDrain; app(DeployDrain::class)->release();', 'call:release'],
        'pose, alias' => ['<?php use App\Support\Deploy\DeployDrain as Drain; function f(Drain $d) { $d->start(5); }', 'call:start'],
        'fenêtre, nom qualifié' => ['<?php $d = app(\App\Support\Deploy\DeployDrain::class); $d->openWindow(1);', 'call:openWindow'],
        'clé de configuration' => ["<?php Cache::forget(config('deploy.cache_key'));", 'key'],
        'entrée du cache' => ['<?php Cache::put('.var_export($cacheKey, true).', [], 60);', 'key'],
        'commande de pose' => ["<?php Artisan::call('deploy:drain --timeout=5');", 'command'],
        'commande de levée' => ["<?php Artisan::call('deploy:release');", 'command'],
    ];

    foreach ($witnesses as $label => [$source, $form]) {
        expect(in_array($form, array_column(gameBoundaryDrainWrites($source, $cacheKey), 'form'), true))->toBeTrue($label);
    }

    // Lire le drapeau n'est pas l'écrire ; `release()` d'un verrou ou d'un
    // job n'est pas celui du drapeau.
    $innocents = [
        'lecture' => ['<?php use App\Support\Deploy\DeployDrain; app(DeployDrain::class)->isDraining(); $d->state();'],
        'autres release' => ['<?php $lock->release(); $job->release(10);'],
        'garde' => ["<?php Artisan::call('deploy:guard');"],
        'commentaire' => ['<?php // app(App\Support\Deploy\DeployDrain::class)->release();'],
    ];

    foreach ($innocents as $label => [$source]) {
        expect(gameBoundaryDrainWrites($source, $cacheKey))->toBe([], $label);
    }

    // Écrivains autorisés : les deux commandes, le drapeau lui-même (qui lit
    // sa clé) et sa configuration (qui la déclare).
    $allowed = [
        'app/Console/Commands/DeployDrainCommand.php' => ['call:', 'command'],
        'app/Console/Commands/DeployReleaseCommand.php' => ['call:', 'command'],
        'app/Support/Deploy/DeployDrain.php' => ['key'],
        'config/deploy.php' => ['key', 'command'],
    ];
    $violations = [];
    $found = [];

    foreach (gameBoundaryPhpFiles() as $file) {
        foreach (gameBoundaryDrainWrites((string) file_get_contents(base_path($file)), $cacheKey) as $site) {
            $found[$file][] = $site['form'];
            $permitted = array_filter(
                $allowed[$file] ?? [],
                static fn (string $prefix): bool => str_starts_with($site['form'], $prefix),
            );

            if ($permitted === []) {
                $violations[] = $file.':'.$site['line'].' '.$site['form'];
            }
        }
    }

    expect($violations)->toBe([]);

    // Et les commandes écrivent bien : le balayage voit ce qu'il autorise.
    expect($found['app/Console/Commands/DeployDrainCommand.php'] ?? [])->toContain('call:start', 'call:openWindow', 'call:release')
        ->and($found['app/Console/Commands/DeployReleaseCommand.php'] ?? [])->toContain('call:release')
        ->and($found['app/Support/Deploy/DeployDrain.php'] ?? [])->toContain('key');
});

it('lève si une colonne figée de game est modifiée', function (): void {
    // La liste du contrat C6, à la lettre et dans son ordre.
    expect(Game::FROZEN_COLUMNS)->toBe([
        'mode', 'input_difficulty', 'rounds_count', 'frames_per_round', 'draw_seed', 'draw_pool_size',
        'tier_grace_ms', 'preload_lead_ms', 'settings_version', 'settings_snapshot', 'scoring_version',
        'validation_version', 'started_at', 'room_id',
    ]);

    $settings = RoomSettings::defaults();
    $room = Room::factory()->create();
    $elsewhere = Room::factory()->create();

    // Une valeur différente pour chaque colonne figée.
    $changes = [
        'mode' => GameMode::Solo,
        'input_difficulty' => InputDifficulty::Expert,
        'rounds_count' => $settings->roundsCount + 1,
        'frames_per_round' => RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        'draw_seed' => SeededPrf::generateSeed(),
        'draw_pool_size' => 1,
        'tier_grace_ms' => 1,
        'preload_lead_ms' => 1,
        'settings_version' => RoomSettings::VERSION + 1,
        'settings_snapshot' => RoomSettings::fromInput(['roundsCount' => $settings->roundsCount + 1]),
        'scoring_version' => 99,
        'validation_version' => 99,
        'started_at' => Date::now()->subMinute(),
        'room_id' => $elsewhere->id,
    ];

    expect(array_keys($changes))->toBe(Game::FROZEN_COLUMNS)
        ->and($settings->inputDifficulty)->not->toBe(InputDifficulty::Expert)
        ->and($settings->framesPerRound)->not->toBe(RoomSettingsBounds::MAX_FRAMES_PER_ROUND);

    foreach ($changes as $column => $value) {
        // Une partie relue en base, comme tout écrivain la relit.
        $created = Game::factory()->forRoom($room)->withSettings($settings)->create();
        $game = Game::query()->findOrFail($created->id);
        $before = (array) DB::table('game')->where('id', $game->id)->first();

        $game->forceFill([$column => $value]);

        expect($game->changedFrozenColumns())->toBe([$column])
            ->and(fn () => $game->save())->toThrow(LogicException::class, 'après le lancement : '.$column.' (')
            ->and((array) DB::table('game')->where('id', $game->id)->first())->toBe($before);
    }

    // Plusieurs à la fois : toutes nommées, dans l'ordre de la liste.
    $game = Game::factory()->forRoom($room)->withSettings($settings)->create();

    expect(fn () => $game->forceFill(['room_id' => $elsewhere->id, 'draw_seed' => SeededPrf::generateSeed()])->save())
        ->toThrow(LogicException::class, 'draw_seed, room_id');

    // Rien ne change par la VALEUR : relire l'instantané, ou le remplacer par
    // un objet égal, puis sauvegarder une colonne vivante ne lève pas.
    $game = Game::factory()->forRoom($room)->withSettings($settings)->create();
    $game = Game::query()->findOrFail($game->id);
    $before = (array) DB::table('game')->where('id', $game->id)->first();

    expect($game->settings_snapshot->equals($settings))->toBeTrue();

    $game->forceFill(['total_paused_ms' => 1_250])->save();
    $game->forceFill(['settings_snapshot' => RoomSettings::fromStorage($settings->toPayload(), $settings->sourceVersion)])->save();

    expect($game->changedFrozenColumns())->toBe([])
        ->and(Arr::except((array) DB::table('game')->where('id', $game->id)->first(), ['total_paused_ms', 'updated_at']))
        ->toBe(Arr::except($before, ['total_paused_ms', 'updated_at']));

    // Les colonnes vivantes s'écrivent par leurs écrivains : pause, puis gel.
    $game->forceFill(['status' => GameStatus::Paused, 'paused_at' => Date::now()])->save();
    $game->forceFill(['status' => GameStatus::Running, 'paused_at' => null])->save();

    expect(app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, Date::now()->toImmutable()))->toBeTrue()
        ->and(Arr::except((array) DB::table('game')->where('id', $game->id)->first(), ['status', 'paused_at', 'total_paused_ms', 'rounds_completed', 'ended_at', 'updated_at']))
        ->toBe(Arr::except($before, ['status', 'paused_at', 'total_paused_ms', 'rounds_completed', 'ended_at', 'updated_at']));

    // Même figée, la partie refuse toute réécriture de sa règle.
    expect(fn () => $game->refresh()->forceFill(['scoring_version' => 99])->save())
        ->toThrow(LogicException::class, 'scoring_version');
});
