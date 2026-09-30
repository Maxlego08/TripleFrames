<?php

use App\Actions\Game\SubmitChoice;
use App\Actions\Game\SubmitTextAnswer;
use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\AnswerAccepted;
use App\Events\Game\InputClosed;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\AnswerKey;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Support\Answers\ChoicesPresenter;
use App\Support\Answers\DecoyPicker;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Draw\DrawContext;
use App\Support\Draw\SeededPrf;
use App\Support\I18n\DisplayTitleResolver;
use App\Support\Identity\PlayerToken;
use App\ValueObjects\Answers\ChoicesPayload;
use App\ValueObjects\Answers\SeatInputView;
use App\ValueObjects\Scoring\TierScore;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Database\Factories\RoundPlayerFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\ChoiceSetFixtures;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Composition et présentation du QCM — spec 70 § 10.5-10.8, contrat C11
|--------------------------------------------------------------------------
|
| `ComposeChoiceSets` écrit une ligne `round_choice_set` par locale activée
| (`choice_1` = la cible), la locale effective atteinte (`rendered_locale`)
| et la langue de composition de chaque siège ; `ChoicesPresenter` rejoue la
| ligne du siège, permutée par `draw:qcm:{sequenceIndex}:{publicId}`, avec le
| seul drapeau `useOriginalTitle` et `lang`.
|
| Graines fixes, vrais films de vivier (`PoolFixtures`), titres inventés.
| `$this->at` est l'instant THÉORIQUE d'ouverture du QCM : la manche est
| démarrée pour qu'il le soit exactement (`ChoiceSetFixtures::round()`).
|
| Le jugement du clic (lot L70-9) se joue PAR LA ROUTE, sur une partie
| matérialisée par les actions réelles (`choiceSetPlayedRound()`) : la
| chaîne cliquée traverse le middleware global `TrimStrings`, comme en
| production. `SeatInputView` (même lot) y est lue après la composition.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->at = CarbonImmutable::parse('2026-09-24 10:00:20.250');
});

/** Un titre inventé, unique dans le test. */
function choiceSetTitle(): string
{
    /** @var string $words */
    $words = fake()->unique()->words(3, true);

    return Str::title($words);
}

/**
 * Un film de vivier au profil de titre voulu.
 *
 * @param  array<string, string>  $titles  locale de catalogue => titre ; `[]` = `en` et `fr`
 */
function choiceSetMovie(array $titles = [], ?string $original = null, ?string $latin = null): Movie
{
    $state = $original === null
        ? null
        : static fn (MovieFactory $factory): MovieFactory => $factory->state([
            'title_original' => $original,
            'title_original_latin' => $latin,
        ]);

    return PoolFixtures::movie(null, null, $state, $titles);
}

/** @return array<string, string> Profil complet : un titre dans chaque locale activée. */
function choiceSetBoth(): array
{
    return [Locale::English->value => choiceSetTitle(), Locale::French->value => choiceSetTitle()];
}

/**
 * Une partie Normal à graine fixe dans un salon neuf, sa manche composable à
 * `$at`, et `$peers` films au même profil que la cible.
 *
 * @param  array<string, string>|null  $targetTitles
 * @return array{game: Game, round: Round, target: Movie}
 */
function choiceSetScene(CarbonImmutable $at, ?array $targetTitles = null, int $peers = 4, int $seed = 0): array
{
    $game = ChoiceSetFixtures::game(
        Room::factory()->create(),
        ChoiceSetFixtures::settings(InputDifficulty::Normal),
        ChoiceSetFixtures::seeds()[$seed],
    );
    $titles = $targetTitles ?? choiceSetBoth();
    $target = choiceSetMovie($titles);

    for ($index = 0; $index < $peers; $index++) {
        choiceSetMovie(array_map(static fn (): string => choiceSetTitle(), $titles));
    }

    return ['game' => $game, 'round' => ChoiceSetFixtures::round($game, $target, $at), 'target' => $target];
}

/**
 * Les lignes de la manche, indexées par locale.
 *
 * @return array<string, RoundChoiceSet>
 */
function choiceSetRows(Round $round): array
{
    return RoundChoiceSet::query()
        ->where('round_id', $round->id)
        ->get()
        ->keyBy(static fn (RoundChoiceSet $set): string => $set->locale->value)
        ->all();
}

/**
 * Les quatre chaînes stockées d'une ligne, dans l'ordre des colonnes.
 *
 * @return list<string>
 */
function choiceSetStored(RoundChoiceSet $set): array
{
    return [$set->choice_1, $set->choice_2, $set->choice_3, $set->choice_4];
}

/**
 * Les films de la manche dans l'ordre de `choice_1..4` : la cible, puis les
 * leurres dans l'ordre de `decoy_movie_id_1..3`.
 *
 * @return list<Movie>
 */
function choiceSetMovies(Round $round): array
{
    $round->refresh();

    return array_map(
        static fn (?int $id): Movie => Movie::query()->with('titles')->findOrFail($id),
        [$round->movie_id, $round->decoy_movie_id_1, $round->decoy_movie_id_2, $round->decoy_movie_id_3],
    );
}

/**
 * L'ordre attendu d'un siège, recalculé sans le présentateur : la lettre du
 * § 10.8.
 *
 * @return list<string>
 */
function choiceSetExpectedOrder(Round $round, RoundPlayer $seat, RoundChoiceSet $set): array
{
    $game = Game::query()->findOrFail($round->game_id);
    $player = Player::query()->findOrFail($seat->player_id);
    $stored = choiceSetStored($set);

    return array_map(
        static fn (int $index): string => $stored[$index],
        SeededPrf::forGame($game)->permutation(
            DrawContext::qcmOrder($round->sequence_index, $player->public_id),
            ChoicesPayload::COUNT,
        ),
    );
}

it('compose une ligne par locale activée avec choice_1 égal au film cible', function () {
    ['game' => $game, 'round' => $round, 'target' => $target] = choiceSetScene($this->at);
    $expected = app(DecoyPicker::class)->pick($round, $game, $this->at);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

    $round->refresh();
    $rows = choiceSetRows($round);
    $resolver = app(DisplayTitleResolver::class);
    $movies = choiceSetMovies($round);

    expect([$round->decoy_movie_id_1, $round->decoy_movie_id_2, $round->decoy_movie_id_3])->toBe($expected?->movieIds)
        ->and($round->choices_use_original_title)->toBeFalse()
        ->and(array_keys($rows))->toEqualCanonicalizing(array_map(static fn (Locale $locale): string => $locale->value, Locale::cases()))
        ->and($movies[0]->id)->toBe($target->id);

    foreach (Locale::cases() as $locale) {
        $set = $rows[$locale->value];

        // `choice_1` est la cible ; `choice_2..4` les leurres, dans l'ordre de
        // `decoy_movie_id_1..3` ; profil complet : chaque ligne atteint sa
        // propre locale (rang 1).
        expect(choiceSetStored($set))->toBe(array_map(
            static fn (Movie $movie): string => Str::trim($resolver->resolve($movie, $locale)->text),
            $movies,
        ))
            ->and($set->choice_1)->toBe(Str::trim($resolver->resolve($target, $locale)->text))
            ->and($set->rendered_locale)->toBe($locale)
            ->and($set->composed_at->format('Y-m-d H:i:s.v'))->toBe($this->at->format('Y-m-d H:i:s.v'));
    }

    // Mode dégradé : la cible, seule de son profil, sort de `title_original`
    // dans toutes les locales, et `choice_1` reste la sienne.
    $lonely = ChoiceSetFixtures::game(Room::factory()->create(), ChoiceSetFixtures::settings(), ChoiceSetFixtures::seeds()[1]);
    $lonelyTarget = choiceSetMovie([Locale::French->value => choiceSetTitle()]);
    $lonelyRound = ChoiceSetFixtures::round($lonely, $lonelyTarget, $this->at);

    expect(ChoiceSetFixtures::compose($lonelyRound, $this->at))->toBeTrue();

    $lonelyRound->refresh();
    $lonelyMovies = choiceSetMovies($lonelyRound);

    expect($lonelyRound->choices_use_original_title)->toBeTrue();

    foreach (choiceSetRows($lonelyRound) as $set) {
        expect(choiceSetStored($set))->toBe(array_map(
            static fn (Movie $movie): string => Str::trim($resolver->original($movie)),
            $lonelyMovies,
        ))
            ->and($set->choice_1)->toBe($lonelyTarget->title_original)
            ->and($set->rendered_locale)->toBeNull();
    }
});

it('les quatre chaînes sont deux à deux distinctes dans chaque locale', function () {
    $target = [
        Locale::English->value => 'The Hollow Lantern Keeper',
        Locale::French->value => 'Le Gardien De La Lanterne Creuse',
    ];

    foreach (ChoiceSetFixtures::seeds() as $seedIndex => $seed) {
        $game = ChoiceSetFixtures::game(Room::factory()->create(), ChoiceSetFixtures::settings(), $seed);
        $round = ChoiceSetFixtures::round($game, choiceSetMovie([
            Locale::English->value => $target[Locale::English->value].' '.$seedIndex,
            Locale::French->value => $target[Locale::French->value].' '.$seedIndex,
        ]), $this->at);

        // Pièges : un titre anglais de même forme normalisée que la cible, un
        // titre français de même forme qu'un autre candidat.
        choiceSetMovie([
            Locale::English->value => 'the hollow lantern keeper! '.$seedIndex,
            Locale::French->value => choiceSetTitle(),
        ]);
        choiceSetMovie([Locale::English->value => choiceSetTitle(), Locale::French->value => 'Le Témoin Du Sel '.$seedIndex]);
        choiceSetMovie([Locale::English->value => choiceSetTitle(), Locale::French->value => 'le temoin du sel.. '.$seedIndex]);
        choiceSetMovie(choiceSetBoth());
        choiceSetMovie(choiceSetBoth());

        expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

        foreach (choiceSetRows($round) as $locale => $set) {
            $stored = choiceSetStored($set);
            $forms = array_map(AnswerKeyNormalizer::normalize(...), $stored);

            expect(array_unique($stored))->toHaveCount(ChoicesPayload::COUNT, "{$locale} : doublon strict")
                ->and(array_unique($forms))->toHaveCount(ChoicesPayload::COUNT, "{$locale} : doublon normalisé");
        }
    }
});

it('pose choices_locale pour les sièges open et text_exhausted', function () {
    ['round' => $round] = choiceSetScene($this->at);

    $open = ChoiceSetFixtures::seat($round, Locale::English);
    $openFrench = ChoiceSetFixtures::seat($round, Locale::French);
    $exhausted = ChoiceSetFixtures::seat($round, Locale::French, static fn (RoundPlayerFactory $factory): RoundPlayerFactory => $factory->textExhausted());
    // Déconnecté compris : il recevra les propositions à son retour.
    $away = ChoiceSetFixtures::seat($round, Locale::English);
    Player::query()->whereKey($away->player_id)->update(['connection_state' => PlayerConnectionState::Disconnected->value]);
    // Saisie close : aucune langue de composition.
    $locked = ChoiceSetFixtures::seat($round, Locale::English, static fn (RoundPlayerFactory $factory): RoundPlayerFactory => $factory->locked());
    Guess::factory()->forRound($round, Player::query()->findOrFail($locked->player_id))->create();

    // Le siège d'une autre manche n'est pas touché.
    ['round' => $other] = choiceSetScene($this->at, seed: 1);
    $elsewhere = ChoiceSetFixtures::seat($other, Locale::French);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

    $expected = [
        [$open, Locale::English],
        [$openFrench, Locale::French],
        [$exhausted, Locale::French],
        [$away, Locale::English],
    ];

    foreach ($expected as [$seat, $locale]) {
        $seat->refresh();

        expect($seat->choices_locale)->toBe($locale)
            ->and($seat->choices_composed_at?->format('Y-m-d H:i:s.v'))->toBe($this->at->format('Y-m-d H:i:s.v'));
    }

    // L'état de saisie n'est pas touché par la composition.
    expect($exhausted->refresh()->input_state)->toBe(RoundPlayerInputState::TextExhausted)
        ->and($locked->refresh()->choices_locale)->toBeNull()
        ->and($locked->choices_composed_at)->toBeNull()
        ->and($elsewhere->refresh()->choices_locale)->toBeNull()
        ->and($elsewhere->choices_composed_at)->toBeNull();
});

it('rejoue exactement les quatre mêmes chaînes après resynchronisation, changement de langue et second onglet', function () {
    ['round' => $round, 'target' => $target] = choiceSetScene($this->at);
    $seat = ChoiceSetFixtures::seat($round, Locale::English);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

    $first = ChoiceSetFixtures::present($seat)?->toArray();

    expect($first)->not->toBeNull()
        ->and($first['lang'] ?? null)->toBe(Locale::English->bcp47());

    // Resynchronisation : ligne relue, présentateur neuf.
    app()->forgetScopedInstances();
    expect(ChoiceSetFixtures::present($seat)?->toArray())->toBe($first);

    // Le catalogue change après la composition : titres corrigés, film cible
    // renommé. Rien n'est recomposé.
    MovieTitle::query()->where('movie_id', $target->id)->update(['title' => 'Un Titre Corrigé Après Coup']);
    Movie::query()->whereKey($target->id)->update(['title_original' => 'Corrected Afterwards']);

    expect(ChoiceSetFixtures::present($seat)?->toArray())->toBe($first);

    // Changement de langue en cours de manche : la langue de COMPOSITION est
    // rejouée, chaînes et `lang` compris.
    Player::query()->whereKey($seat->player_id)->update(['locale' => Locale::French->value]);

    expect(ChoiceSetFixtures::present($seat)?->toArray())->toBe($first)
        ->and($seat->refresh()->choices_locale)->toBe(Locale::English);

    // Second onglet : un nouveau jeton de siège, le même siège, le même
    // `public_id` — le même QCM, dans le même ordre.
    Player::query()->whereKey($seat->player_id)->update(['active_seat_token' => (string) Str::ulid()]);

    expect(ChoiceSetFixtures::present($seat)?->toArray())->toBe($first);

    // Une composition rappelée ne change rien.
    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue()
        ->and(ChoiceSetFixtures::present($seat)?->toArray())->toBe($first);
});

it('la permutation dérive de draw:qcm:{sequenceIndex}:{publicId} et diffère entre deux sièges', function () {
    // Une autre partie d'abord : la manche étudiée a un `round.id` distinct de
    // sa `sequence_index`, et ses sièges des `player.id` quelconques.
    choiceSetScene($this->at, seed: 1);
    ChoiceSetFixtures::seat(Round::query()->firstOrFail());
    ['round' => $round] = choiceSetScene($this->at);

    expect($round->id)->not->toBe($round->sequence_index);

    $seats = [
        ChoiceSetFixtures::seat($round, Locale::English, publicId: 'A1B2C3D4E5F6'),
        ChoiceSetFixtures::seat($round, Locale::English, publicId: 'ZYXWVTSRQPNM'),
        ChoiceSetFixtures::seat($round, Locale::English, publicId: '0123456789AB'),
        ChoiceSetFixtures::seat($round, Locale::English, publicId: 'KMNPQRSTVWXY'),
    ];

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

    $set = choiceSetRows($round)[Locale::English->value];
    $orders = [];
    $targetPositions = [];

    foreach ($seats as $seat) {
        $player = Player::query()->findOrFail($seat->player_id);

        expect(DrawContext::qcmOrder($round->sequence_index, $player->public_id)->value)
            ->toBe("draw:qcm:{$round->sequence_index}:{$player->public_id}");

        $choices = ChoiceSetFixtures::present($seat)?->choices;

        // L'ordre reçu est la permutation de la graine de la partie sur ce
        // contexte, et rien d'autre : ni `round_id`, ni `player_id`.
        expect($choices)->toBe(choiceSetExpectedOrder($round, $seat, $set))
            ->and($choices)->toEqualCanonicalizing(choiceSetStored($set));

        $orders[] = $choices;
        $targetPositions[] = array_search($set->choice_1, $choices ?? [], true);
    }

    // Deux sièges, deux ordres ; la bonne réponse n'a pas de place fixe.
    expect($orders[0])->not->toBe($orders[1])
        ->and(array_unique($targetPositions, SORT_REGULAR))->not->toHaveCount(1);

    // Recalculée à chaque envoi, jamais stockée : identique d'un envoi à
    // l'autre, et aucune colonne ne la porte.
    expect(ChoiceSetFixtures::present($seats[0])?->choices)->toBe($orders[0])
        ->and(array_keys(RoundPlayer::query()->findOrFail($seats[0]->id)->getAttributes()))
        ->not->toContain('choices_order');
});

it('la charge ciblée ne contient que quatre chaînes, le drapeau et lang', function () {
    ['round' => $round] = choiceSetScene($this->at);
    $seat = ChoiceSetFixtures::seat($round, Locale::French);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

    $payload = ChoiceSetFixtures::present($seat)?->toArray() ?? throw new LogicException;
    $set = choiceSetRows($round)[Locale::French->value];

    expect(array_keys($payload))->toBe(['choices', 'useOriginalTitle', 'lang'])
        ->and($payload['choices'])->toBeList()->toHaveCount(ChoicesPayload::COUNT)
        ->and($payload['choices'])->toEqualCanonicalizing(choiceSetStored($set))
        ->and($payload['useOriginalTitle'])->toBeFalse()
        ->and($payload['lang'])->toBe(Locale::French->bcp47());

    foreach ($payload['choices'] as $choice) {
        expect($choice)->toBeString();
    }

    // Aucun identifiant, aucun index ni drapeau de la bonne réponse : pas un
    // seul entier dans la charge, et aucune clé hors des trois du contrat —
    // `choices` est une liste nue, sans métadonnée par proposition.
    array_walk_recursive($payload, static function (mixed $value): void {
        expect(is_int($value))->toBeFalse('un entier dans la charge du QCM');
    });

    expect(array_keys($payload['choices']))->toBe(range(0, ChoicesPayload::COUNT - 1));

    // Mode dégradé : même forme, drapeau levé, `lang` nul.
    $lonely = ChoiceSetFixtures::game(Room::factory()->create(), ChoiceSetFixtures::settings(), ChoiceSetFixtures::seeds()[1]);
    $lonelyRound = ChoiceSetFixtures::round($lonely, choiceSetMovie([Locale::French->value => choiceSetTitle()]), $this->at);
    $lonelySeat = ChoiceSetFixtures::seat($lonelyRound, Locale::English);

    expect(ChoiceSetFixtures::compose($lonelyRound, $this->at))->toBeTrue();

    $degraded = ChoiceSetFixtures::present($lonelySeat)?->toArray() ?? throw new LogicException;

    expect(array_keys($degraded))->toBe(['choices', 'useOriginalTitle', 'lang'])
        ->and($degraded['useOriginalTitle'])->toBeTrue()
        ->and($degraded['lang'])->toBeNull();
});

it('lang égale la locale effective atteinte et reste celle de la composition', function () {
    // 1. Profil complet : chaque ligne atteint sa locale ; le siège anglais
    // garde `en` après être passé au français.
    ['round' => $round] = choiceSetScene($this->at);
    $english = ChoiceSetFixtures::seat($round, Locale::English);
    $french = ChoiceSetFixtures::seat($round, Locale::French);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue()
        ->and(ChoiceSetFixtures::present($english)?->lang)->toBe(Locale::English)
        ->and(ChoiceSetFixtures::present($french)?->lang)->toBe(Locale::French);

    Player::query()->whereKey($english->player_id)->update(['locale' => Locale::French->value]);

    expect(ChoiceSetFixtures::present($english)?->toArray()['lang'])->toBe(Locale::English->bcp47());

    // 2. Profil « français seul » : la ligne anglaise atteint le français au
    // rang 2 — ses chaînes sont françaises, et `lang` le dit.
    ['round' => $frenchOnly] = choiceSetScene($this->at, [Locale::French->value => choiceSetTitle()], seed: 1);
    $englishSeat = ChoiceSetFixtures::seat($frenchOnly, Locale::English);

    expect(ChoiceSetFixtures::compose($frenchOnly, $this->at))->toBeTrue();

    $rows = choiceSetRows($frenchOnly);

    expect($frenchOnly->refresh()->choices_use_original_title)->toBeFalse()
        ->and($rows[Locale::English->value]->rendered_locale)->toBe(Locale::French)
        ->and($rows[Locale::French->value]->rendered_locale)->toBe(Locale::French)
        ->and(choiceSetStored($rows[Locale::English->value]))->toBe(choiceSetStored($rows[Locale::French->value]))
        ->and(ChoiceSetFixtures::present($englishSeat)?->toArray()['lang'])->toBe(Locale::French->bcp47());

    // 3. Aucun titre dans une locale activée (masque nul) : les quatre
    // chaînes sortent du titre original, au rang 3 — `lang` nul, sans mode
    // dégradé.
    ['round' => $untitled] = choiceSetScene($this->at, ['es' => choiceSetTitle()], seed: 2);
    $untitledSeat = ChoiceSetFixtures::seat($untitled, Locale::French);

    expect(ChoiceSetFixtures::compose($untitled, $this->at))->toBeTrue();

    foreach (choiceSetRows($untitled) as $set) {
        expect($set->rendered_locale)->toBeNull();
    }

    expect($untitled->refresh()->choices_use_original_title)->toBeFalse()
        ->and(ChoiceSetFixtures::present($untitledSeat)?->toArray()['lang'])->toBeNull();

    // 4. Mode dégradé : `lang` nul, drapeau levé.
    ['round' => $degraded] = choiceSetScene($this->at, [Locale::English->value => choiceSetTitle()], peers: 0, seed: 3);
    $degradedSeat = ChoiceSetFixtures::seat($degraded, Locale::English);

    expect(ChoiceSetFixtures::compose($degraded, $this->at))->toBeTrue()
        ->and($degraded->refresh()->choices_use_original_title)->toBeTrue()
        ->and(ChoiceSetFixtures::present($degradedSeat)?->toArray()['lang'])->toBeNull();
});

it('composer deux fois une manche ne crée aucune ligne de plus', function () {
    ['round' => $round] = choiceSetScene($this->at);
    $seat = ChoiceSetFixtures::seat($round, Locale::English);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

    $snapshot = static fn (): array => [
        'round' => Round::query()->findOrFail($round->id)->only([
            'decoy_movie_id_1', 'decoy_movie_id_2', 'decoy_movie_id_3', 'choices_use_original_title', 'updated_at',
        ]),
        'sets' => RoundChoiceSet::query()->where('round_id', $round->id)->orderBy('id')->get()
            ->map(static fn (RoundChoiceSet $set): array => $set->makeVisible(['choice_1', 'choice_2', 'choice_3', 'choice_4', 'rendered_locale'])->toArray())
            ->all(),
        'seat' => RoundPlayer::query()->findOrFail($seat->id)->only(['choices_locale', 'choices_composed_at', 'updated_at']),
    ];
    $before = $snapshot();

    // Plus tard, le catalogue a changé et un siège est arrivé : rien n'est
    // recomposé, aucune ligne n'est écrite, le nouveau siège n'est pas touché.
    $this->travel(40)->seconds();
    choiceSetMovie(choiceSetBoth());
    choiceSetMovie(choiceSetBoth());
    $late = ChoiceSetFixtures::seat($round, Locale::French);

    DB::enableQueryLog();
    $again = ChoiceSetFixtures::compose($round, $this->at);
    $statements = array_map(static fn (array $query): string => Str::lower(ltrim((string) $query['query'])), DB::getQueryLog());
    DB::disableQueryLog();

    expect($again)->toBeTrue()
        ->and($snapshot())->toEqual($before)
        ->and(RoundChoiceSet::query()->where('round_id', $round->id)->count())->toBe(count(Locale::cases()))
        ->and($late->refresh()->choices_locale)->toBeNull();

    foreach ($statements as $statement) {
        expect($statement)->not->toStartWith('insert')
            ->and($statement)->not->toStartWith('update')
            ->and($statement)->not->toStartWith('delete');
    }
});

it('écrit des chaînes invariantes par Str::trim, y compris pour des titres qui finissent par U+00A0 ou commencent par U+200B', function () {
    $nbsp = "\u{00A0}";
    $zwsp = "\u{200B}";

    // Le témoin : `trim()` de PHP garde ces caractères, `Str::trim()` — celle
    // du middleware global `TrimStrings` — les retire.
    expect(trim('Harbor Of Glass'.$nbsp))->not->toBe('Harbor Of Glass')
        ->and(Str::trim('Harbor Of Glass'.$nbsp))->toBe('Harbor Of Glass')
        ->and(Str::trim($zwsp.'Le Port De Verre'))->toBe('Le Port De Verre');

    // 1. Mode normal : titres bordés d'invisibles, cible et leurres.
    $game = ChoiceSetFixtures::game(Room::factory()->create(), ChoiceSetFixtures::settings(), ChoiceSetFixtures::seeds()[0]);
    $round = ChoiceSetFixtures::round($game, choiceSetMovie([
        Locale::English->value => 'Harbor Of Glass'.$nbsp,
        Locale::French->value => $zwsp.'Le Port De Verre',
    ]), $this->at);
    choiceSetMovie([Locale::English->value => "\u{FEFF}Salt Witness ", Locale::French->value => 'Le Témoin'."\u{200E}"]);
    choiceSetMovie([Locale::English->value => $zwsp.'Lantern Road', Locale::French->value => 'La Route Des Lanternes'.$nbsp]);
    choiceSetMovie(choiceSetBoth());
    $seat = ChoiceSetFixtures::seat($round, Locale::English);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

    $rows = choiceSetRows($round);

    expect($rows[Locale::English->value]->choice_1)->toBe('Harbor Of Glass')
        ->and($rows[Locale::French->value]->choice_1)->toBe('Le Port De Verre');

    foreach ($rows as $set) {
        foreach (choiceSetStored($set) as $choice) {
            expect(Str::trim($choice))->toBe($choice);
        }
    }

    // La proposition reçue est celle que `TrimStrings` rendra au clic.
    foreach (ChoiceSetFixtures::present($seat)->choices ?? [] as $choice) {
        expect(Str::trim($choice))->toBe($choice);
    }

    // 2. Mode dégradé : le titre original est nettoyé de même.
    $lonely = ChoiceSetFixtures::game(Room::factory()->create(), ChoiceSetFixtures::settings(), ChoiceSetFixtures::seeds()[1]);
    $lonelyRound = ChoiceSetFixtures::round(
        $lonely,
        choiceSetMovie([Locale::French->value => choiceSetTitle()], $zwsp.'Umi No Oto'.$nbsp),
        $this->at,
    );

    expect(ChoiceSetFixtures::compose($lonelyRound, $this->at))->toBeTrue()
        ->and($lonelyRound->refresh()->choices_use_original_title)->toBeTrue();

    foreach (choiceSetRows($lonelyRound) as $set) {
        expect($set->choice_1)->toBe('Umi No Oto');

        foreach (choiceSetStored($set) as $choice) {
            expect(Str::trim($choice))->toBe($choice);
        }
    }
});

it('le cas défensif de ChoicesPresenter pose choices_locale et choices_composed_at une seule fois quand la manche est composée', function () {
    ['round' => $round] = choiceSetScene($this->at);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeTrue();

    // Un siège sans langue de composition, alors que la manche est composée.
    $this->travel(30)->seconds();
    $late = ChoiceSetFixtures::seat($round, Locale::French);

    expect($late->choices_locale)->toBeNull();

    DB::enableQueryLog();
    $payload = ChoiceSetFixtures::present($late);
    $updates = array_values(array_filter(
        array_map(static fn (array $query): string => Str::lower((string) $query['query']), DB::getQueryLog()),
        static fn (string $statement): bool => str_starts_with(ltrim($statement), 'update'),
    ));
    DB::disableQueryLog();

    $set = choiceSetRows($round)[Locale::French->value];
    $late->refresh();

    // Une seule écriture, conditionnelle ; la langue du siège, l'instant de
    // composition de la ligne — jamais l'heure de la requête.
    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toContain('"choices_locale" is null')
        ->and($late->choices_locale)->toBe(Locale::French)
        ->and($late->choices_composed_at?->format('Y-m-d H:i:s.v'))->toBe($set->composed_at->format('Y-m-d H:i:s.v'))
        ->and($payload?->lang)->toBe(Locale::French)
        ->and($payload?->choices)->toBe(choiceSetExpectedOrder($round, $late, $set));

    $frozen = $late->only(['choices_locale', 'choices_composed_at', 'updated_at']);

    // La langue du joueur change, et l'instance passée est périmée : la
    // langue figée ne bouge plus.
    Player::query()->whereKey($late->player_id)->update(['locale' => Locale::English->value]);
    $stale = RoundPlayer::query()->findOrFail($late->id)->forceFill(['choices_locale' => null, 'choices_composed_at' => null]);

    expect(app(ChoicesPresenter::class)->forSeat($stale)?->toArray())->toBe($payload?->toArray())
        ->and(RoundPlayer::query()->findOrFail($late->id)->only(['choices_locale', 'choices_composed_at', 'updated_at']))->toEqual($frozen);

    // Saisie close : rien n'est posé, rien n'est rendu.
    $locked = ChoiceSetFixtures::seat($round, Locale::English, static fn (RoundPlayerFactory $factory): RoundPlayerFactory => $factory->locked());
    Guess::factory()->forRound($round, Player::query()->findOrFail($locked->player_id))->create();

    expect(ChoiceSetFixtures::present($locked))->toBeNull()
        ->and($locked->refresh()->choices_locale)->toBeNull();

    // Manche non composée : rien n'est posé, rien n'est rendu.
    ['round' => $pending] = choiceSetScene($this->at, seed: 1);
    $waiting = ChoiceSetFixtures::seat($pending, Locale::English);

    expect(ChoiceSetFixtures::present($waiting))->toBeNull()
        ->and($waiting->refresh()->choices_locale)->toBeNull()
        ->and($waiting->choices_composed_at)->toBeNull();
});

it("refuse une composition en Expert ou hors de l'instant théorique d'ouverture du QCM", function () {
    // Expert : aucun QCM, jamais.
    $expert = ChoiceSetFixtures::game(
        Room::factory()->create(),
        ChoiceSetFixtures::settings(InputDifficulty::Expert),
        ChoiceSetFixtures::seeds()[0],
    );
    $expertRound = ChoiceSetFixtures::round($expert, choiceSetMovie(), $this->at);

    expect(fn () => ChoiceSetFixtures::compose($expertRound, $this->at))->toThrow(LogicException::class);

    // Normal : `$at` est `T_N` à la milliseconde, ni l'heure d'exécution ni
    // `T₁`.
    ['round' => $round, 'game' => $game] = choiceSetScene($this->at);
    $startedAt = CarbonImmutable::instance($round->refresh()->started_at ?? throw new LogicException);

    expect(fn () => ChoiceSetFixtures::compose($round, $this->at->addMillisecond()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ChoiceSetFixtures::compose($round, $startedAt))->toThrow(InvalidArgumentException::class)
        ->and(RoundChoiceSet::query()->where('round_id', $round->id)->exists())->toBeFalse()
        ->and($round->refresh()->decoy_movie_id_1)->toBeNull();

    // La milliseconde se lit TRONQUÉE, comme la stocke `timestamp(3)` : un
    // `$at` à 600 µs de plus désigne la même milliseconde (l'arrondi de
    // `getTimestampMs()` la ferait passer à la suivante) et compose.
    $withMicros = $this->at->addMicroseconds(600);

    expect($withMicros->getTimestampMs())->not->toBe($this->at->getTimestampMs())
        ->and(ChoiceSetFixtures::compose($round, $withMicros))->toBeTrue()
        ->and($round->refresh()->decoy_movie_id_1)->not->toBeNull();

    foreach (choiceSetRows($round) as $set) {
        expect($set->composed_at->format('Y-m-d H:i:s.v'))->toBe($this->at->format('Y-m-d H:i:s.v'));
    }

    // Palier du QCM non matérialisé : refus, rien d'écrit.
    $bare = PoolFixtures::round($game, choiceSetMovie(), $startedAt);

    expect(fn () => ChoiceSetFixtures::compose($bare, $this->at))->toThrow(LogicException::class)
        ->and(RoundChoiceSet::query()->where('round_id', $bare->id)->exists())->toBeFalse();

    // Facile : le QCM s'ouvre à `T₁`, instant de démarrage de la manche.
    $easy = ChoiceSetFixtures::game(
        Room::factory()->create(),
        ChoiceSetFixtures::settings(InputDifficulty::Easy),
        ChoiceSetFixtures::seeds()[1],
    );
    $easyRound = ChoiceSetFixtures::round($easy, choiceSetMovie(), $this->at);

    for ($index = 0; $index < 3; $index++) {
        choiceSetMovie();
    }

    expect($easyRound->refresh()->started_at?->format('Y-m-d H:i:s.v'))->toBe($this->at->format('Y-m-d H:i:s.v'))
        ->and(ChoiceSetFixtures::compose($easyRound, $this->at))->toBeTrue();
});

/**
 * Une partie Normal jouée PAR LES ROUTES (lot L70-9) : sièges tenus par
 * leurs jetons, chacun dans la langue de son jeton, manche 1 portant
 * `$target`, QCM composé par l'action réelle à `T_N`.
 *
 * @param  list<PlayerToken>  $tokens
 * @return array{game: Game, round: Round, seats: list<Player>, at: CarbonImmutable}
 */
function choiceSetPlayedRound(array $tokens, Movie $target): array
{
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();
    Date::setTestNow(CarbonImmutable::parse('2026-09-27 21:10:00.125'));

    [$game, $round, $seats] = SubmissionFixtures::openedRound(
        $tokens,
        SubmissionFixtures::settings(InputDifficulty::Normal),
        $target,
    );
    SubmissionFixtures::decoyCandidates();

    // La langue de composition d'un siège est celle de son joueur.
    foreach ($seats as $index => $seat) {
        Player::query()->whereKey($seat->id)->update(['locale' => ($tokens[$index]->locale ?? Locale::English)->value]);
    }

    $at = SubmissionFixtures::openChoices($round);

    return ['game' => $game, 'round' => $round, 'seats' => $seats, 'at' => $at];
}

/**
 * Les instructions d'un relevé qui lisent ou écrivent `answer_key`.
 *
 * @param  list<QueryExecuted>  $queries
 * @return list<string>
 */
function choiceSetAnswerKeyQueries(array $queries): array
{
    return array_values(array_filter(
        array_map(static fn (QueryExecuted $query): string => $query->sql, $queries),
        static fn (string $sql): bool => SubmissionFixtures::touches($sql, 'answer_key'),
    ));
}

it('un clic est jugé par égalité stricte contre choice_1 sans lire answer_key', function () {
    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::French)];
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    ['game' => $game, 'round' => $round, 'seats' => [$first, $second], 'at' => $tN] = choiceSetPlayedRound($tokens, $target);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $set = SubmissionFixtures::choiceSet($round, $first);
    $invalid = trans('game.choices.invalid', [], Locale::French->value);

    expect($set->locale)->toBe(Locale::French)
        ->and($set->choice_1)->toBe('Les Feux du port')
        ->and($invalid)->not->toBe('game.choices.invalid');

    // 1. Égalité STRICTE : une chaîne de même forme normalisée n'est pas la
    // proposition — c'est une requête fabriquée, jamais un clic juste.
    $shouted = Str::upper($set->choice_1);

    expect($shouted)->not->toBe($set->choice_1)
        ->and(AnswerKeyNormalizer::normalize($shouted))->toBe(AnswerKeyNormalizer::normalize($set->choice_1));

    $queries = SubmissionFixtures::queries(fn () => SubmissionFixtures::click($this, $first, $tokens[0], $shouted, $tN->addMilliseconds($cadence))
        ->assertUnprocessable()
        ->assertJsonPath('errors.choice', [$invalid]));

    // 2. Le titre de la cible est corrigé entre la composition et le clic :
    // sa clé normalisée disparaît de `answer_key`, les quatre chaînes figées,
    // elles, ne bougent pas.
    MovieTitle::query()
        ->where('movie_id', $target->id)
        ->where('locale', Locale::French->value)
        ->update(['title' => 'Les Lumières du havre']);
    (new AnswerKeyProjector)->project($target->refresh());

    expect(AnswerKey::query()->where('movie_id', $target->id)->where('normalized', AnswerKeyNormalizer::normalize($set->choice_1))->exists())->toBeFalse()
        ->and(SubmissionFixtures::correctChoice($round, $first))->toBe($set->choice_1);

    // 3. La proposition reçue verrouille, sans aucune lecture d'`answer_key` :
    // l'instantané est celui d'un clic, jamais celui d'une clé.
    $queries = [...$queries, ...SubmissionFixtures::queries(fn () => SubmissionFixtures::click($this, $first, $tokens[0], $set->choice_1, $tN->addMilliseconds(2 * $cadence))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1]))];

    $guess = SubmissionFixtures::guess($round, $first);

    expect($guess->source)->toBe(GuessSource::Choice)
        ->and($guess->match_kind)->toBe(GuessMatchKind::Choice)
        ->and($guess->answer_key_id)->toBeNull()
        ->and($guess->answer_key_normalized)->toBe(AnswerKeyNormalizer::normalize($set->choice_1))
        ->and($guess->submitted_normalized)->toBe(AnswerKeyNormalizer::normalize($set->choice_1))
        ->and($guess->edit_distance)->toBe(0)
        ->and($guess->prefix_was_ambiguous)->toBeFalse();

    // 4. Le titre corrigé n'est pas une proposition ; la chaîne composée le
    // reste pour le siège suivant.
    $queries = [...$queries, ...SubmissionFixtures::queries(fn () => SubmissionFixtures::click($this, $second, $tokens[1], 'Les Lumières du havre', $tN->addMilliseconds($cadence))
        ->assertUnprocessable()
        ->assertJsonPath('errors.choice', [$invalid]))];

    $queries = [...$queries, ...SubmissionFixtures::queries(fn () => SubmissionFixtures::click($this, $second, $tokens[1], $set->choice_1, $tN->addMilliseconds(2 * $cadence))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 2]))];

    expect(choiceSetAnswerKeyQueries($queries))->toBe([]);
});

it('une chaîne hors des quatre propositions est refusée en 422 sans fermer la saisie', function () {
    Event::fake([InputClosed::class]);

    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::English)];
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    ['game' => $game, 'round' => $round, 'seats' => [$seat, $late], 'at' => $tN] = choiceSetPlayedRound($tokens, $target);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $own = choiceSetStored(SubmissionFixtures::choiceSet($round, $seat));
    $english = choiceSetRows($round)[Locale::English->value];
    $invalid = trans('game.choices.invalid', [], Locale::French->value);

    // Une chaîne fabriquée ; la bonne proposition d'une AUTRE langue, qui
    // n'est pas l'une des quatre de ce siège ; un index, qu'un clic n'envoie
    // jamais.
    $refused = [SubmissionFixtures::WRONG, $english->choice_1, '1'];
    $queries = [];

    foreach ($refused as $step => $choice) {
        expect(in_array($choice, $own, true))->toBeFalse();

        $queries = [...$queries, ...SubmissionFixtures::queries(fn () => SubmissionFixtures::click($this, $seat, $tokens[0], $choice, $tN->addMilliseconds(($step + 1) * $cadence))
            ->assertUnprocessable()
            ->assertJsonPath('errors.choice', [$invalid]))];
    }

    // Rien n'est écrit ni compté, la saisie reste ouverte.
    $participation = SubmissionFixtures::participation($round, $seat);

    expect(SubmissionFixtures::writes($queries))->toBe([])
        ->and($participation->input_state)->toBe(RoundPlayerInputState::Open)
        ->and($participation->input_closed_at)->toBeNull()
        ->and($participation->wrong_attempts)->toBe(0)
        ->and($participation->choices_locale)->toBe(Locale::French)
        ->and(Guess::query()->count())->toBe(0);

    Event::assertNotDispatched(InputClosed::class);

    // Un siège sans langue de composition n'a aucune ligne à lire : même la
    // bonne proposition de sa langue est une chaîne inconnue.
    RoundPlayer::query()->whereKey(SubmissionFixtures::participation($round, $late)->id)->update(['choices_locale' => null, 'choices_composed_at' => null]);

    SubmissionFixtures::click($this, $late, $tokens[1], $english->choice_1, $tN->addMilliseconds($cadence))
        ->assertUnprocessable()
        ->assertJsonPath('errors.choice', [trans('game.choices.invalid', [], Locale::English->value)]);

    expect(SubmissionFixtures::participation($round, $late)->input_state)->toBe(RoundPlayerInputState::Open);

    // La saisie est restée ouverte : le texte libre est encore jugé et
    // compté, puis le clic, une fois, verrouille.
    $next = $tN->addMilliseconds((count($refused) + 1) * $cadence);

    SubmissionFixtures::submit($this, $seat, $tokens[0], SubmissionFixtures::WRONG, $next)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody($game->settings_snapshot->attemptsPerRound - 1));

    SubmissionFixtures::click($this, $seat, $tokens[0], SubmissionFixtures::correctChoice($round, $seat), $next->addMilliseconds($cadence))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1]);
});

it('une proposition dont le titre source porte une espace insécable ou un caractère invisible en bord reste cliquable', function () {
    $nbsp = "\u{00A0}";
    $zwsp = "\u{200B}";

    // Le témoin : `trim()` de PHP garde ces caractères ; `TrimStrings`, qui
    // nettoie le corps du clic par `Str::trim()`, les retire.
    expect(trim('Harbor Of Glass'.$nbsp))->not->toBe('Harbor Of Glass')
        ->and(trim($zwsp.'Le Port De Verre'))->not->toBe('Le Port De Verre');

    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::English)];
    $target = SubmissionFixtures::movie('Harbor Of Glass'.$nbsp, $zwsp.'Le Port De Verre');
    ['game' => $game, 'round' => $round, 'seats' => [$french, $english], 'at' => $tN] = choiceSetPlayedRound($tokens, $target);
    $cadence = SubmissionFixtures::cadenceMs($game);

    // La proposition reçue par le siège français, telle que le présentateur
    // la rend, est la cible nettoyée ; le client la renvoie telle quelle.
    $received = app(ChoicesPresenter::class)->forSeat(SubmissionFixtures::participation($round, $french))?->choices ?? [];

    expect($received)->toContain('Le Port De Verre')
        ->and(SubmissionFixtures::correctChoice($round, $french))->toBe('Le Port De Verre');

    SubmissionFixtures::click($this, $french, $tokens[0], 'Le Port De Verre', $tN->addMilliseconds($cadence))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1]);

    // Le siège anglais renvoie le titre SOURCE, bordé de son espace
    // insécable : `TrimStrings` le rend égal à la chaîne composée.
    expect(SubmissionFixtures::correctChoice($round, $english))->toBe('Harbor Of Glass');

    SubmissionFixtures::click($this, $english, $tokens[1], 'Harbor Of Glass'.$nbsp, $tN->addMilliseconds($cadence))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 2]);

    expect(Guess::query()->where('round_id', $round->id)->count())->toBe(2);
});

it("en Normal, après une composition terminale, SeatInputView::forSeat d'un siège anciennement text_exhausted rend attempts_exhausted sans propositions", function () {
    // La cible est seule au catalogue : aucun leurre, à aucun rang.
    ['game' => $game, 'round' => $round] = choiceSetScene($this->at, peers: 0);
    $waiting = ChoiceSetFixtures::seat($round, Locale::French, static fn (RoundPlayerFactory $factory): RoundPlayerFactory => $factory->textExhausted());
    $open = ChoiceSetFixtures::seat($round, Locale::English);

    expect($game->input_difficulty)->toBe(InputDifficulty::Normal);

    // Avant la composition : texte épuisé, QCM attendu, aucune proposition.
    expect(SeatInputView::forSeat($waiting)->toArray())->toBe([
        'inputState' => RoundPlayerInputState::TextExhausted->value,
        'attemptsLeft' => 0,
        'choices' => null,
        'locked' => null,
    ]);

    expect(ChoiceSetFixtures::compose($round, $this->at))->toBeFalse();

    // L'instance est périmée — `text_exhausted` en mémoire — : la vue relit
    // la participation, close par la composition terminale, et ne promet
    // plus de propositions.
    expect($waiting->input_state)->toBe(RoundPlayerInputState::TextExhausted)
        ->and(SeatInputView::forSeat($waiting)->toArray())->toBe([
            'inputState' => RoundPlayerInputState::AttemptsExhausted->value,
            'attemptsLeft' => 0,
            'choices' => null,
            'locked' => null,
        ]);

    // Témoin : un siège ouvert garde le texte libre seul, ses tentatives, et
    // aucune proposition.
    expect(SeatInputView::forSeat($open)->toArray())->toBe([
        'inputState' => RoundPlayerInputState::Open->value,
        'attemptsLeft' => $game->settings_snapshot->attemptsPerRound - $open->wrong_attempts,
        'choices' => null,
        'locked' => null,
    ]);
});

it('un clic faux et une bonne réponse texte entrelacés se tranchent sous verrou, sans jamais locked et qcm_wrong', function () {
    // L'entrelacement des tests `locks-timing` (LockTransactionTest), rejoué
    // sur une seule connexion : l'écriture rivale est validée À UNE LECTURE
    // DONNÉE de la soumission, après sa lecture de la saisie (S4) et avant sa
    // transaction d'écriture. C'est donc la revérification sous verrou qui
    // tranche, jamais la lecture de S4.
    Event::fake([AnswerAccepted::class, InputClosed::class]);

    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::French)];
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    ['game' => $game, 'round' => $round, 'seats' => [$typedFirst, $clickedFirst], 'at' => $tN] = choiceSetPlayedRound($tokens, $target);
    $at = $tN->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    /**
     * Exécute `$rival` à la première lecture de `$table` par la soumission.
     *
     * @param  Closure(): void  $rival
     */
    $interleave = static function (string $table, Closure $rival): Closure {
        $done = false;

        DB::listen(static function (QueryExecuted $query) use (&$done, $table, $rival): void {
            if ($done || ! SubmissionFixtures::touches($query->sql, $table)) {
                return;
            }

            $done = true;
            $rival();
        });

        return static function () use (&$done): bool {
            return $done;
        };
    };

    // 1. Le clic faux lit une saisie `open`, puis la bonne réponse texte du
    // même siège se verrouille pendant sa lecture des propositions (S5') :
    // son instruction conditionnelle ne touche rien, sa relecture dit
    // `locked`, et aucune clôture n'est annoncée.
    $wrong = SubmissionFixtures::wrongChoice($round, $typedFirst);
    $fired = $interleave('round_choice_set', static function () use ($typedFirst, $game, $at): void {
        expect(app(SubmitTextAnswer::class)->handle($typedFirst, $game, 1, 'Harbour Lights', $at)->toArray())
            ->toMatchArray(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1]);
    });

    expect($fired())->toBeFalse();

    SubmissionFixtures::click($this, $typedFirst, $tokens[0], $wrong, $at->addMillisecond())
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::Locked));

    expect($fired())->toBeTrue()
        ->and(SubmissionFixtures::participation($round, $typedFirst)->input_state)->toBe(RoundPlayerInputState::Locked)
        ->and(SubmissionFixtures::participation($round, $typedFirst)->input_closed_at?->equalTo($at))->toBeTrue();

    Event::assertNotDispatched(InputClosed::class);

    // 2. La bonne réponse texte lit une saisie `open`, puis le clic faux du
    // même siège est validé pendant sa lecture des clés (S6) : la
    // transaction de verrouillage relit `qcm_wrong` sous verrou et n'écrit
    // rien.
    $wrong = SubmissionFixtures::wrongChoice($round, $clickedFirst);
    $fired = $interleave('answer_key', static function () use ($clickedFirst, $game, $at, $wrong): void {
        expect(app(SubmitChoice::class)->handle($clickedFirst, $game, 1, $wrong, $at)->toArray())
            ->toBe(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::QcmWrong));
    });

    expect($fired())->toBeFalse();

    SubmissionFixtures::submit($this, $clickedFirst, $tokens[1], 'Harbour Lights', $at->addMillisecond())
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::QcmWrong));

    expect($fired())->toBeTrue()
        ->and(SubmissionFixtures::participation($round, $clickedFirst)->input_state)->toBe(RoundPlayerInputState::QcmWrong)
        ->and(Guess::query()->where('round_id', $round->id)->pluck('player_id')->all())->toBe([$typedFirst->id])
        ->and(Round::query()->whereKey($round->id)->value('found_count'))->toBe(1);

    Event::assertDispatchedTimes(AnswerAccepted::class, 1);
    Event::assertDispatchedTimes(InputClosed::class, 1);
    Event::assertDispatched(InputClosed::class, static fn (InputClosed $event): bool => $event->playerId === $clickedFirst->id
        && $event->state === RoundPlayerInputState::QcmWrong);
});

it("SeatInputView::forSeat d'un siège verrouillé entre ses lectures rend l'état d'avant ou celui d'après, jamais une rupture", function () {
    // La resynchronisation (60 § 12.2) lit la vue hors de toute transaction,
    // et `LockGuess` valide `guess` et `locked` ensemble : le verrouillage du
    // siège est validé À UNE LECTURE DONNÉE de la vue, une table par siège.
    // La vue ne lève jamais : elle rend la saisie d'avant ou celle d'après, et
    // celle d'après dès que la ligne `guess` a été lue vide avant la
    // participation.
    Event::fake([AnswerAccepted::class, InputClosed::class]);

    $tables = ['guess', 'round_player', 'round', 'game'];
    $tokens = array_map(static fn (): PlayerToken => PlayerToken::mint(Locale::French), $tables);
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    ['game' => $game, 'round' => $round, 'seats' => $seats, 'at' => $tN] = choiceSetPlayedRound($tokens, $target);
    $at = $tN->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    /**
     * Exécute `$rival` à la première lecture de `$table` qui suit, une fois ;
     * chaque appel a son propre drapeau.
     *
     * @param  Closure(): void  $rival
     */
    $interleave = static function (string $table, Closure $rival): Closure {
        $done = false;

        DB::listen(static function (QueryExecuted $query) use (&$done, $table, $rival): void {
            if ($done || ! SubmissionFixtures::touches($query->sql, $table)) {
                return;
            }

            $done = true;
            $rival();
        });

        return static function () use (&$done): bool {
            return $done;
        };
    };

    $views = [];

    foreach ($tables as $index => $table) {
        $seat = $seats[$index];
        $participation = SubmissionFixtures::participation($round, $seat);
        $before = SeatInputView::forSeat($participation)->toArray();

        expect($before['inputState'])->toBe(RoundPlayerInputState::Open->value)
            ->and($before['choices'])->not->toBeNull()
            ->and($before['locked'])->toBeNull();

        // La bonne réponse texte du siège est validée à la première lecture
        // de `$table` par la vue, après que cette lecture a rendu ses lignes.
        $fired = $interleave($table, static function () use ($seat, $game, $at, $index): void {
            expect(app(SubmitTextAnswer::class)->handle($seat, $game, 1, 'Harbour Lights', $at)->toArray())
                ->toMatchArray(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => $index + 1]);
        });

        $view = SeatInputView::forSeat($participation)->toArray();

        expect($fired())->toBeTrue();

        $after = [
            'inputState' => RoundPlayerInputState::Locked->value,
            'attemptsLeft' => 0,
            'choices' => $before['choices'],
            'locked' => ['lockRank' => $index + 1, ...TierScore::fromGuess(SubmissionFixtures::guess($round, $seat))->toArray()],
        ];

        expect(SeatInputView::forSeat($participation)->toArray())->toBe($after)
            ->and([$before, $after])->toContain($view);

        $views[$table] = $view;
    }

    // `guess` lue vide, puis `locked` lue : la relecture de `guess` rend le
    // rang et les points, jamais une exception.
    expect($views['guess']['inputState'])->toBe(RoundPlayerInputState::Locked->value)
        ->and($views['guess']['locked']['lockRank'] ?? null)->toBe(1);
});
