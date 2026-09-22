<?php

namespace App\Support\Tmdb;

/**
 * Les cas d'échec nommés d'un appel TMDB — jamais une exception nue.
 *
 * Aucun texte humain ici : chaque cas n'expose qu'une CLÉ de traduction du
 * domaine `admin` ({@see self::translationKey()}), français par construction
 * (`05-i18n-et-langues.md` § back-office). Le message porté par
 * {@see TmdbException} est un message de JOURNAL destiné au développeur, jamais
 * une chaîne affichée : ce qui remonte à l'écran transite en données — une clé
 * et ses substitutions — jamais en chaîne pré-formatée côté serveur (règle 4).
 *
 * Ce n'est PAS un enum de schéma : il ne caste aucune colonne. `import_run` ne
 * conserve que `status` et les compteurs ; la cause d'un arrêt vit dans le
 * journal applicatif et dans le message rendu au curateur.
 */
enum TmdbErrorKind: string
{
    /** Aucune des deux variables d'environnement n'est posée : l'import est désactivé, rien n'est cassé. */
    case NotConfigured = 'not_configured';

    /** 401 ou 403 : jeton v4 ou clé v3 invalide, révoquée, ou dépourvue du droit demandé. */
    case Unauthorized = 'unauthorized';

    /** 404 : identifiant inconnu de TMDB. */
    case NotFound = 'not_found';

    /** 429 : quota atteint. `retryAfterSeconds` porte l'en-tête `Retry-After` quand TMDB l'envoie. */
    case RateLimited = 'rate_limited';

    /** 5xx : panne côté TMDB, après épuisement des tentatives. */
    case ServerError = 'server_error';

    /** Panne de transport : DNS, TLS, délai d'attente dépassé, connexion coupée. */
    case Transport = 'transport';

    /** Réponse 2xx dont la forme ne correspond pas au contrat : aucun `mixed` ne passe en aval. */
    case Malformed = 'malformed';

    /** Statut hors de tous les cas ci-dessus : 3xx non suivi, 400, 422. */
    case UnexpectedStatus = 'unexpected_status';

    /** Préfixe des clés de traduction, domaine `admin` (français seulement). */
    private const string LANG_PREFIX = 'admin.tmdb.error.';

    /**
     * Clé de traduction du cas, à afficher par le back-office ou la commande.
     *
     * Les clés correspondantes sont à créer dans `lang/fr/admin.php` par la piste
     * i18n ; aucun texte n'est écrit ici.
     */
    public function translationKey(): string
    {
        return self::LANG_PREFIX.$this->value;
    }

    /**
     * Vrai si une nouvelle tentative plus tard a une chance d'aboutir sans geste
     * humain. `false` pour une clé absente, invalide, ou un identifiant inconnu.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::RateLimited, self::ServerError, self::Transport => true,
            self::NotConfigured, self::Unauthorized, self::NotFound,
            self::Malformed, self::UnexpectedStatus => false,
        };
    }
}
