<?php

namespace App\Support\Admin;

use App\Support\Catalog\ImportDecision;
use App\Support\Catalog\ImportOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * L'aperçu à blanc d'un collage — spec 20 § 3.3 (n° 8, `questions-ouvertes.md`
 * « Import par lot »).
 *
 * **Un résultat en cache, jamais une ligne en base.** L'aperçu passe par la
 * commande d'import en simulation (`catalog:import-ids --preview=<jeton>`), qui
 * n'ouvre aucune ligne `import_run` : l'historique des balayages ne contient
 * que des imports réels, et le chiffre opposable des refus de contenu reste
 * `import_run.total_refused_content`, écrit par l'import réel. Ce qui est tenu
 * ici est **indicatif** : « Importer ces films » ouvre ensuite un collage réel
 * qui rejoue toutes les gardes.
 *
 * **Clé `admin:paste-preview:{userId}:{jeton}`** : l'identifiant de l'auteur
 * est DANS la clé, si bien qu'un autre compte ne lit jamais cet aperçu, même
 * jeton en main. Le jeton, `bin2hex(random_bytes(16))`, distingue deux aperçus
 * successifs du même auteur : le job d'un aperçu remplacé écrit sous sa propre
 * clé, jamais sous celle du suivant. Un pointeur par auteur
 * (`admin:paste-preview:{userId}:latest`) désigne le dernier ouvert, que
 * l'écran d'import affiche et sonde.
 *
 * **Durée de vie fixée à l'ouverture** (`catalog.import.preview_ttl_minutes`) :
 * chaque écriture du job reprend l'échéance d'origine, jamais une échéance
 * glissante — un aperçu n'est pas prolongé par son propre calcul.
 *
 * Des tableaux et jamais des objets : le cache ne désérialise aucune classe
 * (`cache.serializable_classes`). Chaque lecture revalide la forme : une
 * entrée hors contrat vaut une entrée absente.
 *
 * @phpstan-type PreviewRow array{tmdb_id: int, decision: string, title_original: string|null, release_year: int|null, motives: list<string>, is_import_exception: bool, reason_key: string|null, reason_replacements: array<string, string|int>, movie_id: int|null, availability: string|null}
 * @phpstan-type PreviewState array{token: string, status: string, identifiers: list<int>, rows: list<PreviewRow>, error_key: string|null, expires_at: string}
 */
final class PastePreview
{
    /** Préfixe de toutes les clés d'aperçu. */
    public const string CACHE_PREFIX = 'admin:paste-preview:';

    /** Suffixe du pointeur vers le dernier aperçu d'un auteur — jamais un jeton, qui est hexadécimal. */
    private const string LATEST = 'latest';

    /** En file : aucun identifiant encore lu. */
    public const string PENDING = 'pending';

    /** La commande lit les fiches, une à une. */
    public const string RUNNING = 'running';

    /** Chaque identifiant a son sort. */
    public const string COMPLETED = 'completed';

    /** Interrompu : les lignes déjà lues restent, l'écran propose « Réessayer ». */
    public const string FAILED = 'failed';

    /** Motif générique d'un aperçu interrompu. */
    public const string FAILED_KEY = 'admin.import.preview.failed';

    private const int TOKEN_BYTES = 16;

    private const string TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

    /**
     * Ouvre un aperçu en file et en fait le dernier de son auteur ; rend son
     * jeton.
     *
     * @param  list<int>  $identifiers  le collage, dans l'ordre du curateur
     */
    public static function open(int $userId, array $identifiers): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $expiresAt = CarbonImmutable::now()->addMinutes(self::ttlMinutes());

        $state = [
            'token' => $token,
            'status' => self::PENDING,
            'identifiers' => $identifiers,
            'rows' => [],
            'error_key' => null,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        Cache::put(self::key($userId, $token), $state, $expiresAt);
        Cache::put(self::CACHE_PREFIX.$userId.':'.self::LATEST, $token, $expiresAt);

        return $token;
    }

    /** La clé d'un aperçu : son auteur, puis son jeton. */
    public static function key(int $userId, string $token): string
    {
        return self::CACHE_PREFIX.$userId.':'.$token;
    }

    /** Un jeton d'aperçu bien formé : 32 caractères hexadécimaux. */
    public static function isToken(string $candidate): bool
    {
        return preg_match(self::TOKEN_PATTERN, $candidate) === 1;
    }

    /**
     * L'aperçu `$token` de `$userId`, ou `null` s'il n'existe pas, a expiré ou
     * appartient à un autre compte.
     *
     * @return PreviewState|null
     */
    public static function find(int $userId, string $token): ?array
    {
        if (! self::isToken($token)) {
            return null;
        }

        return self::normalize(Cache::get(self::key($userId, $token)));
    }

    /**
     * Le dernier aperçu ouvert par `$userId`, ou `null`.
     *
     * @return PreviewState|null
     */
    public static function latest(int $userId): ?array
    {
        $token = Cache::get(self::CACHE_PREFIX.$userId.':'.self::LATEST);

        return is_string($token) ? self::find($userId, $token) : null;
    }

    /** La commande a commencé à lire les fiches. */
    public static function start(int $userId, string $token): void
    {
        self::update($userId, $token, static function (array $state): array {
            $state['status'] = self::RUNNING;

            return $state;
        });
    }

    /**
     * Ajoute le sort d'un identifiant, dans l'ordre de lecture.
     *
     * @param  PreviewRow  $row
     */
    public static function record(int $userId, string $token, array $row): void
    {
        self::update($userId, $token, static function (array $state) use ($row): array {
            $state['status'] = self::RUNNING;
            $state['rows'][] = $row;

            return $state;
        });
    }

    /** Chaque identifiant a son sort. */
    public static function complete(int $userId, string $token): void
    {
        self::update($userId, $token, static function (array $state): array {
            $state['status'] = self::COMPLETED;

            return $state;
        });
    }

    /**
     * Interrompt l'aperçu avec un motif — une clé du domaine `admin`, jamais
     * un message brut. Un aperçu déjà terminé n'est jamais réécrit.
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
     * Le sort d'un identifiant, en DONNÉES (règle 4) : la décision, la clé du
     * motif et ses substitutions, jamais une phrase. `$title` et `$year`
     * viennent de la fiche TMDB lue, ou du film déjà au catalogue.
     *
     * @return PreviewRow
     */
    public static function row(ImportOutcome $outcome, ?string $title, ?int $year): array
    {
        $motives = [];

        if ($outcome->motives !== null) {
            if ($outcome->motives->forLanguage) {
                $motives[] = 'language';
            }

            if ($outcome->motives->forVoteCount) {
                $motives[] = 'vote_count';
            }

            if ($outcome->motives->forReleaseYear) {
                $motives[] = 'release_year';
            }
        }

        $movie = $outcome->movie;

        return [
            'tmdb_id' => $outcome->tmdbId,
            'decision' => $outcome->decision->value,
            'title_original' => $title ?? $movie?->title_original,
            'release_year' => $year ?? $movie?->release_year,
            'motives' => $motives,
            'is_import_exception' => $outcome->isImportException,
            'reason_key' => $outcome->reasonKey,
            'reason_replacements' => $outcome->reasonReplacements,
            'movie_id' => $movie?->id,
            'availability' => $movie?->availability->value,
        ];
    }

    /**
     * Les props de l'écran d'import : l'état, l'avancement, les lignes.
     *
     * @param  PreviewState  $state
     * @return array{token: string, status: string, identifiers: list<int>, total: int, processed: int, rows: list<PreviewRow>, error_key: string|null, expires_at: string}
     */
    public static function toProps(array $state): array
    {
        return [
            'token' => $state['token'],
            'status' => $state['status'],
            'identifiers' => $state['identifiers'],
            'total' => count($state['identifiers']),
            'processed' => count($state['rows']),
            'rows' => $state['rows'],
            'error_key' => $state['error_key'],
            'expires_at' => $state['expires_at'],
        ];
    }

    /**
     * Lit, transforme et réécrit un aperçu sous son échéance d'ORIGINE. Un
     * aperçu absent ou expiré reste absent : le job d'un aperçu perdu n'en
     * recrée jamais un.
     *
     * @param  callable(PreviewState): PreviewState  $change
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

    /** Durée de vie d'un aperçu, en minutes, jamais moins d'une. */
    private static function ttlMinutes(): int
    {
        return max(1, Config::integer('catalog.import.preview_ttl_minutes', 60));
    }

    /**
     * Revalide une entrée lue du cache.
     *
     * @return PreviewState|null
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

        if (! is_string($token) || ! is_string($status) || ! is_string($expiresAt)) {
            return null;
        }

        if (! in_array($status, [self::PENDING, self::RUNNING, self::COMPLETED, self::FAILED], true)) {
            return null;
        }

        $identifiers = [];

        foreach (is_array($raw['identifiers'] ?? null) ? $raw['identifiers'] : [] as $identifier) {
            if (is_int($identifier)) {
                $identifiers[] = $identifier;
            }
        }

        $rows = [];

        foreach (is_array($raw['rows'] ?? null) ? $raw['rows'] : [] as $row) {
            $normalized = is_array($row) ? self::normalizeRow($row) : null;

            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        return [
            'token' => $token,
            'status' => $status,
            'identifiers' => $identifiers,
            'rows' => $rows,
            'error_key' => is_string($errorKey) ? $errorKey : null,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return PreviewRow|null
     */
    private static function normalizeRow(array $row): ?array
    {
        $tmdbId = $row['tmdb_id'] ?? null;
        $decision = $row['decision'] ?? null;

        if (! is_int($tmdbId) || ! is_string($decision) || ImportDecision::tryFrom($decision) === null) {
            return null;
        }

        $motives = [];

        foreach (is_array($row['motives'] ?? null) ? $row['motives'] : [] as $motive) {
            if (is_string($motive)) {
                $motives[] = $motive;
            }
        }

        $replacements = [];

        foreach (is_array($row['reason_replacements'] ?? null) ? $row['reason_replacements'] : [] as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $replacements[$name] = $value;
            }
        }

        $title = $row['title_original'] ?? null;
        $year = $row['release_year'] ?? null;
        $reasonKey = $row['reason_key'] ?? null;
        $movieId = $row['movie_id'] ?? null;
        $availability = $row['availability'] ?? null;

        return [
            'tmdb_id' => $tmdbId,
            'decision' => $decision,
            'title_original' => is_string($title) ? $title : null,
            'release_year' => is_int($year) ? $year : null,
            'motives' => $motives,
            'is_import_exception' => ($row['is_import_exception'] ?? false) === true,
            'reason_key' => is_string($reasonKey) ? $reasonKey : null,
            'reason_replacements' => $replacements,
            'movie_id' => is_int($movieId) ? $movieId : null,
            'availability' => is_string($availability) ? $availability : null,
        ];
    }
}
