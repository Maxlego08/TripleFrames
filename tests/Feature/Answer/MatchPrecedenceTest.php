<?php

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\GuessMatchKind;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\AnswerKey;
use App\Models\Guess;
use App\Models\Round;
use App\Support\Answers\AnswerMatcher;
use App\Support\Answers\AnswerRules;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Identity\PlayerToken;
use App\ValueObjects\Answers\MatchResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\MatchFixtures;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Précédence du verdict texte — spec 70 § 4.2, § 4.3, § 6, lot L70-3
|--------------------------------------------------------------------------
|
| (a) une clé exacte de la cible est toujours acceptée, homonyme publié ou
| non ; (b) une clé dérivée ne l'est que si aucun autre film publié ne porte
| la forme ; (c) une saisie qui désigne exactement un autre film publié est
| refusée, même sous le seuil de tolérance ; (d) la tolérance ; (e) le refus.
| L'ambiguïté se lit sur le catalogue publié ENTIER, à l'instant serveur de
| réception, par les deux lectures inconditionnelles K et O ; et elle n'est
| JAMAIS rétroactive : une bonne réponse verrouillée garde l'instantané de la
| règle qui l'a acceptée (lot L70-6).
|
*/

it('accepte toujours un titre complet homonyme d\'un autre film publié', function (): void {
    $original = MatchFixtures::movie('Solaris', ['en' => 'Solaris', 'fr' => 'Solaris']);
    $remake = MatchFixtures::movie('Solaris', ['en' => 'Solaris', 'fr' => 'Solaris']);
    // Un troisième film publié porte la forme sous une nature DÉRIVÉE : la
    // nature sous laquelle l'autre film la porte n'y change rien.
    $sequel = MatchFixtures::movie('Solaris: Return');

    expect(MatchFixtures::key($sequel, 'solaris')->key_kind)->toBe(AnswerKeyKind::Prefix);

    foreach ([$original, $remake] as $target) {
        $result = MatchFixtures::judge(MatchFixtures::roundOn($target), 'Solaris');

        // Accepté, et l'homonymie publiée à l'instant du match est journalisée
        // dans l'instantané (E10-50) — l'année, elle, n'est jamais demandée.
        expect($result)->toEqual(new MatchResult(
            accepted: true,
            submittedNormalized: 'solaris',
            answerKeyId: MatchFixtures::key($target, 'solaris')->id,
            answerKeyNormalized: 'solaris',
            matchKind: GuessMatchKind::Title,
            editDistance: 0,
            prefixWasAmbiguous: true,
        ));
    }

    // Un alias curé aussi : il désigne la cible, qu'un autre film publié porte
    // la même chaîne en titre entier ne l'empêche pas.
    $heist = MatchFixtures::movie('Grand Larceny Night', aliases: ['en' => ['Night Heist']]);
    MatchFixtures::movie('Night Heist');

    $viaAlias = MatchFixtures::judge(MatchFixtures::roundOn($heist), 'night heist!');

    expect($viaAlias->accepted)->toBeTrue()
        ->and($viaAlias->matchKind)->toBe(GuessMatchKind::Alias)
        ->and($viaAlias->answerKeyId)->toBe(MatchFixtures::key($heist, 'night heist')->id)
        ->and($viaAlias->editDistance)->toBe(0)
        ->and($viaAlias->prefixWasAmbiguous)->toBeTrue();

    // Sans homonyme publié, le drapeau reste faux.
    $alone = MatchFixtures::movie('Unique Lantern');

    expect(MatchFixtures::judge(MatchFixtures::roundOn($alone), 'Unique Lantern')->prefixWasAmbiguous)->toBeFalse();
});

it('refuse un préfixe porté par un autre film publié', function (): void {
    $towers = MatchFixtures::movie('The Lord of the Rings: The Two Towers', [
        'en' => 'The Lord of the Rings: The Two Towers',
        'fr' => 'Le Seigneur des Anneaux : Les Deux Tours',
    ]);
    MatchFixtures::movie('The Lord of the Rings: The Fellowship of the Ring', [
        'en' => 'The Lord of the Rings: The Fellowship of the Ring',
        'fr' => 'Le Seigneur des Anneaux : La Communauté de l\'Anneau',
    ]);

    $round = MatchFixtures::roundOn($towers);

    // Les deux préfixes existent bien sur la cible : le refus n'est pas vacant.
    expect(MatchFixtures::key($towers, 'seigneur des anneaux')->key_kind)->toBe(AnswerKeyKind::Prefix)
        ->and(MatchFixtures::key($towers, 'lord of the rings')->key_kind)->toBe(AnswerKeyKind::Prefix);

    // Refusé en (c), avec exactement le corps d'un refus franc : ni clé, ni
    // nature, ni distance (décision 13).
    foreach (['Le Seigneur des Anneaux', 'The Lord of the Rings'] as $typed) {
        expect(MatchFixtures::judge($round, $typed))
            ->toEqual(MatchResult::rejected(AnswerKeyNormalizer::normalize($typed)));
    }

    // Une faute de frappe sur le préfixe partagé ne passe pas davantage en
    // (d) : la clé dérivée ambiguë n'y est jamais candidate.
    expect(MatchFixtures::judge($round, 'Le Seigneur des Aneaux'))
        ->toEqual(MatchResult::rejected('seigneur des aneaux'));

    // Le titre complet, lui, est toujours accepté.
    $full = MatchFixtures::judge($round, 'Le Seigneur des Anneaux : Les Deux Tours');

    expect($full->accepted)->toBeTrue()
        ->and($full->matchKind)->toBe(GuessMatchKind::Title)
        ->and($full->prefixWasAmbiguous)->toBeFalse();

    // Témoin : un préfixe qu'aucun autre film publié ne porte est accepté en
    // (b), sans homonymie journalisée.
    $harbour = MatchFixtures::movie('Harbour Lights: The Long Night');
    $prefix = MatchFixtures::judge(MatchFixtures::roundOn($harbour), 'Harbour Lights');

    expect($prefix)->toEqual(new MatchResult(
        accepted: true,
        submittedNormalized: 'harbour lights',
        answerKeyId: MatchFixtures::key($harbour, 'harbour lights')->id,
        answerKeyNormalized: 'harbour lights',
        matchKind: GuessMatchKind::Prefix,
        editDistance: 0,
        prefixWasAmbiguous: false,
    ));
});

it('refuse une saisie égale à une clé d\'un autre film publié même sous le seuil de tolérance', function (): void {
    $alien = MatchFixtures::movie('Alien');
    $aliens = MatchFixtures::movie('Aliens');
    $alienThree = MatchFixtures::movie('Alien³');

    // Sans la garde exacte, « aliens » passerait la tolérance de la clé
    // `alien` : mêmes chiffres, distance 1, barème de 1 à cinq caractères.
    expect(AnswerKeyNormalizer::digits('aliens'))->toBe(AnswerKeyNormalizer::digits('alien'))
        ->and(AnswerKeyNormalizer::distance('aliens', 'alien'))
        ->toBeLessThanOrEqual(AnswerRules::tolerance(strlen(AnswerKeyNormalizer::compact('alien'))));

    $round = MatchFixtures::roundOn($alien);

    expect(MatchFixtures::judge($round, 'Aliens'))->toEqual(MatchResult::rejected('aliens'))
        ->and(MatchFixtures::judge(MatchFixtures::roundOn($alienThree), 'Aliens'))
        ->toEqual(MatchResult::rejected('aliens'));

    // Témoin : « Aliens » dépublié, plus aucun AUTRE film publié ne porte la
    // forme, et la même saisie, dans la même manche, passe en (d).
    $aliens->availability = ContentAvailability::Unpublished;
    $aliens->save();

    $tolerated = MatchFixtures::judge($round, 'Aliens');

    expect($tolerated)->toEqual(new MatchResult(
        accepted: true,
        submittedNormalized: 'aliens',
        answerKeyId: MatchFixtures::key($alien, 'alien')->id,
        answerKeyNormalized: 'alien',
        matchKind: GuessMatchKind::Title,
        editDistance: 1,
        prefixWasAmbiguous: false,
    ));
});

it('évalue l\'ambiguïté à l\'instant de réception quand un film est publié en pleine manche', function (): void {
    $towers = MatchFixtures::movie('The Lord of the Rings: The Two Towers', [
        'en' => 'The Lord of the Rings: The Two Towers',
        'fr' => 'Le Seigneur des Anneaux : Les Deux Tours',
    ]);
    // Le second épisode est encore un brouillon : il ne compte pas, l'ambiguïté
    // se mesurant sur le catalogue PUBLIÉ entier.
    $fellowship = MatchFixtures::movie('The Lord of the Rings: The Fellowship of the Ring', [
        'en' => 'The Lord of the Rings: The Fellowship of the Ring',
        'fr' => 'Le Seigneur des Anneaux : La Communauté de l\'Anneau',
    ], availability: ContentAvailability::Draft);

    $round = MatchFixtures::roundOn($towers);
    // Une seule instance pour toute la manche : si elle gardait quoi que ce
    // soit en mémoire, le verdict suivant la publication resterait périmé.
    $matcher = app(AnswerMatcher::class);

    $before = MatchFixtures::judge($round, 'Le Seigneur des Anneaux', $matcher);

    expect($before->accepted)->toBeTrue()
        ->and($before->matchKind)->toBe(GuessMatchKind::Prefix)
        ->and($before->prefixWasAmbiguous)->toBeFalse()
        ->and(MatchFixtures::judge($round, 'Le Seigneur des Aneaux', $matcher)->accepted)->toBeTrue();

    // Publié en pleine manche, avant même le recompte du drapeau : la
    // soumission suivante lit l'ambiguïté FRAÎCHE par la lecture O, jamais le
    // drapeau dénormalisé ni un cache.
    $fellowship->availability = ContentAvailability::Published;
    $fellowship->save();

    expect(MatchFixtures::key($towers, 'seigneur des anneaux')->is_ambiguous)->toBeFalse()
        ->and(MatchFixtures::judge($round, 'Le Seigneur des Anneaux', $matcher))
        ->toEqual(MatchResult::rejected('seigneur des anneaux'));

    // Le geste complet recompte le drapeau dans la foulée : la faute de frappe
    // cesse aussi d'être tolérée.
    MatchFixtures::publish($fellowship);

    expect(MatchFixtures::judge($round, 'Le Seigneur des Aneaux', $matcher))
        ->toEqual(MatchResult::rejected('seigneur des aneaux'));

    // Dépublié en pleine manche avant recompte : le drapeau reste vrai, mais O
    // ne trouve plus d'autre film publié, donc (b) accepte sans lire le
    // drapeau.
    $fellowship->availability = ContentAvailability::Unpublished;
    $fellowship->save();

    expect(MatchFixtures::key($towers, 'seigneur des anneaux')->is_ambiguous)->toBeTrue()
        ->and(MatchFixtures::judge($round, 'Le Seigneur des Anneaux', $matcher))
        ->toEqual(new MatchResult(
            accepted: true,
            submittedNormalized: 'seigneur des anneaux',
            answerKeyId: MatchFixtures::key($towers, 'seigneur des anneaux')->id,
            answerKeyNormalized: 'seigneur des anneaux',
            matchKind: GuessMatchKind::Prefix,
            editDistance: 0,
            prefixWasAmbiguous: false,
        ));

    // Jamais rétroactif : le verdict déjà rendu n'est pas réévalué, il reste
    // l'instantané de la règle appliquée à sa réception.
    expect($before->accepted)->toBeTrue()
        ->and($before->answerKeyId)->toBe(MatchFixtures::key($towers, 'seigneur des anneaux')->id);

    // La cible elle-même dépubliée en pleine manche garde ses clés : sa manche
    // reste jugeable, et son titre complet reste accepté.
    $towers->availability = ContentAvailability::Unpublished;
    $towers->save();

    expect(MatchFixtures::judge($round, 'The Lord of the Rings: The Two Towers', $matcher)->accepted)->toBeTrue();
});

it('exécute toujours exactement les deux lectures K et O, quel que soit le verdict', function (): void {
    $towers = MatchFixtures::movie('The Lord of the Rings: The Two Towers', [
        'en' => 'The Lord of the Rings: The Two Towers',
        'fr' => 'Le Seigneur des Anneaux : Les Deux Tours',
    ]);
    MatchFixtures::movie('The Lord of the Rings: The Fellowship of the Ring', [
        'en' => 'The Lord of the Rings: The Fellowship of the Ring',
        'fr' => 'Le Seigneur des Anneaux : La Communauté de l\'Anneau',
    ]);

    $round = MatchFixtures::roundOn($towers);
    $matcher = app(AnswerMatcher::class);

    /** @var list<string> $statements */
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    /** @var array<string, list<string>> $bySubmission */
    $bySubmission = [];

    // (a) titre, (b) sous-titre, (c) préfixe ambigu, (d) faute de frappe,
    // (e) saisie lointaine, et une saisie aux chiffres différents.
    foreach ([
        'Le Seigneur des Anneaux : Les Deux Tours',
        'The Two Towers',
        'Le Seigneur des Anneaux',
        'le seigneur des anneaux les deux tour',
        'Un tout autre film sans aucun rapport',
        'seigneur des anneaux 2',
    ] as $typed) {
        $statements = [];
        MatchFixtures::judge($round, $typed, $matcher);
        $bySubmission[$typed] = $statements;
    }

    // Deux lectures, les MÊMES instructions pour tout verdict : un refus pour
    // préfixe ambigu ne lit rien de plus qu'un refus franc ni qu'une
    // acceptation (invariant L4), et rien n'est écrit.
    $reference = $bySubmission['Le Seigneur des Anneaux : Les Deux Tours'];

    expect($reference)->toHaveCount(2)
        ->and($reference[0])->toContain('answer_key')->not->toContain('join')
        ->and($reference[1])->toContain('join')->toContain('availability');

    foreach ($bySubmission as $typed => $executed) {
        expect($executed)->toBe($reference, $typed);
    }
});

it('départage la tolérance par distance, puis nature exacte, prefix, subtitle, puis identifiant, sans lire la base', function (): void {
    $key = static function (int $id, string $normalized, AnswerKeyKind $kind, bool $ambiguous = false): AnswerKey {
        $answerKey = new AnswerKey;
        $answerKey->forceFill([
            'id' => $id,
            'movie_id' => 7,
            'normalized' => $normalized,
            'key_kind' => $kind,
            'is_ambiguous' => $ambiguous,
        ]);

        return $answerKey;
    };

    // Les clés dérivées reçoivent les identifiants les PLUS PETITS : un
    // départage qui ignorerait la nature (distance puis identifiant) retiendrait
    // le sous-titre ou le préfixe, jamais l'alias attendu.
    $keys = [
        $key(1, 'silver tide', AnswerKeyKind::Subtitle),
        $key(2, 'silver tidy', AnswerKeyKind::Prefix),
        $key(20, 'silver tida', AnswerKeyKind::Alias),
        $key(10, 'silver tido', AnswerKeyKind::Alias),
        $key(5, 'silver tidez', AnswerKeyKind::Title),
    ];

    DB::enableQueryLog();

    // Plus petite distance d'abord : la clé de titre à distance 0 (forme
    // compacte), quel que soit l'ordre de K.
    $closest = AnswerMatcher::decide('silver tide z', 7, array_reverse($keys), []);

    // La distance prime sur la nature : un préfixe à distance 0 l'emporte sur
    // un titre à distance 1 (un départage par nature d'abord retiendrait le
    // titre).
    $distanceFirst = AnswerMatcher::decide('silver tide z', 7, [
        $key(2, 'silver tidez', AnswerKeyKind::Prefix),
        $key(5, 'silver tidey', AnswerKeyKind::Title),
    ], []);

    // À distance égale (1), la nature exacte l'emporte, puis l'identifiant le
    // plus petit parmi les exactes.
    $exactFirst = AnswerMatcher::decide('silver tidq', 7, $keys, []);

    // Sans clé exacte candidate, prefix avant subtitle, malgré l'identifiant
    // plus petit du sous-titre.
    $prefixFirst = AnswerMatcher::decide('silver tidq', 7, [$keys[0], $keys[1]], []);

    // Une clé dérivée ambiguë n'est jamais candidate en tolérance.
    $ambiguousSkipped = AnswerMatcher::decide('silver tidq', 7, [
        $keys[0],
        $key(2, 'silver tidy', AnswerKeyKind::Prefix, ambiguous: true),
    ], []);

    expect(DB::getQueryLog())->toBe([])
        ->and($closest->answerKeyId)->toBe(5)
        ->and($closest->editDistance)->toBe(0)
        ->and($distanceFirst->answerKeyId)->toBe(2)
        ->and($distanceFirst->matchKind)->toBe(GuessMatchKind::Prefix)
        ->and($distanceFirst->editDistance)->toBe(0)
        ->and($exactFirst->answerKeyId)->toBe(10)
        ->and($exactFirst->matchKind)->toBe(GuessMatchKind::Alias)
        ->and($exactFirst->editDistance)->toBe(1)
        ->and($prefixFirst->answerKeyId)->toBe(2)
        ->and($prefixFirst->matchKind)->toBe(GuessMatchKind::Prefix)
        ->and($ambiguousSkipped->answerKeyId)->toBe(1)
        ->and($ambiguousSkipped->matchKind)->toBe(GuessMatchKind::Subtitle);

    // Le verdict ne dépend que de (s, K, O) : rejoué, il est identique.
    expect(AnswerMatcher::decide('silver tidq', 7, $keys, []))->toEqual($exactFirst)
        // Un autre film publié portant la saisie la refuse en (c) ; la cible
        // elle-même dans O ne compte pas.
        ->and(AnswerMatcher::decide('silver tidq', 7, $keys, [8])->accepted)->toBeFalse()
        ->and(AnswerMatcher::decide('silver tidq', 7, $keys, [7]))->toEqual($exactFirst);
});

it('ne recalcule jamais un guess existant', function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();
    Date::setTestNow(CarbonImmutable::parse('2026-09-27 19:05:00.125'));

    // La manche porte « Harbour Lights: The Long Night » ; « Harbour Lights:
    // Dawn », qui partage son préfixe, n'est encore qu'un brouillon.
    $target = SubmissionFixtures::movie('Harbour Lights: The Long Night');
    $sequel = MatchFixtures::movie('Harbour Lights: Dawn', availability: ContentAvailability::Draft);
    $early = PlayerToken::mint(Locale::French);
    $late = PlayerToken::mint(Locale::English);
    [$game, $round, [$earlySeat, $lateSeat]] = SubmissionFixtures::openedRound([$early, $late], target: $target);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds($cadence);

    // Seul film publié à porter le préfixe : accepté en (b) et verrouillé.
    SubmissionFixtures::submit($this, $earlySeat, $early, 'Harbour Lights', $at)
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1]);

    $guess = SubmissionFixtures::guess($round, $earlySeat);
    $stored = $guess->getAttributes();
    $closedAt = SubmissionFixtures::participation($round, $earlySeat)->getAttributes();

    expect($guess->match_kind)->toBe(GuessMatchKind::Prefix)
        ->and($guess->answer_key_id)->toBe(MatchFixtures::key($target, 'harbour lights')->id)
        ->and($guess->prefix_was_ambiguous)->toBeFalse();

    // Publié en pleine manche : le préfixe devient ambigu dès la soumission
    // suivante, refusée ; le siège verrouillé qui soumet encore reçoit 409
    // `closed` et rien n'est rejugé.
    $queries = SubmissionFixtures::queries(function () use ($sequel, $lateSeat, $late, $earlySeat, $early, $at, $cadence, $game, $target): void {
        MatchFixtures::publish($sequel);

        SubmissionFixtures::submit($this, $lateSeat, $late, 'Harbour Lights', $at->addMilliseconds($cadence))
            ->assertOk()
            ->assertExactJson(SubmissionFixtures::rejectedBody($game->settings_snapshot->attemptsPerRound - 1));

        SubmissionFixtures::submit($this, $earlySeat, $early, $target->title_original, $at->addMilliseconds(2 * $cadence))
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::Locked));
    });

    // La règle d'aujourd'hui refuserait la même saisie…
    expect(MatchFixtures::key($target, 'harbour lights')->is_ambiguous)->toBeTrue()
        ->and(MatchFixtures::judge($round, 'Harbour Lights'))->toEqual(MatchResult::rejected('harbour lights'));

    // …mais la bonne réponse déjà attribuée n'est ni relue, ni réécrite, ni
    // doublée : aucune écriture de `guess`, la même ligne à l'octet près, le
    // même rang, la même saisie close.
    expect(array_filter(
        SubmissionFixtures::writes($queries),
        static fn (string $sql): bool => SubmissionFixtures::touches($sql, 'guess'),
    ))->toBe([])
        ->and(Guess::query()->whereKey($guess->id)->firstOrFail()->getAttributes())->toBe($stored)
        ->and(Guess::query()->where('round_id', $round->id)->count())->toBe(1)
        ->and(Round::query()->whereKey($round->id)->value('found_count'))->toBe(1)
        ->and(SubmissionFixtures::participation($round, $earlySeat)->getAttributes())->toBe($closedAt);
});
