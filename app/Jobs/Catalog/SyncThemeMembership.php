<?php

namespace App\Jobs\Catalog;

use App\Models\Theme;
use App\Support\Catalog\ThemeEvaluator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * La réévaluation d'un thème sur tout le catalogue (spec 30 § 13.1-13.2,
 * D43 du 01/10).
 *
 * Dispatchée **après commit** par la création d'un thème, par le changement de
 * sa règle (`rule_value`, `rule_negated`) et par l'insertion d'un thème par
 * `PlatformDataSeeder` ; jamais sur la requête du curateur, jamais sur la file
 * `game` : réévaluer un thème touche tout le catalogue. File par défaut.
 * `ShouldQueueAfterCommit` porte la règle « après commit » sur la classe même :
 * un appelant qui l'oublierait ne ferait pas évaluer une règle encore
 * invisible au job.
 *
 * **Unique jusqu'au début du traitement, et non jusqu'à la fin**
 * (`ShouldBeUniqueUntilProcessing`) : avec `ShouldBeUnique`, une règle
 * corrigée pendant un passage enverrait un second job, jeté en silence, et
 * l'appartenance resterait calculée sur l'ancienne règle. Ici le verrou tombe
 * quand le traitement commence ; deux corrections rapprochées n'empilent au
 * plus qu'un job en attente, et {@see self::handle()} relit le thème en tête,
 * donc évalue la règle en vigueur à son démarrage.
 */
final class SyncThemeMembership implements ShouldBeUniqueUntilProcessing, ShouldQueueAfterCommit
{
    use Queueable;

    /** L'évaluation est idempotente, mais `catalog:themes` est le rattrapage d'un échec. */
    public int $tries = 1;

    /**
     * Durée de vie du verrou d'unicité, en secondes. Sans elle, un job poussé
     * puis jamais traité (file vidée à la main, worker perdu) garderait le
     * verrou pour toujours sur Redis, et toute correction ultérieure de la
     * règle de ce thème serait jetée en silence. Un doublon après expiration
     * est sans effet : l'évaluation est idempotente.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $themeId) {}

    /**
     * Un job par thème : deux thèmes différents se réévaluent en parallèle.
     */
    public function uniqueId(): string
    {
        return 'theme-sync-'.$this->themeId;
    }

    public function handle(ThemeEvaluator $evaluator): void
    {
        // Relu en tête : la règle évaluée est celle en vigueur au démarrage.
        // Garde défensive — le back-office n'offre aucune suppression de thème.
        $theme = Theme::query()->find($this->themeId);

        if (! $theme instanceof Theme) {
            return;
        }

        $evaluator->syncTheme($theme);
    }
}
