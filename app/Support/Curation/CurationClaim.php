<?php

namespace App\Support\Curation;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * La réservation souple d'un film par un curateur — spec 20 § 4.1, lot L20-32.
 *
 * Tenue **en cache, sans aucune colonne** : `curation:claim:{movieId}` →
 * identifiant du curateur, pour `catalog.curation.claim_minutes` minutes.
 * Prise à l'ouverture de l'éditeur et par « Film suivant », **prolongée par
 * chaque battement** (§ 10.1) ; sans battement, elle expire d'elle-même.
 *
 * **Souple** : elle n'interdit aucun geste. Elle sert à la file, qui saute
 * les films réservés par un autre curateur et l'affiche, pour que deux
 * curateurs n'enchaînent pas le même film. Une réservation tenue par un autre
 * n'est jamais volée : elle se libère en expirant.
 *
 * Seul lecteur de `catalog.curation.claim_minutes` : entier ≥ 1 et
 * strictement au-dessus de la cadence du battement (`claim_minutes × 60 >
 * heartbeat_seconds`) — sans quoi une réservation expirerait entre deux
 * battements d'un curateur au travail. Hors bornes : `InvalidArgumentException`.
 */
final class CurationClaim
{
    public const string KEY_PREFIX = 'curation:claim:';

    /** Durée du verrou d'une prise, en secondes. */
    private const int LOCK_SECONDS = 5;

    /** Attente maximale du verrou, en secondes. */
    private const int LOCK_WAIT_SECONDS = 2;

    /**
     * Prend ou prolonge la réservation d'un film ; rend `false`, sans rien
     * écrire, si un autre curateur la tient.
     */
    public static function claim(User $curator, int $movieId): bool
    {
        $key = self::key($movieId);
        $ttl = self::claimMinutes() * 60;

        try {
            return (bool) Cache::lock($key.':lock', self::LOCK_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                static function () use ($key, $ttl, $curator): bool {
                    $holder = self::read($key);

                    if ($holder !== null && $holder !== $curator->id) {
                        return false;
                    }

                    Cache::put($key, $curator->id, $ttl);

                    return true;
                },
            );
        } catch (LockTimeoutException) {
            // Une réservation n'est qu'une indication : sous un verrou qui ne
            // répond pas, on ne prend rien et on ne bloque rien.
            return false;
        }
    }

    /** L'identifiant du curateur qui réserve ce film, `null` s'il est libre. */
    public static function holder(int $movieId): ?int
    {
        return self::read(self::key($movieId));
    }

    /**
     * Les réservations d'une liste de films, en une lecture du cache.
     *
     * @param  list<int>  $movieIds
     * @return array<int, int> identifiant du film => identifiant du curateur
     */
    public static function holders(array $movieIds): array
    {
        if ($movieIds === []) {
            return [];
        }

        $keys = array_map(self::key(...), $movieIds);
        $values = Cache::many($keys);
        $holders = [];

        foreach ($movieIds as $index => $movieId) {
            $holder = self::normalize($values[$keys[$index]] ?? null);

            if ($holder !== null) {
                $holders[$movieId] = $holder;
            }
        }

        return $holders;
    }

    /** Le film est-il réservé par un AUTRE curateur que celui-ci ? */
    public static function heldByAnother(int $movieId, User $curator): bool
    {
        $holder = self::holder($movieId);

        return $holder !== null && $holder !== $curator->id;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function claimMinutes(): int
    {
        $minutes = config('catalog.curation.claim_minutes');
        $heartbeat = config('catalog.curation.heartbeat_seconds');

        if (! is_int($minutes) || $minutes < 1 || (is_int($heartbeat) && $minutes * 60 <= $heartbeat)) {
            throw new InvalidArgumentException('catalog.curation.claim_minutes doit être un entier ≥ 1, au-dessus de la cadence du battement.');
        }

        return $minutes;
    }

    public static function key(int $movieId): string
    {
        return self::KEY_PREFIX.$movieId;
    }

    private static function read(string $key): ?int
    {
        return self::normalize(Cache::get($key));
    }

    /**
     * Le store Redis rend un entier écrit en chaîne numérique : la valeur se
     * lit comme un entier écrit en chiffres, quel que soit son type PHP.
     */
    private static function normalize(mixed $value): ?int
    {
        $holder = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($holder) ? $holder : null;
    }
}
