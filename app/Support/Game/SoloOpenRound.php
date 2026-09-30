<?php

namespace App\Support\Game;

use App\Actions\Game\RevealSoloAnswer;
use App\Actions\Game\SkipSoloRound;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use LogicException;

/**
 * La précondition commune des deux gestes d'entraînement assisté — spec 60
 * § 16.5 (D18 du 23/09) : « Voir la réponse » ({@see RevealSoloAnswer}) et
 * « Passer la manche » ({@see SkipSoloRound}).
 *
 * **Barrière 1 de 10 § 7.10** ({@see self::assertSolo()}) : `revealed` et
 * `skipped` sont inatteignables hors solo — une partie multijoueur, ou un
 * siège de salon, lève une `LogicException`, avant toute lecture ; les
 * routes ne résolvent d'ailleurs que la partie solo du siège.
 *
 * **Précondition** ({@see self::lock()}), relue sous les verrous, dans
 * l'ordre global `game → round → round_player` (§ 4.5, E10-51) : la partie
 * est en cours (`running`, `ended_at` nul), une manche y est `running` à
 * `ended_at` nul — phase `running` —, et la saisie du siège dans cette
 * manche est ouverte (`open` ou `text_exhausted`, le complément exact de
 * {@see RoundPlayerInputState::isClosed()}). Sinon `null` : le geste
 * répond 409 `{ "code": "round_not_running" }`, sans rien écrire.
 *
 * Classe interne (nom libre, hors schéma) : elle ne décide rien d'autre
 * qu'un « oui, la manche du siège court » ; le rattrapage ({@see
 * \App\Actions\Game\CatchUpGame}) est appelé AVANT par le geste, pour que la
 * phase lue soit l'état échu.
 */
final readonly class SoloOpenRound
{
    private function __construct(
        public Game $game,
        public Round $round,
        public RoundPlayer $roundPlayer,
    ) {}

    /**
     * Barrière 1 de 10 § 7.10 : une partie solo, et un siège solo qui y
     * participe.
     *
     * @throws LogicException Partie non solo, siège de salon, ou siège sans
     *                        participation à la partie.
     */
    public static function assertSolo(Game $game, Player $seat): void
    {
        if ($game->mode !== GameMode::Solo || $game->room_id !== null) {
            throw new LogicException('Les gestes d’entraînement n’existent qu’en solo (spec 60 § 16.5, 10 § 7.10).');
        }

        if ($seat->room_id !== null) {
            throw new LogicException('Un geste d’entraînement vient toujours d’un siège solo (spec 60 § 16.5).');
        }

        if (! $game->gamePlayers()->where('player_id', $seat->id)->exists()) {
            throw new LogicException('Le siège ne participe pas à la partie solo visée.');
        }
    }

    /**
     * La partie, sa manche en cours et la saisie du siège, prises `FOR
     * UPDATE` dans cet ordre — ou `null` hors précondition.
     */
    public static function lock(Game $game, Player $seat): ?self
    {
        $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->first();

        if (! $lockedGame instanceof Game
            || $lockedGame->ended_at !== null
            || $lockedGame->status !== GameStatus::Running) {
            return null;
        }

        $round = Round::query()
            ->where('game_id', $lockedGame->id)
            ->where('status', RoundStatus::Running->value)
            ->orderBy('sequence_index')
            ->lockForUpdate()
            ->first();

        if (! $round instanceof Round || $round->ended_at !== null || $round->started_at === null) {
            return null;
        }

        $roundPlayer = RoundPlayer::query()
            ->where('round_id', $round->id)
            ->where('player_id', $seat->id)
            ->lockForUpdate()
            ->first();

        if (! $roundPlayer instanceof RoundPlayer || $roundPlayer->input_state->isClosed()) {
            return null;
        }

        return new self($lockedGame, $round, $roundPlayer);
    }
}
