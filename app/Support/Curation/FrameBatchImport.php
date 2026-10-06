<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\PastePreview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * L'import d'un lot d'images dans le back-office — spec 20 § 5.10, D57 du
 * 05/10 : aperçu à blanc, puis import par la file `default`, avancement
 * sondé par l'écran.
 *
 * L'état vit **en cache**, sous la clé de son auteur et d'un jeton
 * `bin2hex(random_bytes(16))`, comme l'aperçu d'un collage
 * ({@see PastePreview}) : aucune table, aucune ligne qui
 * survive à son échéance. Ce qui reste d'un import, ce sont les images
 * qu'il a ajoutées, chacune avec sa ligne `frame.added` au journal.
 *
 * Une ligne par film du lot, calculée à l'aperçu sur la base seule (aucun
 * appel TMDB) :
 *
 * - `ready` : le film est au catalogue et sa banque peut grossir ;
 * - `missing` : aucun film de cet identifiant TMDB — l'écran propose de
 *   l'importer par le collage existant (§ 3.3), puis de recharger le lot ;
 * - `locked` : film suspendu ou retiré, dont la banque ne grossit pas
 *   (`FramePolicy::create`).
 *
 * `known` compte les images du lot qui n'entreront pas : déjà présentes
 * dans la banque — même visuel, même cadre, quel que soit leur état, écartées
 * comprises — ou d'un visuel suspendu ou retiré. Aucune n'est téléchargée.
 *
 * @phpstan-type BatchRow array{tmdb_id: int, title: string|null, movie_id: int|null, status: string, frames: int, known: int, added: int, skipped: int, refused: list<string>, done: bool}
 * @phpstan-type BatchState array{token: string, status: string, batch: array<string, mixed>, rows: list<BatchRow>, error_key: string|null, expires_at: string}
 */
final class FrameBatchImport
{
    public const string CACHE_PREFIX = 'admin:frame-batch:';

    private const string LATEST = 'latest';

    /** Aperçu à blanc rendu ; rien n'est encore importé. */
    public const string PREVIEWED = 'previewed';

    /** Import confié à la file, pas encore commencé. */
    public const string PENDING = 'pending';

    /** Import en cours, film par film. */
    public const string RUNNING = 'running';

    /** Chaque film du lot a son sort. */
    public const string COMPLETED = 'completed';

    /** Interrompu : les films déjà traités le restent. */
    public const string FAILED = 'failed';

    public const string STATUS_READY = 'ready';

    public const string STATUS_MISSING = 'missing';

    public const string STATUS_LOCKED = 'locked';

    public const string FAILED_KEY = 'admin.frame_batch.failed';

    private const int TOKEN_BYTES = 16;

    private const string TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

    /**
     * Ouvre un lot à l'aperçu, en fait le dernier de son auteur, et rend son
     * jeton.
     */
    public static function open(User $user, FrameBatch $batch): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $expiresAt = CarbonImmutable::now()->addMinutes(self::ttlMinutes());

        $state = [
            'token' => $token,
            'status' => self::PREVIEWED,
            'batch' => $batch->toArray(),
            'rows' => self::preview($user, $batch),
            'error_key' => null,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        Cache::put(self::key($user->id, $token), $state, $expiresAt);
        Cache::put(self::CACHE_PREFIX.$user->id.':'.self::LATEST, $token, $expiresAt);

        return $token;
    }

    /**
     * L'aperçu à blanc d'un lot : une ligne par film, sur la base seule.
     *
     * @return list<BatchRow>
     */
    public static function preview(User $user, FrameBatch $batch): array
    {
        $tmdbIds = array_map(static fn (array $movie): int => $movie['tmdb_id'], $batch->movies);

        /** @var array<int, Movie> $movies */
        $movies = Movie::query()
            ->whereIn('tmdb_id', $tmdbIds)
            ->with('frames')
            ->get()
            ->keyBy('tmdb_id')
            ->all();

        $rows = [];

        foreach ($batch->movies as $entry) {
            $movie = $movies[$entry['tmdb_id']] ?? null;
            $known = 0;

            if ($movie !== null) {
                foreach ($entry['frames'] as $frame) {
                    if (self::isKnown($movie, $frame['tmdb_file_path'], $frame['crop']?->toArray())) {
                        $known++;
                    }
                }
            }

            $rows[] = [
                'tmdb_id' => $entry['tmdb_id'],
                'title' => $movie !== null ? $movie->title_original : $entry['title'],
                'movie_id' => $movie?->id,
                'status' => match (true) {
                    $movie === null => self::STATUS_MISSING,
                    ! Gate::forUser($user)->allows('create', [Frame::class, $movie]) => self::STATUS_LOCKED,
                    default => self::STATUS_READY,
                },
                'frames' => count($entry['frames']),
                'known' => $known,
                'added' => 0,
                'skipped' => 0,
                'refused' => [],
                'done' => false,
            ];
        }

        return $rows;
    }

    /**
     * Vrai si la banque du film porte déjà ce visuel sous ce cadre — ou, sans
     * cadre, ce visuel sous un cadre quelconque —, **quel que soit son état**,
     * ou si ce visuel y est bloqué ({@see self::isBlocked()}). La relation
     * `frames` doit être chargée.
     *
     * Plus strict que le dédoublonnage de l'ajout unitaire (`AddFrame`, qui
     * laisse réajouter une image écartée) : un lot se redépose à volonté — pour
     * rattraper les films d'abord absents, par exemple —, et ne doit jamais
     * ressusciter une image que le curateur a écartée ou dépubliée.
     *
     * @param  array{x: int, y: int, width: int, height: int}|null  $crop
     */
    public static function isKnown(Movie $movie, string $filePath, ?array $crop): bool
    {
        if (self::isBlocked($movie, $filePath)) {
            return true;
        }

        foreach ($movie->frames as $frame) {
            if ($frame->tmdb_file_path !== $filePath) {
                continue;
            }

            if ($crop === null
                || ($frame->crop_x === $crop['x'] && $frame->crop_y === $crop['y']
                    && $frame->crop_width === $crop['width'] && $frame->crop_height === $crop['height'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vrai si une image de ce visuel, sous n'importe quel cadre, est
     * suspendue ou retirée : un lot ne la fait jamais revenir sous un autre
     * cadre (retrait juridique, suspension conservatoire, spec 20 § 11). La
     * relation `frames` doit être chargée.
     */
    public static function isBlocked(Movie $movie, string $filePath): bool
    {
        foreach ($movie->frames as $frame) {
            if ($frame->tmdb_file_path === $filePath
                && in_array($frame->availability, [ContentAvailability::Suspended, ContentAvailability::Withdrawn], true)) {
                return true;
            }
        }

        return false;
    }

    public static function key(int $userId, string $token): string
    {
        return self::CACHE_PREFIX.$userId.':'.$token;
    }

    public static function isToken(string $candidate): bool
    {
        return preg_match(self::TOKEN_PATTERN, $candidate) === 1;
    }

    /**
     * Le lot `$token` de `$userId`, ou `null` s'il n'existe pas, a expiré ou
     * appartient à un autre compte.
     *
     * @return BatchState|null
     */
    public static function find(int $userId, string $token): ?array
    {
        if (! self::isToken($token)) {
            return null;
        }

        return self::normalize(Cache::get(self::key($userId, $token)));
    }

    /**
     * Le dernier lot ouvert par `$userId`, ou `null`.
     *
     * @return BatchState|null
     */
    public static function latest(int $userId): ?array
    {
        $token = Cache::get(self::CACHE_PREFIX.$userId.':'.self::LATEST);

        return is_string($token) ? self::find($userId, $token) : null;
    }

    /** Le lot est confié à la file. */
    public static function queue(int $userId, string $token): void
    {
        self::update($userId, $token, static function (array $state): array {
            $state['status'] = self::PENDING;

            return $state;
        });
    }

    /**
     * La commande a commencé, ou reprend un passage. Le lot vit de nouveau
     * toute sa durée à compter de cet instant : un import en plusieurs
     * passages ne doit jamais perdre son état en route (amendé le 06/10).
     */
    public static function start(int $userId, string $token): void
    {
        self::update($userId, $token, static function (array $state): array {
            $state['status'] = self::RUNNING;
            $state['expires_at'] = CarbonImmutable::now()->addMinutes(self::ttlMinutes())->toIso8601String();

            return $state;
        });

        $state = self::find($userId, $token);

        if ($state !== null) {
            Cache::put(self::CACHE_PREFIX.$userId.':'.self::LATEST, $token, CarbonImmutable::parse($state['expires_at']));
        }
    }

    /**
     * Le sort d'un film du lot.
     *
     * @param  list<string>  $refused  les motifs traduits des images refusées
     */
    public static function record(int $userId, string $token, int $tmdbId, int $added, int $skipped, array $refused): void
    {
        self::update($userId, $token, static function (array $state) use ($tmdbId, $added, $skipped, $refused): array {
            foreach ($state['rows'] as $index => $row) {
                if ($row['tmdb_id'] === $tmdbId) {
                    $state['rows'][$index]['added'] = $added;
                    $state['rows'][$index]['skipped'] = $skipped;
                    $state['rows'][$index]['refused'] = $refused;
                    $state['rows'][$index]['done'] = true;
                }
            }

            return $state;
        });
    }

    public static function complete(int $userId, string $token): void
    {
        self::update($userId, $token, static function (array $state): array {
            $state['status'] = self::COMPLETED;

            return $state;
        });
    }

    /**
     * Interrompt l'import avec un motif — une clé du domaine `admin`. Un lot
     * déjà terminé n'est jamais réécrit.
     */
    public static function fail(int $userId, string $token, string $errorKey = self::FAILED_KEY): void
    {
        self::update($userId, $token, static function (array $state) use ($errorKey): array {
            if (in_array($state['status'], [self::COMPLETED, self::FAILED], true)) {
                return $state;
            }

            $state['status'] = self::FAILED;
            $state['error_key'] = $errorKey;

            return $state;
        });
    }

    /**
     * Les props de l'écran : l'état et les lignes, sans le lot lui-même.
     *
     * @param  BatchState  $state
     * @return array{token: string, status: string, rows: list<BatchRow>, error_key: string|null, expires_at: string}
     */
    public static function toProps(array $state): array
    {
        return [
            'token' => $state['token'],
            'status' => $state['status'],
            'rows' => $state['rows'],
            'error_key' => $state['error_key'],
            'expires_at' => $state['expires_at'],
        ];
    }

    /**
     * @param  callable(BatchState): BatchState  $change
     */
    private static function update(int $userId, string $token, callable $change): void
    {
        $state = self::find($userId, $token);

        if ($state === null) {
            return;
        }

        $state = $change($state);

        try {
            $expiresAt = CarbonImmutable::parse($state['expires_at']);
        } catch (Throwable) {
            return;
        }

        if ($expiresAt->isPast()) {
            Cache::forget(self::key($userId, $token));

            return;
        }

        Cache::put(self::key($userId, $token), $state, $expiresAt);
    }

    /** Durée de vie d'un lot, en minutes, jamais moins d'une. */
    private static function ttlMinutes(): int
    {
        return max(1, Config::integer('catalog.import.preview_ttl_minutes', 60));
    }

    /**
     * Revalide une entrée lue du cache.
     *
     * @return BatchState|null
     */
    private static function normalize(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $token = $raw['token'] ?? null;
        $status = $raw['status'] ?? null;
        $expiresAt = $raw['expires_at'] ?? null;
        $errorKey = $raw['error_key'] ?? null;
        $batch = $raw['batch'] ?? null;

        if (! is_string($token) || ! is_string($status) || ! is_string($expiresAt) || ! is_array($batch)) {
            return null;
        }

        if (! in_array($status, [self::PREVIEWED, self::PENDING, self::RUNNING, self::COMPLETED, self::FAILED], true)) {
            return null;
        }

        $rows = [];

        foreach (is_array($raw['rows'] ?? null) ? $raw['rows'] : [] as $row) {
            $normalized = is_array($row) ? self::normalizeRow($row) : null;

            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        /** @var array<string, mixed> $batch */
        return [
            'token' => $token,
            'status' => $status,
            'batch' => $batch,
            'rows' => $rows,
            'error_key' => is_string($errorKey) ? $errorKey : null,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return BatchRow|null
     */
    private static function normalizeRow(array $row): ?array
    {
        $tmdbId = $row['tmdb_id'] ?? null;
        $status = $row['status'] ?? null;
        $title = $row['title'] ?? null;
        $movieId = $row['movie_id'] ?? null;

        if (! is_int($tmdbId) || ! is_string($status)
            || ! in_array($status, [self::STATUS_READY, self::STATUS_MISSING, self::STATUS_LOCKED], true)) {
            return null;
        }

        $refused = [];

        foreach (is_array($row['refused'] ?? null) ? $row['refused'] : [] as $message) {
            if (is_string($message)) {
                $refused[] = $message;
            }
        }

        return [
            'tmdb_id' => $tmdbId,
            'title' => is_string($title) ? $title : null,
            'movie_id' => is_int($movieId) ? $movieId : null,
            'status' => $status,
            'frames' => is_int($row['frames'] ?? null) ? $row['frames'] : 0,
            'known' => is_int($row['known'] ?? null) ? $row['known'] : 0,
            'added' => is_int($row['added'] ?? null) ? $row['added'] : 0,
            'skipped' => is_int($row['skipped'] ?? null) ? $row['skipped'] : 0,
            'refused' => $refused,
            'done' => ($row['done'] ?? false) === true,
        ];
    }
}
