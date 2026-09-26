<?php

use App\Enums\InputDifficulty;
use App\Enums\RoundPlayerInputState;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;

/*
|--------------------------------------------------------------------------
| Tentatives, plafond et épuisement — spec 70 § 3 et § 8
|--------------------------------------------------------------------------
|
| Fichier partagé entre lots : L70-4 y pose l'effet de `text_exhausted` sur
| la fin anticipée (D20 du 23/09, E10-53) ; L70-5 (plafond, comptage,
| routes) et L70-9 (clic à `T_N`) le complètent.
|
*/

/**
 * Une manche en cours d'une partie en Normal, seule difficulté où
 * `text_exhausted` est atteignable (10 A16).
 */
function attemptsNormalRound(): Round
{
    $game = Game::factory()->create();

    expect($game->input_difficulty)->toBe(InputDifficulty::Normal);

    return Round::factory()->forGame($game)->running()->create();
}

/**
 * Un siège du salon de la manche, déconnecté à la demande.
 */
function attemptsSeat(Round $round, bool $disconnected = false): Player
{
    $factory = Player::factory();

    return ($disconnected ? $factory->disconnected() : $factory)->create(['room_id' => $round->room_id]);
}

/**
 * Un siège verrouillé, avec la ligne `guess` que l'invariant exige.
 */
function attemptsLockedSeat(Round $round): RoundPlayer
{
    $player = attemptsSeat($round);
    Guess::factory()->forRound($round, $player)->create();

    return RoundPlayer::factory()->forRound($round, $player)->locked()->create();
}

it('un siège text_exhausted ne compte pas comme saisie close pour la fin anticipée', function (): void {
    // A a trouvé ; B a épuisé son texte libre avant `T_N` et attend le QCM.
    $round = attemptsNormalRound();
    attemptsLockedSeat($round);
    $seatB = RoundPlayer::factory()->forRound($round, attemptsSeat($round))->textExhausted()->create();

    expect($seatB->input_state->isClosed())->toBeFalse()
        ->and($seatB->input_closed_at)->toBeNull()
        ->and($round->isEarlyEndReached())->toBeFalse();

    // B clique à `T_N` — ici faux : sa saisie se clôt, et avec elle la manche.
    RoundPlayer::query()->whereKey($seatB->id)->update([
        'input_state' => RoundPlayerInputState::QcmWrong,
        'input_closed_at' => now(),
    ]);

    expect($round->isEarlyEndReached())->toBeTrue();

    // Seul participant, un siège `text_exhausted` suffit à tenir la manche
    // ouverte : la borne `COUNT(participants) >= 1` est satisfaite, pas la
    // clôture.
    $alone = attemptsNormalRound();
    RoundPlayer::factory()->forRound($alone, attemptsSeat($alone))->textExhausted()->create();

    expect($alone->isEarlyEndReached())->toBeFalse();

    // Le dénominateur, lui, ne change pas avec D20 : un siège `text_exhausted`
    // déconnecté n'est pas un participant et ne bloque rien.
    $away = attemptsNormalRound();
    attemptsLockedSeat($away);
    RoundPlayer::factory()->forRound($away, attemptsSeat($away, disconnected: true))->textExhausted()->create();

    expect($away->isEarlyEndReached())->toBeTrue();
});
