<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Events\Game\GamePaused;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Room;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Support\Game\GameJournal;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La pause d'une partie — spec 60 § 14.1, contrat C7 § 4.11 (action interne,
 * nom donné par le contrat).
 *
 * Appelée par `EndReveal(k)` quand une manche reste à jouer et qu'**aucun
 * siège n'est présent** (§ 1.2, écart (b) du § 22 bis), sous le verrou
 * `game`, que la pause reprend (sans effet sous l'appelant qui le tient) :
 *
 * - `game.status = paused`, `paused_at = $pausedAt` — l'instant THÉORIQUE
 *   `reveal_ends_at(k)`, jamais l'heure d'exécution ;
 * - la manche déjà programmée est **déprogrammée** (`started_at` remis à
 *   NULL, E10-46) : son palier 1 n'est plus servi (la garde de service exige
 *   une origine de temps), et son ouverture est périmée. Le jeton frappé est
 *   gardé : la reprise le réutilise (§ 14.2) ;
 * - après commit : le job {@see InterruptPausedGame} à `paused_at +
 *   pauseTimeoutMs` (clôture à 15 minutes, § 14.3) et, **en multijoueur
 *   seulement**, `game.paused` `{ pausedAt, interruptsAt }` ; la pause au
 *   journal `game` (§ 4.7).
 *
 * **L'horloge d'une manche ne se met jamais en pause** : la pause n'arrive
 * qu'entre deux manches, et une manche en cours refuse la pause. Une partie
 * close ou déjà en pause n'est pas mise en pause (`LogicException`) : la
 * garde de l'appelant la précède.
 */
final readonly class PauseGame
{
    /**
     * Colonnes de `game` que la pause écrit, recopiées sur l'instance de
     * l'appelant.
     *
     * @var list<string>
     */
    private const array PAUSED_COLUMNS = ['status', 'paused_at', 'updated_at'];

    /**
     * @param  CarbonImmutable  $pausedAt  Instant théorique de la pause : `reveal_ends_at(k)`.
     *
     * @throws LogicException Partie close ou qui n'est pas en cours, ou manche
     *                        encore en cours ou en révélation.
     */
    public function handle(Game $game, CarbonImmutable $pausedAt): void
    {
        DB::transaction(static function () use ($game, $pausedAt): void {
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($lockedGame->ended_at !== null || $lockedGame->status !== GameStatus::Running) {
                throw new LogicException(sprintf(
                    'PauseGame : la partie est %s ; seule une partie en cours se met en pause.',
                    $lockedGame->status->value,
                ));
            }

            if (Round::query()
                ->where('game_id', $lockedGame->id)
                ->whereIn('status', [RoundStatus::Running->value, RoundStatus::Revealing->value])
                ->exists()) {
                throw new LogicException('PauseGame : une manche court encore ; la pause n’arrive qu’entre deux manches.');
            }

            $scheduledRounds = Round::query()
                ->where('game_id', $lockedGame->id)
                ->where('status', RoundStatus::Pending->value)
                ->whereNotNull('started_at')
                ->orderBy('sequence_index')
                ->lockForUpdate()
                ->get();

            foreach ($scheduledRounds as $scheduledRound) {
                $scheduledRound->forceFill(['started_at' => null])->save();
            }

            $lockedGame->forceFill([
                'status' => GameStatus::Paused,
                'paused_at' => $pausedAt,
            ])->save();

            $game->forceFill($lockedGame->only(self::PAUSED_COLUMNS))
                ->syncOriginalAttributes(self::PAUSED_COLUMNS);

            $armedAt = $lockedGame->paused_at ?? throw new LogicException('PauseGame : instant de pause perdu à l’écriture.');

            InterruptPausedGame::dispatch($lockedGame->id, WireTime::iso($armedAt));

            GameJournal::gamePaused($lockedGame, $armedAt);

            // Garde de mode (§ 11.2) : aucune diffusion en solo.
            if ($lockedGame->mode === GameMode::Multiplayer) {
                GamePaused::dispatch(self::room($lockedGame), $lockedGame, [
                    'pausedAt' => WireTime::iso($armedAt),
                    'interruptsAt' => WireTime::iso($armedAt->addMilliseconds(EngineConstants::pauseTimeoutMs())),
                ]);
            }
        });
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('PauseGame : partie multijoueur sans salon.');
    }
}
