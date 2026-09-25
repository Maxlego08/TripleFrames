<?php

namespace App\Support\Catalog;

use Closure;
use Illuminate\Support\Facades\App;

/**
 * La garde d'instantané des imports lancés à la main — règle 12, spec 100
 * § 11.4 et § 13.1, exigence adressée à la spec 20 (I-9).
 *
 * `catalog:import-discover` et `catalog:import-ids` (`--resync` compris, qui
 * supprime puis réécrit les titres et alias d'origine TMDB) écrivent `movie`,
 * `movie_title` et `alias`. **Lancées à la main hors `local` et `testing`**,
 * elles appellent donc `backup:snapshot` avant leur première écriture et
 * s'arrêtent sans rien écrire sur un code non nul.
 *
 * **Deux dispenses, et deux seulement :**
 *
 * - **la simulation** (`--dry-run`, `--preview`) n'écrit rien, pas même une
 *   ligne `import_run` : il n'y a rien à protéger ;
 * - **le chemin d'import ordinaire** : le job `RunCatalogImport` du
 *   back-office n'est ni une commande ni un script au sens de la règle 12
 *   (spec 30 § 13.2) — un vidage complet par balayage placerait un geste
 *   d'exploitation sur le chemin du curateur (D10 du 23/09), et la curation,
 *   qu'il ne détruit pas, reste couverte par le tier chaud.
 *
 * **Le moyen de la dispense est un état de PROCESSUS, jamais une option de
 * commande** : une option se taperait à la main et contournerait la garde
 * depuis le terminal même qu'elle protège. Le job enveloppe son appel dans
 * {@see self::ordinaryPath()}, que rien ne peut invoquer depuis une ligne de
 * commande ; l'état est rendu dans un `finally`, si bien qu'un worker qui
 * enchaîne les jobs ne le garde jamais au-delà de l'appel.
 */
final class ImportSnapshotGuard
{
    /** Profondeur d'imbrication du chemin ordinaire en cours, jamais négative. */
    private static int $ordinaryDepth = 0;

    /**
     * Exécute `$callback` sur le chemin d'import ordinaire : la garde y est
     * levée, et rétablie quoi qu'il arrive.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function ordinaryPath(Closure $callback): mixed
    {
        self::$ordinaryDepth++;

        try {
            return $callback();
        } finally {
            self::$ordinaryDepth = max(0, self::$ordinaryDepth - 1);
        }
    }

    /** Vrai pendant un appel enveloppé par {@see self::ordinaryPath()}. */
    public static function onOrdinaryPath(): bool
    {
        return self::$ordinaryDepth > 0;
    }

    /**
     * Une commande d'import doit-elle prendre un instantané avant d'écrire ?
     *
     * Non en simulation, non sur le chemin ordinaire, non en `local` ni en
     * `testing` ; oui partout ailleurs — production comprise, et tout
     * environnement inconnu, qui n'est jamais présumé jetable.
     */
    public static function required(bool $simulation): bool
    {
        if ($simulation || self::onOrdinaryPath()) {
            return false;
        }

        return ! App::environment(['local', 'testing']);
    }
}
