<?php

namespace App\Support\Retention;

use App\Enums\PurgeScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Le gestionnaire d'UN périmètre de purge — spec 100 § 14 et § 15.
 *
 * Il déclare ce qui est éligible et comment une ligne s'efface ; le moteur
 * ({@see RetentionPurger}) porte tout le reste, une seule fois pour tous les
 * périmètres : lots bornés, curseur, une transaction par ligne, compteur
 * d'échecs, ligne `purge_run`, suspension. Un gestionnaire ne supprime donc
 * jamais en masse et n'écrit jamais lui-même son journal.
 *
 * **Un seul prédicat.** `eligibleCount()`, `nextBatch()` et `purge()` lisent
 * la même condition d'éligibilité : la sonde n° 4 compte ce que le moteur
 * supprime, et une ligne redevenue non éligible entre sa sélection et son
 * effacement (une session reprise) n'est pas effacée.
 *
 * **Enregistrement** : chaque gestionnaire est déclaré dans
 * {@see PurgeHandlers::CLASSES} et étiqueté sous le nom de cette interface.
 * Le moteur n'exécute que les périmètres de `PurgeScope::implemented()`, et
 * chacun par un seul gestionnaire ; la sonde met en alerte un périmètre sans
 * gestionnaire ou servi par deux.
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

    /**
     * Le lot suivant : au plus `$size` lignes éligibles à `$now`, du plus
     * ancien au plus récent sur la colonne pilote, **strictement après**
     * `$after` (`null` = depuis le début).
     *
     * Le curseur avance au-delà d'une ligne en échec : un lot trié qui bute
     * sur un `restrict` ne resélectionne jamais la même ligne (10 § 11.3).
     *
     * @return list<PurgeRow>
     */
    public function nextBatch(CarbonImmutable $now, ?PurgeRow $after, int $size): array;

    /**
     * Efface UNE ligne, si elle est encore éligible à `$now`, et rend le
     * nombre de lignes traitées (0 si elle a disparu ou n'est plus éligible).
     * Appelé par le moteur dans une transaction ouverte sur {@see connection()}.
     */
    public function purge(PurgeRow $row, CarbonImmutable $now): int;

    /** La connexion qui porte la table : celle de la transaction de chaque ligne. */
    public function connection(): ConnectionInterface;
}
