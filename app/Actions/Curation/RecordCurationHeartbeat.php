<?php

namespace App\Actions\Curation;

use App\Enums\ContentAvailability;
use App\Enums\ImportSource;
use App\Models\Movie;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Le battement de débit — spec 20 § 10.1, décision 10 : le temps ACTIF de
 * curation d'un film, `movie.curation_active_seconds`, le seul qui capte la
 * recherche d'une image.
 *
 * **La règle serveur.** L'instant du dernier battement vit en cache sous
 * `curation:beat:{userId}:{movieId}` — aucune colonne. Chaque battement le lit
 * puis le réécrit : un écart d'au plus `catalog.curation.idle_seconds`
 * secondes s'ajoute au temps actif ; un écart plus long, ou un premier
 * battement, n'ajoute rien. Combinée à la règle client — un battement n'est
 * posté que si une saisie a eu lieu depuis le tick précédent
 * (`resources/js/lib/admin/heartbeat-gate.ts`) —, elle exclut EN ENTIER toute
 * pause de plus de `idle_seconds` et compte en entier toute pause plus courte.
 *
 * **Deux onglets ne doublent pas le temps** : ils partagent la clé, si bien que
 * chaque écart est mesuré depuis le battement précédent, quel que soit
 * l'onglet qui l'a posté. Deux battements concurrents — deux onglets au même
 * instant — sont sérialisés par un verrou de cache NON bloquant : le second,
 * qui n'ajouterait qu'un écart nul, est simplement abandonné.
 *
 * **Mesure de la passe 1, figée à la terminaison** (§ 10.1, B14) : l'incrément
 * ne touche qu'un film réel qui n'a jamais été publié ni écarté —
 * `availability = draft` ET `first_published_at` NULL (EN20-1). La condition
 * est portée par l'`UPDATE` lui-même, atomique : une publication concurrente
 * n'est jamais suivie d'un incrément. Un film de démonstration n'est jamais
 * compté (§ 10.2).
 *
 * **N'écrit que `movie.curation_active_seconds`** : l'incrément passe par le
 * constructeur de requêtes, sans `updated_at` — le battement n'est pas une
 * retouche du film (la file de curation lit, elle, `frame.updated_at`).
 */
final class RecordCurationHeartbeat
{
    /** Préfixe de la clé de cache du dernier battement. */
    public const string KEY_PREFIX = 'curation:beat:';

    /**
     * Durée du verrou qui sérialise deux battements concurrents, en secondes :
     * bien plus que la durée d'un battement, bien moins que sa cadence.
     */
    private const int LOCK_SECONDS = 5;

    /**
     * Enregistre un battement ; rend le nombre de secondes ajoutées au temps
     * actif du film — zéro pour un premier battement, une pause trop longue,
     * un battement concurrent, ou un film hors de la mesure.
     */
    public function handle(User $user, Movie $movie, CarbonImmutable $now): int
    {
        if (! self::measured($movie)) {
            return 0;
        }

        $key = self::key($user, $movie);
        $lock = Cache::lock($key.':lock', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $idleSeconds = Config::integer('catalog.curation.idle_seconds');
            // Le store Redis de la production écrit un entier SANS le
            // sérialiser et le relit en chaîne numérique, jamais en `int` :
            // la valeur se lit donc comme un entier écrit en chiffres, quel
            // que soit son type PHP — même piège que `Ops\Heartbeat::lastBeatAt()`.
            // Un `is_int` rendrait un écart nul à chaque battement, et un p90
            // du pilote nul.
            $previous = filter_var(Cache::get($key), FILTER_VALIDATE_INT);
            $beatAt = $now->getTimestamp();

            // Au-delà de la fenêtre, la clé n'a plus d'usage : un écart plus
            // long n'ajoute rien, qu'on le mesure ou non.
            Cache::put($key, $beatAt, 2 * $idleSeconds);

            $gap = $previous === false ? 0 : $beatAt - $previous;

            if ($gap <= 0 || $gap > $idleSeconds) {
                return 0;
            }

            $updated = Movie::query()
                ->whereKey($movie->id)
                ->where('availability', ContentAvailability::Draft->value)
                ->whereNull('first_published_at')
                ->where('import_source', '<>', ImportSource::Demo->value)
                ->toBase()
                ->increment('curation_active_seconds', $gap);

            return $updated > 0 ? $gap : 0;
        } finally {
            $lock->release();
        }
    }

    /** La clé de cache du dernier battement d'un curateur sur un film. */
    public static function key(User $user, Movie $movie): string
    {
        return self::KEY_PREFIX.$user->id.':'.$movie->id;
    }

    /**
     * Le film est-il encore dans la mesure de la passe 1 ? Lecture de
     * l'instance, pour s'épargner le cache : l'`UPDATE` conditionnel reste la
     * seule garde qui fasse foi.
     */
    private static function measured(Movie $movie): bool
    {
        return $movie->import_source !== ImportSource::Demo
            && $movie->availability === ContentAvailability::Draft
            && $movie->first_published_at === null;
    }
}
