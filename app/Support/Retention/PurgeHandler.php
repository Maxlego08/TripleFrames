<?php

namespace App\Support\Retention;

use App\Enums\PurgeScope;
use Carbon\CarbonImmutable;

/**
 * Le gestionnaire d'UN périmètre de purge, tel que la sonde `purge` le lit —
 * spec 100 § 14 et § 15.
 *
 * Contrat minimal posé par le premier temps de L100-7, dont la sonde n° 4 de
 * 10 § 11.3 lit `eligibleCount()` ; les gestionnaires eux-mêmes, leur
 * exécution par `RetentionPurger` et `PurgeScope::implemented()` arrivent
 * avec L100-8, qui étend ce contrat au geste de suppression.
 *
 * **Enregistrement** : chaque gestionnaire est étiqueté dans le conteneur
 * sous le nom de cette interface (`$app->tag([...], PurgeHandler::class)`).
 * La sonde parcourt `PurgeScope::implemented()` ET les périmètres des
 * gestionnaires étiquetés : un périmètre déclaré sans gestionnaire, ou servi
 * par deux gestionnaires, est en alerte.
 */
interface PurgeHandler
{
    /** Le périmètre servi, un seul par gestionnaire. */
    public function scope(): PurgeScope;

    /**
     * Le compte des lignes éligibles, **par le prédicat même que le
     * gestionnaire supprime** : deux prédicats écrits à deux endroits
     * divergeraient (spec 100 § 14).
     *
     * `$asOf` évalue ce prédicat à un instant passé — les lignes qui étaient
     * déjà éligibles à cet instant et existent encore ; `null` = maintenant.
     * La sonde le pose au début de la dernière exécution terminée : une ligne
     * devenue éligible APRÈS elle n'est pas un signe de blocage, seulement le
     * travail de la nuit suivante. Sans ce décalage, un périmètre creux
     * (`failed_jobs`, jetons de réinitialisation) mettrait la sonde en alerte
     * à chaque ligne qui échoit entre deux nuits.
     */
    public function eligibleCount(?CarbonImmutable $asOf = null): int;
}
