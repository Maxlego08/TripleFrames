<?php

namespace App\Support\Tmdb;

use RuntimeException;
use Throwable;

/**
 * L'unique erreur que {@see TmdbClient} laisse sortir — toujours TYPÉE.
 *
 * Un `RequestException` de Guzzle porte l'URL complète, donc la clé v3 quand
 * elle voyage en paramètre : il ne doit jamais remonter tel quel à un écran ni
 * à un rapport d'import. Cette classe en retient le seul strict nécessaire —
 * un cas nommé ({@see TmdbErrorKind}), le statut HTTP, et le `Retry-After`
 * quand TMDB l'envoie — et n'emporte jamais l'URL appelée.
 *
 * Une seule classe portant un enum plutôt qu'une hiérarchie : le consommateur
 * écrit `match ($e->kind)`, l'exhaustivité est vérifiée par PHPStan, et ajouter
 * un cas ne demande ni fichier ni `catch` supplémentaire.
 *
 * Le `message` est destiné au JOURNAL. Ce qui s'affiche passe par
 * {@see self::translationKey()} et {@see self::translationReplacements()} :
 * une clé et des données, jamais une chaîne pré-formatée (règle 4).
 */
final class TmdbException extends RuntimeException
{
    private function __construct(
        public readonly TmdbErrorKind $kind,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * Ni jeton v4 ni clé v3 : l'import est désactivé, et c'est un état normal.
     * La CI tourne sans clé ; le catalogue de démonstration reste jouable.
     */
    public static function notConfigured(): self
    {
        return new self(
            TmdbErrorKind::NotConfigured,
            'Aucune variable TMDB_API_READ_ACCESS_TOKEN ni TMDB_API_KEY n’est posée : import TMDB désactivé.',
        );
    }

    public static function unauthorized(int $status): self
    {
        return new self(
            TmdbErrorKind::Unauthorized,
            'TMDB a refusé l’authentification (statut '.$status.').',
            $status,
        );
    }

    public static function notFound(string $context): self
    {
        return new self(
            TmdbErrorKind::NotFound,
            'TMDB ne connaît pas la ressource demandée ['.$context.'].',
            404,
        );
    }

    public static function rateLimited(?int $retryAfterSeconds): self
    {
        return new self(
            TmdbErrorKind::RateLimited,
            'Quota TMDB atteint'.($retryAfterSeconds !== null ? ' ; réessayer dans '.$retryAfterSeconds.' s.' : '.'),
            429,
            $retryAfterSeconds,
        );
    }

    public static function serverError(int $status): self
    {
        return new self(
            TmdbErrorKind::ServerError,
            'TMDB est en panne (statut '.$status.'), tentatives épuisées.',
            $status,
        );
    }

    public static function unexpectedStatus(int $status): self
    {
        return new self(
            TmdbErrorKind::UnexpectedStatus,
            'Statut TMDB inattendu ('.$status.').',
            $status,
        );
    }

    public static function transport(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            TmdbErrorKind::Transport,
            'Appel TMDB interrompu : '.$reason,
            null,
            null,
            $previous,
        );
    }

    /**
     * Visuel plus lourd que le plafond configuré, en kilooctets : le corps n'est
     * pas lu au-delà, et rien n'en est conservé.
     */
    public static function tooLarge(int $maxKilobytes): self
    {
        return new self(
            TmdbErrorKind::TooLarge,
            'Visuel TMDB plus lourd que le plafond de '.$maxKilobytes.' Ko : téléchargement interrompu.',
        );
    }

    /**
     * Forme de réponse hors contrat. `$context` nomme le champ fautif, jamais
     * son contenu : une charge utile entière dans un journal est une fuite.
     */
    public static function malformed(string $context, string $reason): self
    {
        return new self(
            TmdbErrorKind::Malformed,
            'Réponse TMDB hors contrat sur ['.$context.'] : '.$reason,
        );
    }

    public function translationKey(): string
    {
        return $this->kind->translationKey();
    }

    /**
     * Substitutions de la clé de traduction — des DONNÉES, jamais du texte.
     *
     * @return array<string, int|string>
     */
    public function translationReplacements(): array
    {
        $replacements = [];

        if ($this->httpStatus !== null) {
            $replacements['status'] = $this->httpStatus;
        }

        if ($this->retryAfterSeconds !== null) {
            $replacements['retry_after'] = $this->retryAfterSeconds;
        }

        return $replacements;
    }

    /**
     * Vrai si une reprise ultérieure a une chance d'aboutir sans geste humain :
     * c'est ce que lit la boucle d'import pour décider entre suspendre un
     * `import_run` (reprenable) et l'arrêter en échec.
     */
    public function isTransient(): bool
    {
        return $this->kind->isTransient();
    }
}
