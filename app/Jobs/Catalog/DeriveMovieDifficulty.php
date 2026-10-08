<?php

namespace App\Jobs\Catalog;

use App\Support\Catalog\MovieDifficultyDeriver;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * La dérivation de la difficulté de tout le catalogue (spec 30 § 14.2, lot
 * L30-10).
 *
 * File par défaut, jamais la file `game` ; sans argument, unique par classe.
 * Dispatchée **après commit** (`ShouldQueueAfterCommit`, et `DB::afterCommit()`
 * chez les appelants qui tiennent une transaction) : à la clôture d'un
 * `import_run` ou d'une resynchronisation, après la création d'un thème de
 * saga ou le changement de sa collection, après l'insertion d'une saga par
 * `PlatformDataSeeder`, et après toute transition d'`availability` vers ou
 * depuis `withdrawn`.
 *
 * **Unique jusqu'au début du traitement**, et non jusqu'à la fin — forme
 * livrée, amendé le 08/10 (même raison que `SyncThemeMembership`, § 13.1) :
 * avec `ShouldBeUnique`, un import clos pendant une dérivation enverrait un
 * second job, jeté en silence, et ses films resteraient sans difficulté
 * jusqu'au déclencheur suivant, qui peut ne pas venir. Ici, au plus un job
 * attend pendant qu'un autre tourne, et la dérivation relit la population à
 * son démarrage. Deux passages successifs sont sans effet l'un sur l'autre :
 * la dérivation est idempotente.
 *
 * Elle n'est pas une commande au sens de la règle 12 : elle appartient au
 * chemin d'import ordinaire et ne réécrit que des colonnes dérivées, jamais
 * `movie_difficulty_override` (spec 30 § 13.2).
 */
final class DeriveMovieDifficulty implements ShouldBeUniqueUntilProcessing, ShouldQueueAfterCommit
{
    use Queueable;

    /** Idempotente ; `catalog:themes` est le rattrapage d'un échec. */
    public int $tries = 1;

    /**
     * Durée de vie du verrou d'unicité : un job poussé puis perdu ne bloque
     * jamais les dérivations suivantes au-delà d'une heure.
     */
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'movie-difficulty-derivation';
    }

    public function handle(MovieDifficultyDeriver $deriver): void
    {
        $deriver->derive();
    }
}
