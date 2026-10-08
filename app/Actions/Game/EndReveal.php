<?php

namespace App\Actions\Game;

use App\Enums\GamePauseKind;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Round;
use App\Support\Game\GameJournal;
use App\Support\Game\SeatPresence;
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
 *    pendant cette révélation (§ 15.2, étape 3) — une demande de pause en
 *    attente y est effacée sans effet ;
 * 3. une manche reste et une **pause manuelle est demandée**
 *    (`pause_requested_at`, D64 du 07/10) : {@see PauseGame} `manual`, à
 *    l'instant théorique `reveal_ends_at(k)` — prioritaire sur la pause
 *    `empty`, même sans siège présent ; sinon, une manche reste et **aucun
 *    siège présent** (§ 1.2 : ligne
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
                if ($lockedGame->pause_requested_at !== null) {
                    $lockedGame->forceFill(['pause_requested_at' => null])->save();
                }

                if ($this->finalize->handle($lockedGame, GameStatus::Completed, $revealEndsAt)) {
                    GameJournal::gameFinalized($lockedGame, GameStatus::Completed, $revealEndsAt);
                }

                return;
            }

            // 3. Pause demandée, ou personne pour jouer la suivante : la
            // partie se met en pause.
            Round::query()->whereKey($next->id)->lockForUpdate()->firstOrFail();

            // Le budget a été vérifié à la demande ; il ne change qu'à une
            // reprise, impossible tant que la partie court.
            if ($lockedGame->pause_requested_at !== null) {
                $this->pause->handle($lockedGame, $revealEndsAt, GamePauseKind::Manual);
            } elseif (! SeatPresence::any($lockedGame)) {
                $this->pause->handle($lockedGame, $revealEndsAt, GamePauseKind::Empty);
            }

            // 4. Sinon, `k+1` est déjà programmée : rien.
        });
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
