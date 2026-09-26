<?php

use App\Enums\InputDifficulty;
use App\Enums\RoundPlayerInputState;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| États de saisie — spec 70 § 3, lot L70-4 (D20 du 23/09, contrat C10)
|--------------------------------------------------------------------------
|
| Sept états, trois prédicats. `text_exhausted` (« texte épuisé, QCM
| attendu ») est le seul où ils divergent : le texte est fermé, le clic QCM
| reste recevable, et la saisie n'est PAS close pour la fin anticipée. Le
| scope `RoundPlayer::open()` garde son nom et élargit sa sémantique à
| « saisie non close », lue sur `notClosedValues()`, seule liste.
|
*/

/**
 * Les valeurs d'une liste d'états, triées, pour comparer des ensembles.
 *
 * @param  iterable<RoundPlayerInputState>  $states
 * @return list<string>
 */
function inputStateValues(iterable $states): array
{
    $values = [];

    foreach ($states as $state) {
        $values[] = $state->value;
    }

    sort($values);

    return $values;
}

it("acceptsText n'est vrai que pour open", function (): void {
    foreach (RoundPlayerInputState::cases() as $state) {
        expect($state->acceptsText())->toBe($state === RoundPlayerInputState::Open, $state->value);
    }

    // `text_exhausted` en particulier : plus aucune saisie texte.
    expect(RoundPlayerInputState::TextExhausted->acceptsText())->toBeFalse();
});

it("acceptsChoice n'est vrai que pour open et text_exhausted", function (): void {
    $accepting = array_filter(
        RoundPlayerInputState::cases(),
        static fn (RoundPlayerInputState $state): bool => $state->acceptsChoice(),
    );

    expect(inputStateValues($accepting))->toBe(['open', 'text_exhausted']);

    // Un clic faux ou juste, un plafond sans QCM et les gestes du solo ferment
    // le QCM : aucun de ces sièges ne reçoit `seat.choices`.
    foreach ([
        RoundPlayerInputState::AttemptsExhausted,
        RoundPlayerInputState::Locked,
        RoundPlayerInputState::QcmWrong,
        RoundPlayerInputState::Revealed,
        RoundPlayerInputState::Skipped,
    ] as $state) {
        expect($state->acceptsChoice())->toBeFalse($state->value);
    }
});

it('isClosed est faux pour open et text_exhausted et vrai pour les cinq autres états', function (): void {
    // Sept états, liste close de 10 § 7.6 : un cas ajouté sans décider de sa
    // clôture ferait mentir le prédicat de fin anticipée.
    expect(RoundPlayerInputState::cases())->toHaveCount(7);

    $closed = array_filter(
        RoundPlayerInputState::cases(),
        static fn (RoundPlayerInputState $state): bool => $state->isClosed(),
    );
    $notClosed = array_filter(
        RoundPlayerInputState::cases(),
        static fn (RoundPlayerInputState $state): bool => ! $state->isClosed(),
    );

    expect(inputStateValues($notClosed))->toBe(['open', 'text_exhausted'])
        ->and(inputStateValues($closed))->toBe([
            'attempts_exhausted',
            'locked',
            'qcm_wrong',
            'revealed',
            'skipped',
        ]);

    // `notClosedValues()` est le complément exact d'`isClosed()`, dans l'ordre
    // du contrat C10 : c'est la liste que lisent le scope et 10 § 7.7.
    expect(RoundPlayerInputState::notClosedValues())->toBe(['open', 'text_exhausted']);

    foreach (RoundPlayerInputState::cases() as $state) {
        expect(in_array($state->value, RoundPlayerInputState::notClosedValues(), true))
            ->toBe(! $state->isClosed(), $state->value);
    }
});

it('le scope open retient les sièges open et text_exhausted', function (): void {
    // Chaque état dans la partie où il est atteignable : `text_exhausted` en
    // Normal, `revealed` et `skipped` en solo, `locked` avec sa ligne `guess`.
    $game = Game::factory()->create(['input_difficulty' => InputDifficulty::Normal]);
    $round = Round::factory()->forGame($game)->running()->create();
    $seat = static fn (): Player => Player::factory()->create(['room_id' => $game->room_id]);

    $open = RoundPlayer::factory()->forRound($round, $seat())->create();
    $textExhausted = RoundPlayer::factory()->forRound($round, $seat())->textExhausted()->create();
    RoundPlayer::factory()->forRound($round, $seat())->attemptsExhausted()->create();
    RoundPlayer::factory()->forRound($round, $seat())->qcmWrong()->create();

    $winner = $seat();
    RoundPlayer::factory()->forRound($round, $winner)->locked()->create();
    Guess::factory()->forRound($round, $winner)->create();

    $soloGame = Game::factory()->solo()->create();
    $soloRound = Round::factory()->forGame($soloGame)->running()->create();
    RoundPlayer::factory()->forRound($soloRound, Player::factory()->solo()->create())->revealed()->create();
    RoundPlayer::factory()->forRound($soloRound, Player::factory()->solo()->create())->skipped()->create();

    // Les sept états sont bien en base, un par ligne.
    expect(inputStateValues(RoundPlayer::query()->get()->pluck('input_state')))
        ->toBe(inputStateValues(RoundPlayerInputState::cases()));

    $retained = RoundPlayer::query()->open()->orderBy('id')->get();

    expect($retained->modelKeys())->toBe([$open->id, $textExhausted->id])
        ->and(inputStateValues($retained->pluck('input_state')))->toBe(['open', 'text_exhausted']);

    // Le scope compose avec une manche donnée, comme le lira 60.
    expect(RoundPlayer::query()->where('round_id', $soloRound->id)->open()->count())->toBe(0);
});

// Ajouté par le lot (hors intitulés de la spec) : `types/answers.ts` est la
// seule déclaration client de ces types (R-27), et `tsc` ne sait pas qu'un
// cas PHP ajouté doit l'être aussi dans l'union.
it("l'union InputState de types/answers.ts couvre exactement les cas de l'enum, sans aucun import", function (): void {
    $path = resource_path('js/types/answers.ts');

    expect($path)->toBeFile();

    $source = FrontSource::withoutComments((string) file_get_contents($path));

    // Déclarations seules : le fichier naît avant `game-wire.ts`, qui l'importe.
    expect(preg_match('/^\s*import\b/m', $source))->toBe(0);

    expect(preg_match('/export\s+type\s+InputState\s*=([^;]+);/', $source, $declaration))->toBe(1);

    preg_match_all("/'([^']*)'/", $declaration[1], $literals);

    // Rien d'autre que des littéraux : un `string` élargirait l'union en silence.
    expect(trim((string) preg_replace("/'[^']*'|\|/", '', $declaration[1])))->toBe('');

    $client = $literals[1];
    $server = array_map(static fn (RoundPlayerInputState $state): string => $state->value, RoundPlayerInputState::cases());

    sort($client);
    sort($server);

    // Liste triée SANS dédoublonnage : un littéral répété échoue aussi.
    expect($client)->toBe($server);

    // Les quatre types du contrat, et le réexport par `types/index.ts`.
    foreach (['InputState', 'ChoicesPayload', 'SeatInputView', 'SubmissionResult'] as $type) {
        expect(preg_match('/export\s+(?:type|interface)\s+'.$type.'\b/', $source))->toBe(1, $type);
    }

    $index = FrontSource::withoutComments((string) file_get_contents(resource_path('js/types/index.ts')));

    expect($index)->toContain("export type * from './answers';");
});
