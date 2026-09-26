<?php

namespace App\Actions\Game;

use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Round;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La fin de la révélation d'une manche — spec 60 § 9.6, contrat C7 § 4.14
 * (action interne, nom donné par le contrat).
 *
 * À `reveal_ends_at(k)`, sous les verrous `game` → manche `k` → manche `k+1`
 * (ordre global du § 4.5) :
 *
 * 1. `round.status = completed` ; `game.rounds_completed = COUNT(round WHERE
 *    status = completed)`, maintenu par 60 à chaque fin de révélation et
 *    écrasé au gel (E10-36) ;
 * 2. **aucune manche à jouer** : `FinalizeGame::handle($game,
 *    GameStatus::Completed, reveal_ends_at(k))` (contrat C13) — fin normale
 *    (§ 14.5), ou gel laissé par une annulation sans manche restante décidée
 *    pendant cette révélation (§ 15.2, étape 3) ;
 * 3. une manche reste et **aucun siège présent** (§ 1.2 : ligne
 *    `game_player` non expulsée dont le siège est `connected`) :
 *    {@see PauseGame}, à l'instant théorique `reveal_ends_at(k)`. Critère du
 *    siège présent, et non du participant (écart (b) du § 22 bis) : entre
 *    deux manches il n'existe aucun participant au sens de 10 § 7.7, et un
 *    retardataire admis à `k+1` et connecté doit empêcher la pause ;
 * 4. sinon rien : `k+1` est déjà programmée par `RevealRound(k)`.
 *
 * **Personne n'a trouvé** : révélation normale, zéro point ; la manche
 * `completed` à `found_count = 0` alimente la file « films jamais trouvés »
 * de 20, agrégée sans identité (10 § 7.4).
 *
 * **Ordre à instant égal** : `EndReveal(k)` précède `OpenTier(k+1, 1)`
 * (§ 4.1) — `OpenTier` attend, sans rien écrire, tant qu'une manche de la
 * partie est en `revealing`. Une étape non échue (`now < reveal_ends_at`),
 * périmée (partie close ou en pause, manche qui n'est pas en révélation) ou
 * déjà faite (`completed`) n'écrit rien.
 */
final readonly class EndReveal
{
    /**
     * Colonnes que la fin de révélation écrit sur la manche, recopiées sur
     * l'instance de l'appelant.
     *
     * @var list<string>
     */
    private const array COMPLETED_COLUMNS = ['status', 'updated_at'];

    public function __construct(
        private FinalizeGame $finalize,
        private PauseGame $pause,
    ) {}

    /**
     * @param  CarbonImmutable  $now  Instant d'exécution : l'étape n'est échue qu'à `reveal_ends_at`.
     */
    public function handle(Round $round, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($round, $now): void {
            $lockedGame = Game::query()->whereKey($round->game_id)->lockForUpdate()->firstOrFail();
            $completedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($lockedGame->ended_at !== null
                || $lockedGame->status !== GameStatus::Running
                || $completedRound->status !== RoundStatus::Revealing
                || $completedRound->reveal_ends_at === null
                || $completedRound->reveal_ends_at->greaterThan($now)) {
                self::reflect($round, $completedRound);

                return;
            }

            $revealEndsAt = $completedRound->reveal_ends_at;

            // 1. La manche est jouée ; le compteur de la partie la suit.
            $completedRound->forceFill(['status' => RoundStatus::Completed])->save();
            self::reflect($round, $completedRound);

            $lockedGame->forceFill([
                'rounds_completed' => Round::query()
                    ->where('game_id', $lockedGame->id)
                    ->where('status', RoundStatus::Completed->value)
                    ->count(),
            ])->save();

            // 2. Fin de partie, à l'instant théorique de la fin de révélation.
            $next = Round::query()->where('game_id', $lockedGame->id)->toPlay()->first();

            if (! $next instanceof Round) {
                $this->finalize->handle($lockedGame, GameStatus::Completed, $revealEndsAt);

                return;
            }

            // 3. Personne pour jouer la suivante : la partie se met en pause.
            Round::query()->whereKey($next->id)->lockForUpdate()->firstOrFail();

            if (! self::anySeatPresent($lockedGame)) {
                $this->pause->handle($lockedGame, $revealEndsAt);
            }

            // 4. Sinon, `k+1` est déjà programmée : rien.
        });
    }

    /**
     * Un **siège présent** de la partie (§ 1.2) : une ligne `game_player` non
     * expulsée dont le siège est `connected` — retardataire admis à la manche
     * suivante compris.
     */
    private static function anySeatPresent(Game $lockedGame): bool
    {
        return GamePlayer::query()
            ->where('game_id', $lockedGame->id)
            ->where('status', '<>', GamePlayerStatus::Kicked->value)
            ->whereIn('player_id', Player::query()
                ->where('connection_state', PlayerConnectionState::Connected->value)
                ->whereNull('kicked_at')
                ->select('id'))
            ->exists();
    }

    /**
     * Recopie sur l'instance de l'appelant ce que porte la manche relue, sans
     * rien réécrire en base.
     */
    private static function reflect(Round $round, Round $completedRound): void
    {
        $round->forceFill($completedRound->only(self::COMPLETED_COLUMNS))
            ->syncOriginalAttributes(self::COMPLETED_COLUMNS);
    }
}
