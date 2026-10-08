<?php

namespace App\Support\Game;

use App\Console\Commands\GameRescheduleCommand;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsBounds;
use App\Support\Realtime\WireTime;

/**
 * Le prédicat « partie en cours » — spec 60 § 17, contrat C17 (propriété de
 * 60, D32 du 23/09 ; noms et signatures figés).
 *
 * Une partie est **en cours** si et seulement si `ended_at IS NULL AND
 * status IN ('running', 'paused')` — le scope {@see Game::inProgress()}, seule
 * écriture du prédicat, que cette classe ne fait que compter et résumer. **Solo
 * compris** : le déploiement n'est pas atomique (D31), et Composer, les
 * migrations, `optimize` et `queue:restart` cassent aussi les requêtes et les
 * jobs d'une partie solo. **Un salon au lobby ne compte pas** : sans partie, il
 * n'a rien à perdre (Echo se reconnecte seul, puis le client se
 * resynchronise). Forme SQL servie par `game_ended_idx (ended_at)`, aucun index
 * nouveau.
 *
 * **Consommateurs** : `deploy:guard` et `deploy:drain` (100, contrat
 * C18-bis), qui comptent les parties en cours et dérivent la borne d'attente
 * du drainage de {@see self::maxNaturalDurationMs()} ;
 * {@see GameRescheduleCommand}, qui lit la même durée pour reconnaître une
 * partie bloquée (§ 14.4) et affiche {@see self::summary()} à la console du
 * porteur.
 *
 * **Aucune donnée de joueur** ne sort d'ici : ni pseudo, ni `room_code`, ni
 * identifiant interne — des modes, des statuts, des instants et des comptes.
 * Aucune charge client : rien de ceci ne part vers un navigateur (le bandeau
 * de maintenance ne lit que le drapeau de drainage, 100).
 */
final class GamesInProgress
{
    /** Millisecondes par seconde : les bornes de durée sont en secondes. */
    private const int MILLISECONDS_PER_SECOND = 1000;

    /**
     * Décomptes de lancement propres à la partie, hors remplaçants : celui de
     * la manche 1 (§ 5.2). Chaque remplaçant de la réserve en ajoute un
     * (§ 15.2), d'où `(1 + drawSubstituteMargin)` dans la formule.
     */
    private const int LAUNCH_COUNTDOWNS = 1;

    /** Le nombre de parties en cours, solo compris (§ 17.1). */
    public static function count(): int
    {
        return Game::query()->inProgress()->count();
    }

    /**
     * Les parties en cours, pour la console du porteur, triées par
     * `startedAt` croissant — puis dans l'ordre de création, à instant égal —,
     * **sans aucune donnée de joueur** (contrat C17 § 2).
     *
     * `startedAt` est un `IsoMs` ({@see WireTime}) ; `mode` et `status` sont
     * les valeurs de {@see GameMode} et de {@see GameStatus}.
     *
     * @return list<array{mode: string, status: string, startedAt: string, roundsCompleted: int, roundsCount: int}>
     */
    public static function summary(): array
    {
        $games = Game::query()
            ->inProgress()
            ->orderBy('started_at')
            ->orderBy('id')
            ->get(['id', 'mode', 'status', 'started_at', 'rounds_completed', 'rounds_count']);

        $summary = [];

        foreach ($games as $game) {
            $summary[] = [
                'mode' => $game->mode->value,
                'status' => $game->status->value,
                'startedAt' => WireTime::iso($game->started_at),
                'roundsCompleted' => $game->rounds_completed,
                'roundsCount' => $game->rounds_count,
            ];
        }

        return $summary;
    }

    /**
     * Durée naturelle maximale d'une partie **sans pause**, en millisecondes
     * (§ 17.3, point 3) :
     *
     * `(MAX_ROUNDS_COUNT + drawSubstituteMargin) × (MAX_ROUND_DURATION +
     * MAX_REVEAL_DURATION) × 1000 + (MAX_ROUNDS_COUNT + drawSubstituteMargin) ×
     * tier_grace_ms + (1 + drawSubstituteMargin) × launchCountdownMs`,
     *
     * soit environ 77,5 min aux bornes actuelles : chaque manche jouable,
     * réserve de tirage comprise, à sa durée et à sa révélation maximales,
     * plus la grâce finale de chacune, plus le décompte de la manche 1 et celui
     * de chaque remplaçant. La formule suppose l'enchaînement sans intervalle
     * du § 5.3 (`T₁(k+1) = reveal_ends_at(k)`). Chaque pause ajoute au plus
     * `pauseTimeoutMs + launchCountdownMs` (l'attente, puis le décompte de
     * reprise, que `total_paused_ms` ne compte pas) : c'est à l'appelant d'en
     * tenir compte (drainage de 100, partie bloquée du § 14.4).
     *
     * **Sans aucun littéral** : les bornes de {@see RoomSettingsBounds}, la
     * grâce et la marge de tirage de {@see PlatformLimits}, le décompte
     * d'{@see EngineConstants} — une borne ou une constante qui change change
     * la durée, donc la borne d'attente du drainage.
     */
    public static function maxNaturalDurationMs(): int
    {
        $playableRounds = RoomSettingsBounds::MAX_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin();
        $roundCycleMs = (RoomSettingsBounds::MAX_ROUND_DURATION + RoomSettingsBounds::MAX_REVEAL_DURATION) * self::MILLISECONDS_PER_SECOND;
        $launchCountdowns = self::LAUNCH_COUNTDOWNS + PlatformLimits::drawSubstituteMargin();

        return $playableRounds * $roundCycleMs
            + $playableRounds * PlatformLimits::tierGraceMs()
            + $launchCountdowns * EngineConstants::launchCountdownMs();
    }

    /**
     * Temps maximal qu'une partie passe en **pause manuelle**, décomptes de
     * reprise compris (D64 du 07/10) : le budget cumulé par partie,
     * `pauseTimeoutMs` ({@see PauseDeadline}). Le drainage l'ajoute à la durée
     * naturelle (100 § 11) : une pause manuelle déjà en cours, ou demandée
     * avant le drapeau, prolonge une partie au plus de ce budget — pendant le
     * drainage, aucune nouvelle demande n'est acceptée.
     */
    public static function manualPauseBudgetMs(): int
    {
        return EngineConstants::pauseTimeoutMs();
    }
}
