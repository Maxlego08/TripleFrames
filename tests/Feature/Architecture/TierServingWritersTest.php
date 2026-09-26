<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Écrivains uniques du palier servi — spec 60 § 3.3 et § 6.1, contrat C8 § 2
| (lot L60-5), E10-47
|--------------------------------------------------------------------------
|
| Un palier passe par trois états, chacun écrit UNE fois par un écrivain
| unique : matérialisé, FRAPPÉ (`serve_token`, `served_frame_id`,
| `substitution_reason`, par `MintTierServeToken`), OUVERT (`served_at`
| théorique et l'upsert de `seen_frame`, par `OpenTier`). « Aucun autre code
| n'écrit ces cinq colonnes » — la route de service d'image, en particulier,
| est en lecture seule (10 § 7.4) : une requête cliente qui les écrirait
| daterait le journal sur le préchargement et fabriquerait une ligne
| `seen_frame` sur une image jamais montrée, influençant le tirage des
| parties suivantes du salon.
|
| Balayage statique de `app/` et `routes/` (le code de production ; les
| fabriques de test et le seeder de démonstration n'en sont pas), avec une
| liste d'autorisation nominative PAR COLONNE : la frappe n'ouvre rien,
| l'ouverture ne frappe rien. `OpenTier` n'est pas encore livré (L60-6) : son
| entrée est posée d'avance, pour que l'ouverture soit soumise à la règle
| dès sa naissance, et doit alors écrire `served_at` et `seen_frame`.
|
| Formes d'écriture d'une colonne : clé de tableau (`forceFill`, `update`,
| `insert`, `upsert`…, hors corps de `casts()`), affectation de propriété
| (simple ou composée, `++` / `--`), offset de modèle, premier argument de
| `setAttribute` / `increment` / `decrement` / `touch`, SQL brut `INSERT`,
| `UPDATE` ou `REPLACE`. Formes d'écriture de `seen_frame` : `new SeenFrame`,
| un appel écrivain dans la même instruction que `SeenFrame::`, que la
| relation `->seenFrames()` ou que `table('seen_frame')`, SQL brut sur la
| table. Une SUPPRESSION de `seen_frame` n'est pas une écriture au sens de la
| règle : la purge de rétention et le retrait juridique (J2) en suppriment.
|
| Le balayage lit le CODE au tokeniseur PHP, jamais les commentaires. Chaque
| détecteur est prouvé dans les deux sens sur des témoins écrits en chaînes
| littérales — donc invisibles au balayage de ce fichier.
|
*/

/**
 * Colonnes du palier servi, écrites par leurs seuls écrivains.
 *
 * @return list<string>
 */
function tierServingWritersColumns(): array
{
    return ['serve_token', 'served_frame_id', 'substitution_reason', 'served_at'];
}

/** Cible des écritures de la table `seen_frame`. */
function tierServingWritersSeenFrame(): string
{
    return 'seen_frame';
}

/**
 * Liste d'autorisation nominative : fichier → cibles qu'il écrit, et qu'il
 * DOIT écrire s'il existe.
 *
 * @return array<string, list<string>>
 */
function tierServingWritersAllowlist(): array
{
    return [
        'app/Actions/Game/MintTierServeToken.php' => ['serve_token', 'served_frame_id', 'substitution_reason'],
        'app/Actions/Game/OpenTier.php' => ['served_at', tierServingWritersSeenFrame()],
    ];
}

/**
 * Méthodes qui écrivent la ligne qu'elles visent (casse ignorée).
 *
 * @return list<string>
 */
function tierServingWritersWriterMethods(): array
{
    return [
        'create', 'createmany', 'createquietly', 'createorfirst', 'forcecreate', 'forcecreatequietly',
        'firstorcreate', 'firstornew', 'updateorcreate', 'make', 'fill', 'forcefill',
        'insert', 'insertorignore', 'insertgetid', 'insertusing', 'upsert',
        'update', 'updatequietly', 'updateorinsert', 'increment', 'decrement', 'incrementeach', 'decrementeach',
        'save', 'savequietly', 'savemany', 'savemanyquietly', 'push', 'touch',
    ];
}

/**
 * Chemins relatifs (séparateur `/`) des fichiers PHP sous des répertoires du
 * dépôt, triés.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function tierServingWritersPhpFiles(array $directories): array
{
    $files = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));
            }
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
function tierServingWritersTokens(string $source): array
{
    return array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));
}

/**
 * Contenu d'un littéral de chaîne simple, sans ses guillemets ; `null` pour
 * tout autre jeton.
 */
function tierServingWritersLiteral(?PhpToken $token): ?string
{
    return $token !== null && $token->is(T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : null;
}

/**
 * Opérateurs qui écrivent la propriété qu'ils suivent.
 */
function tierServingWritersIsAssignment(?PhpToken $token): bool
{
    return $token !== null && ($token->text === '=' || $token->is([
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_POW_EQUAL,
        T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
        T_INC, T_DEC,
    ]));
}

/**
 * Index des jetons compris dans le corps d'une méthode `casts()` : une
 * déclaration de type, jamais une écriture.
 *
 * @param  list<PhpToken>  $tokens
 * @return array<int, true>
 */
function tierServingWritersCastsBody(array $tokens): array
{
    $exempt = [];

    foreach ($tokens as $index => $token) {
        if (! $token->is(T_FUNCTION) || strtolower($tokens[$index + 1]->text ?? '') !== 'casts') {
            continue;
        }

        $depth = 0;

        for ($cursor = $index + 2; $cursor < count($tokens); $cursor++) {
            $text = $tokens[$cursor]->text;

            if ($depth === 0 && $text === ';') {
                break;
            }

            if ($text === '{' || $tokens[$cursor]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            if ($depth > 0) {
                $exempt[$cursor] = true;
            }
        }
    }

    return $exempt;
}

/**
 * Vrai si l'instruction qui part du jeton `$start` appelle une méthode
 * écrivaine : de `$start` au premier `;` (ou `,`) hors de toute parenthèse,
 * crochet ou accolade ouverts depuis `$start`, ou à la fermeture de celle qui
 * l'englobe.
 *
 * @param  list<PhpToken>  $tokens
 */
function tierServingWritersStatementWrites(array $tokens, int $start): bool
{
    $depth = 0;

    for ($cursor = $start; $cursor < count($tokens); $cursor++) {
        $text = $tokens[$cursor]->text;

        if ($depth === 0 && in_array($text, [';', ','], true)) {
            return false;
        }

        if (in_array($text, ['(', '[', '{'], true) || $tokens[$cursor]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $depth--;

            if ($depth < 0) {
                return false;
            }
        }

        $previous = $tokens[$cursor - 1] ?? null;

        if ($tokens[$cursor]->is(T_STRING)
            && $previous !== null
            && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            && ($tokens[$cursor + 1] ?? null)?->text === '('
            && in_array(strtolower($text), tierServingWritersWriterMethods(), true)) {
            return true;
        }
    }

    return false;
}

/**
 * Nom court d'un jeton de nom de classe (`SeenFrame`, `App\Models\SeenFrame`,
 * `\App\Models\SeenFrame`), ou `null`.
 */
function tierServingWritersClassName(PhpToken $token): ?string
{
    if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
        return null;
    }

    return Str::afterLast($token->text, '\\');
}

/**
 * Toutes les écritures de colonnes du palier servi et de la table
 * `seen_frame` d'une source.
 *
 * @return list<array{line: int, target: string, form: string}>
 */
function tierServingWritersWrites(string $source): array
{
    $tokens = tierServingWritersTokens($source);
    $castsBody = tierServingWritersCastsBody($tokens);
    $columns = tierServingWritersColumns();
    $seenFrame = tierServingWritersSeenFrame();
    $callers = ['setattribute', 'increment', 'decrement', 'incrementquietly', 'decrementquietly', 'touch', 'touchquietly'];
    $sqlColumns = implode('|', array_map(static fn (string $column): string => preg_quote($column, '/'), $columns));
    $writes = [];

    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;
        $literal = tierServingWritersLiteral($token);
        // Colonne qualifiée par la table (`'round_tier.served_at'`) : seule
        // compte la colonne.
        $column = $literal === null ? null : Str::afterLast($literal, '.');

        // Clé de tableau.
        if (in_array($column, $columns, true) && $next?->is(T_DOUBLE_ARROW) && ! isset($castsBody[$index])) {
            $writes[] = ['line' => $token->line, 'target' => (string) $column, 'form' => 'array'];
        }

        // Offset de modèle : `$tier['serve_token'] = …`.
        if (in_array($column, $columns, true) && $previous?->text === '[' && $next?->text === ']' && tierServingWritersIsAssignment($tokens[$index + 2] ?? null)) {
            $writes[] = ['line' => $token->line, 'target' => (string) $column, 'form' => 'offset'];
        }

        // Premier argument d'un appel qui écrit la colonne nommée.
        if (in_array($column, $columns, true) && $previous?->text === '(' && ($tokens[$index - 2] ?? null)?->is(T_STRING)
            && in_array(strtolower($tokens[$index - 2]->text), $callers, true)) {
            $writes[] = ['line' => $token->line, 'target' => (string) $column, 'form' => 'call'];
        }

        // Propriété : `->served_at` suivi d'une affectation, ou précédé de `++` / `--`.
        if ($token->is(T_STRING) && in_array($token->text, $columns, true) && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $prefixed = ($tokens[$index - 2] ?? null)?->is(T_VARIABLE) && ($tokens[$index - 3] ?? null)?->is([T_INC, T_DEC]);

            if (tierServingWritersIsAssignment($next) || $prefixed) {
                $writes[] = ['line' => $token->line, 'target' => $token->text, 'form' => 'property'];
            }
        }

        // SQL brut.
        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
            if (preg_match('/\b(?:insert|update|replace)\b[\s\S]*\b('.$sqlColumns.')\b/i', $token->text, $match) === 1) {
                $writes[] = ['line' => $token->line, 'target' => strtolower($match[1]), 'form' => 'sql'];
            }

            if (preg_match('/\b(?:insert\s+(?:ignore\s+)?into|update|replace\s+into)\s+[`"]?seen_frame\b/i', $token->text) === 1) {
                $writes[] = ['line' => $token->line, 'target' => $seenFrame, 'form' => 'sql'];
            }
        }

        // `new SeenFrame` : construire une ligne, c'est l'écrire.
        if (tierServingWritersClassName($token) === 'SeenFrame' && $previous?->is(T_NEW)) {
            $writes[] = ['line' => $token->line, 'target' => $seenFrame, 'form' => 'new'];
        }

        // `SeenFrame::…` suivi, dans l'instruction, d'un appel écrivain.
        if (tierServingWritersClassName($token) === 'SeenFrame' && $next?->is(T_DOUBLE_COLON)
            && ! ($tokens[$index + 2] ?? null)?->is(T_CLASS)
            && tierServingWritersStatementWrites($tokens, $index + 1)) {
            $writes[] = ['line' => $token->line, 'target' => $seenFrame, 'form' => 'model'];
        }

        // Relation `->seenFrames()` suivie d'un appel écrivain.
        if ($token->is(T_STRING) && $token->text === 'seenFrames' && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            && tierServingWritersStatementWrites($tokens, $index + 1)) {
            $writes[] = ['line' => $token->line, 'target' => $seenFrame, 'form' => 'relation'];
        }

        // `table('seen_frame')` suivi d'un appel écrivain, après la parenthèse
        // qui ferme l'appel.
        if ($literal === $seenFrame && $previous?->text === '(' && strtolower($tokens[$index - 2]->text ?? '') === 'table'
            && $next?->text === ')' && tierServingWritersStatementWrites($tokens, $index + 2)) {
            $writes[] = ['line' => $token->line, 'target' => $seenFrame, 'form' => 'table'];
        }
    }

    return $writes;
}

/**
 * Violations d'une source : toute écriture d'une cible que le fichier n'a
 * pas le droit d'écrire.
 *
 * @param  list<string>  $allowed
 * @return list<string>
 */
function tierServingWritersViolations(string $file, string $source, array $allowed): array
{
    $violations = [];

    foreach (tierServingWritersWrites($source) as $write) {
        if (! in_array($write['target'], $allowed, true)) {
            $violations[] = sprintf('%s:%d écrit %s (%s)', $file, $write['line'], $write['target'], $write['form']);
        }
    }

    return $violations;
}

test('seules MintTierServeToken et OpenTier écrivent serve_token, served_frame_id, substitution_reason, served_at et seen_frame', function (): void {
    // Détecteur, sens négatif : chaque forme d'écriture est vue, avec sa cible.
    $unauthorized = [
        'forceFill' => ["<?php \$tier->forceFill(['serve_token' => bin2hex(random_bytes(16))])->save();", 'serve_token'],
        'update en masse' => ["<?php RoundTier::query()->whereKey(\$id)->update(['served_frame_id' => 3]);", 'served_frame_id'],
        'colonne qualifiée' => ["<?php DB::table('round_tier')->join('round', 'round.id', '=', 'round_tier.round_id')->update(['round_tier.served_at' => \$now]);", 'served_at'],
        'affectation de propriété' => ['<?php $tier->served_at = $now;', 'served_at'],
        'affectation composée' => ['<?php $tier->substitution_reason ??= RoundIncidentReason::FrameUnavailable;', 'substitution_reason'],
        'offset de modèle' => ["<?php \$tier['serve_token'] = 'x';", 'serve_token'],
        'setAttribute' => ["<?php \$tier->setAttribute('served_at', \$now);", 'served_at'],
        'touch' => ["<?php \$tier->touch('served_at');", 'served_at'],
        'SQL brut' => ["<?php DB::update('UPDATE round_tier SET serve_token = ? WHERE id = ?', [\$t, 1]);", 'serve_token'],
        'new SeenFrame' => ['<?php $seen = new SeenFrame;', 'seen_frame'],
        'upsert par le modèle' => ["<?php SeenFrame::query()->upsert([['room_id' => 1, 'frame_id' => 2, 'last_seen_at' => \$now]], ['room_id', 'frame_id'], ['last_seen_at']);", 'seen_frame'],
        'updateOrCreate qualifié' => ["<?php \\App\\Models\\SeenFrame::updateOrCreate(['room_id' => 1, 'frame_id' => 2], ['last_seen_at' => \$now]);", 'seen_frame'],
        'relation' => ["<?php \$room->seenFrames()->create(['frame_id' => 2, 'last_seen_at' => \$now]);", 'seen_frame'],
        'façade' => ["<?php DB::table('seen_frame')->insert(['room_id' => 1, 'frame_id' => 2, 'last_seen_at' => \$now]);", 'seen_frame'],
        'SQL brut sur seen_frame' => ["<?php DB::statement('INSERT INTO seen_frame (room_id, frame_id, last_seen_at) VALUES (1, 2, NOW())');", 'seen_frame'],
    ];

    foreach ($unauthorized as $label => [$source, $target]) {
        expect(in_array($target, array_column(tierServingWritersWrites($source), 'target'), true))->toBeTrue($label)
            ->and(tierServingWritersViolations('témoin', $source, []))->not->toBeEmpty($label);
    }

    // Détecteur, sens positif : lectures, déclarations, suppressions et
    // citations ne sont pas des écritures.
    $readOnly = <<<'PHP'
        <?php
        #[Hidden(['serve_token', 'served_frame_id'])]
        class Witness extends Model
        {
            /** $tier->served_at = $now ; 'serve_token' => 'x' ; UPDATE round_tier SET served_at = NOW() ; new SeenFrame */
            protected function casts(): array
            {
                return ['served_frame_id' => 'integer', 'served_at' => 'datetime'];
            }

            public function read(RoundTier $tier, Room $room): array
            {
                // $tier->serve_token = 'x';
                $open = $tier->served_at !== null && $tier->served_at == $now;
                $token = RoundTier::query()->where('serve_token', $token)->whereNotNull('served_at')->value('served_frame_id');
                $seen = SeenFrame::query()->where('room_id', $room->id)->pluck('last_seen_at');
                $relation = $room->seenFrames()->where('frame_id', 1)->exists();
                $joined = Frame::query()->leftJoin('seen_frame', 'seen_frame.frame_id', '=', 'frame.id')->get();
                $purged = SeenFrame::query()->where('last_seen_at', '<', $cutoff)->delete();
                $class = SeenFrame::class;

                return $this->hasMany(SeenFrame::class) ? [$open, $token, $seen, $relation, $joined, $purged, $class] : [];
            }
        }
        PHP;

    expect(tierServingWritersWrites($readOnly))->toBe([]);

    // Liste d'autorisation PAR COLONNE : la frappe n'ouvre rien, l'ouverture
    // ne frappe rien.
    $mintWritingServedAt = "<?php \$locked->forceFill(['serve_token' => \$t, 'served_frame_id' => \$f, 'substitution_reason' => null, 'served_at' => \$now]);";
    $openTierMinting = "<?php \$tier->forceFill(['served_at' => \$at])->save(); SeenFrame::query()->upsert(\$rows, ['room_id', 'frame_id'], ['last_seen_at']); \$tier->serve_token = \$t;";

    expect(tierServingWritersViolations('témoin', $mintWritingServedAt, tierServingWritersAllowlist()['app/Actions/Game/MintTierServeToken.php']))
        ->toBe(['témoin:1 écrit served_at (array)'])
        ->and(tierServingWritersViolations('témoin', $openTierMinting, tierServingWritersAllowlist()['app/Actions/Game/OpenTier.php']))
        ->toBe(['témoin:1 écrit serve_token (property)']);

    // Balayage du code de production.
    $allowlist = tierServingWritersAllowlist();
    $files = tierServingWritersPhpFiles(['app', 'routes']);
    $violations = [];

    expect($files)->toContain('app/Models/RoundTier.php', 'app/Models/SeenFrame.php', 'routes/game.php');

    foreach ($files as $file) {
        $violations = [...$violations, ...tierServingWritersViolations(
            $file,
            (string) file_get_contents(base_path($file)),
            $allowlist[$file] ?? [],
        )];
    }

    expect($violations)->toBe([]);

    // Chaque écrivain livré écrit bien ce qui lui revient : une écriture
    // déplacée hors de son écrivain rougit ici autant qu'au balayage.
    foreach ($allowlist as $file => $targets) {
        if (! is_file(base_path($file))) {
            // `OpenTier` : livré par L60-6, soumis à la règle dès sa naissance.
            expect($file)->toBe('app/Actions/Game/OpenTier.php');

            continue;
        }

        $written = array_unique(array_column(tierServingWritersWrites((string) file_get_contents(base_path($file))), 'target'));

        sort($written);
        sort($targets);

        expect($written)->toBe($targets, $file);
    }
});
