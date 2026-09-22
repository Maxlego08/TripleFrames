<?php

namespace App\Support\Tmdb;

use Illuminate\Support\Facades\Config;

/**
 * Les réglages d'accès à TMDB, lus une fois et typés — jamais du `mixed`.
 *
 * **Le jeton v4 prime sur la clé v3, et c'est une décision de sécurité.** Le
 * jeton voyage en en-tête `Authorization: Bearer` ; la clé v3 voyage en
 * paramètre d'URL, donc dans les journaux d'accès de tout intermédiaire, dans
 * le `Referer` d'une image, et dans le message de toute exception Guzzle non
 * filtrée. Les deux restent acceptées — un dépôt existant peut n'avoir que la
 * clé — mais {@see self::usesToken()} dit laquelle est employée.
 *
 * **Une variable vide vaut une variable absente.** `composer setup` copie
 * `.env.example`, où les deux lignes sont présentes et vides : `env()` rend
 * alors `''` et non `null`. Sans ce repli, `isConfigured()` serait vrai sur une
 * installation neuve et tout appel partirait sans authentification.
 *
 * Les valeurs d'URL, de délais et de tentatives vivent dans `config/services.php`
 * et non ici : ce sont des réglages d'exploitation, surchargeables en test par
 * `Config::set()`.
 */
final readonly class TmdbConfig
{
    /** Préfixe de configuration, unique domicile des valeurs d'exploitation. */
    private const string CONFIG_PREFIX = 'services.tmdb.';

    public function __construct(
        private ?string $token,
        private ?string $apiKey,
        public string $baseUrl,
        public string $imageBaseUrl,
        public string $language,
        public int $timeoutSeconds,
        public int $connectTimeoutSeconds,
        public int $retryTimes,
        public int $retryBaseDelayMs,
        public int $maxBackoffMs,
        public int $maxRetryAfterSeconds,
    ) {}

    /**
     * Instance construite depuis `config/services.php`.
     */
    public static function fromConfig(): self
    {
        return new self(
            token: self::secret('read_access_token'),
            apiKey: self::secret('api_key'),
            baseUrl: rtrim(self::text('base_url', 'https://api.themoviedb.org/3'), '/'),
            imageBaseUrl: rtrim(self::text('image_base_url', 'https://image.tmdb.org/t/p'), '/'),
            language: self::text('language', 'en-US'),
            timeoutSeconds: self::integer('timeout', 10),
            connectTimeoutSeconds: self::integer('connect_timeout', 5),
            retryTimes: max(1, self::integer('retry_times', 3)),
            retryBaseDelayMs: max(0, self::integer('retry_base_delay_ms', 500)),
            maxBackoffMs: max(0, self::integer('max_backoff_ms', 8_000)),
            maxRetryAfterSeconds: max(1, self::integer('max_retry_after_seconds', 60)),
        );
    }

    /**
     * Vrai dès qu'une des deux variables est posée et non vide.
     *
     * `false` n'est pas une panne : la CI tourne sans clé, le catalogue de
     * démonstration reste jouable, et l'import se désactive avec un message
     * clair porté par {@see TmdbErrorKind::NotConfigured}.
     */
    public function isConfigured(): bool
    {
        return $this->token !== null || $this->apiKey !== null;
    }

    /**
     * Vrai si l'authentification passera par l'en-tête `Authorization`, faux si
     * elle passera par le paramètre d'URL `api_key`.
     */
    public function usesToken(): bool
    {
        return $this->token !== null;
    }

    /**
     * Jeton v4, garanti non vide.
     *
     * @throws TmdbException si aucun jeton n'est posé.
     */
    public function token(): string
    {
        if ($this->token === null) {
            throw TmdbException::notConfigured();
        }

        return $this->token;
    }

    /**
     * Clé v3, garantie non vide.
     *
     * @throws TmdbException si aucune clé n'est posée.
     */
    public function apiKey(): string
    {
        if ($this->apiKey === null) {
            throw TmdbException::notConfigured();
        }

        return $this->apiKey;
    }

    /**
     * URL publique d'un visuel TMDB, `$size` étant un mot-clé TMDB
     * (`original`, `w1280`, `w780`…). Concaténation d'URL, aucune règle métier :
     * quelle taille télécharger et quel visuel retenir appartiennent au service
     * d'import.
     */
    public function imageUrl(string $filePath, string $size = 'original'): string
    {
        return $this->imageBaseUrl.'/'.trim($size, '/').'/'.ltrim($filePath, '/');
    }

    /**
     * Secret de configuration : `null` dès que la valeur est absente ou vide.
     */
    private static function secret(string $key): ?string
    {
        $value = trim(self::text($key, ''));

        return $value === '' ? null : $value;
    }

    private static function text(string $key, string $default): string
    {
        $value = Config::get(self::CONFIG_PREFIX.$key, $default);

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    private static function integer(string $key, int $default): int
    {
        $value = Config::get(self::CONFIG_PREFIX.$key, $default);

        return is_int($value) ? $value : $default;
    }
}
