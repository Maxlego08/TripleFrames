<?php

use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Answers\BruteForceProbe;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Sonde de force brute — spec 70 § 13.2, lot L70-11
|--------------------------------------------------------------------------
|
| Manches gagnées au palier 1, en texte libre, après PLUS de `K` tentatives
| fausses, sur les manches non annulées démarrées dans la fenêtre. `K` et le
| début de la fenêtre sont des paramètres (le job `ReportBruteForce` de 100
| les lit dans sa configuration) : aucune valeur n'est codée dans la sonde.
| La jointure `round` exclut `cancelled` (invariant L1).
|
*/

/**
 * Une manche démarrée à `$startedAt`, gagnée par un siège qui a fait
 * `$wrongAttempts` tentatives fausses avant de trouver au palier `$tierIndex`.
 */
function bruteForceWin(
    CarbonImmutable $startedAt,
    int $wrongAttempts,
    int $tierIndex = 1,
    bool $viaChoice = false,
    ?Round $round = null,
): Guess {
    $round ??= Round::factory()->completed()->create(['started_at' => $startedAt]);
    $player = Player::factory()->create();

    RoundPlayer::factory()->forRound($round, $player)->locked()->withWrongAttempts($wrongAttempts)->create();

    $guess = Guess::factory()->forRound($round, $player)->atTier($tierIndex);

    return ($viaChoice ? $guess->viaChoice() : $guess)->create();
}

it('compte les manches gagnées au palier 1 en texte libre après plus de K tentatives fausses', function (): void {
    $k = 10;
    $since = CarbonImmutable::parse('2026-09-14 04:10:00.000');
    $inWindow = $since->addDays(2);

    // Comptées : plus de K tentatives, palier 1, texte, dans la fenêtre —
    // y compris une manche démarrée à l'instant exact du début de la fenêtre.
    bruteForceWin($inWindow, $k + 1);
    bruteForceWin($since, $k + 5);

    // Écartées, une condition à la fois : exactement K (« plus de », pas « au
    // moins ») ; palier 2 ; clic QCM, pas du texte libre ; démarrée une
    // milliseconde avant la fenêtre.
    bruteForceWin($inWindow, $k);
    bruteForceWin($inWindow, $k + 10, tierIndex: 2);
    bruteForceWin($inWindow, $k + 10, viaChoice: true);
    bruteForceWin($since->subMillisecond(), $k + 10);

    // Les tentatives comptées sont celles du GAGNANT : un autre siège de la
    // même manche, qui a martelé sans trouver, ne la qualifie pas.
    $quietWin = bruteForceWin($inWindow, 0);
    RoundPlayer::factory()
        ->forRound(Round::query()->findOrFail($quietWin->round_id), Player::factory()->create())
        ->attemptsExhausted($k + 20)
        ->create();

    $probe = new BruteForceProbe;

    expect($probe->count($k, $since))->toBe(2)
        // `K` est un paramètre : abaissé d'un cran, la manche à exactement dix
        // tentatives entre dans le compte.
        ->and($probe->count($k - 1, $since))->toBe(3)
        // La fenêtre aussi : reculée d'une seconde, la manche démarrée juste
        // avant le début y entre.
        ->and($probe->count($k, $since->subSecond()))->toBe(3)
        // Avancée après la manche démarrée au début de la fenêtre, elle l'écarte.
        ->and($probe->count($k, $since->addMillisecond()))->toBe(1);

    // Un seuil négatif n'a aucun sens : jamais un compte silencieux.
    expect(fn () => $probe->count(-1, $since))->toThrow(InvalidArgumentException::class);
});

it('exclut les manches annulées', function (): void {
    $k = 10;
    $since = CarbonImmutable::parse('2026-09-14 04:10:00.000');
    $inWindow = $since->addDay();

    // Une manche annulée garde ses lignes `guess`, immuables : c'est la
    // jointure `round` qui l'écarte (invariant L1).
    $cancelled = Round::factory()->cancelled()->create(['started_at' => $inWindow]);
    bruteForceWin($inWindow, $k + 1, round: $cancelled);

    $probe = new BruteForceProbe;

    expect($probe->count($k, $since))->toBe(0);

    // Seul `cancelled` est exclu : une manche encore en révélation, ou
    // clôturée, compte.
    bruteForceWin($inWindow, $k + 1);
    bruteForceWin($inWindow, $k + 1, round: Round::factory()->revealing()->create(['started_at' => $inWindow]));

    expect($probe->count($k, $since))->toBe(2);
});
