<?php

namespace App\Enums;

/**
 * Les six statuts HTTP rendus en page d'erreur traduite (spec 90 § 4.8) :
 * page `error` côté joueur, page `admin/error` côté back-office. **Seul
 * endroit où leur liste est écrite côté serveur** : le gestionnaire
 * d'exceptions ne rend une page que pour un de ces cas, et laisse tout autre
 * statut (401, 405, 422…) à la réponse du framework.
 *
 * Miroir client : l'union `403 | 404 | 419 | 429 | 500 | 503` et la table de
 * clés de `resources/js/pages/error.tsx`, tenues alignées sur ces cas par
 * `ErrorPagesTest`.
 *
 * Les clés sont construites ici, jamais à l'exécution côté client (spec 90
 * § 6.7) : `TranslationCoverageTest` › `carries every key built by an
 * enumerable key constructor` les couvre, puisqu'aucun balayage de littéraux
 * ne voit une clé concaténée.
 */
enum ErrorPageStatus: int
{
    case Forbidden = 403;

    case NotFound = 404;

    case PageExpired = 419;

    case TooManyRequests = 429;

    case ServerError = 500;

    case ServiceUnavailable = 503;

    /** Segment du statut dans les clés `common.error.{slug}.*`. */
    public function slug(): string
    {
        return match ($this) {
            self::Forbidden => 'forbidden',
            self::NotFound => 'not_found',
            self::PageExpired => 'page_expired',
            self::TooManyRequests => 'too_many_requests',
            self::ServerError => 'server_error',
            self::ServiceUnavailable => 'service_unavailable',
        };
    }

    /** Titre de la page `error`, domaine `common`. */
    public function titleKey(): string
    {
        return 'common.error.'.$this->slug().'.title';
    }

    /**
     * Explication de la page `error`, domaine `common`. Celle de
     * {@see self::PageExpired} est aussi le message du flash rendu après une
     * page expirée en visite Inertia (spec 90 § 4.8).
     */
    public function descriptionKey(): string
    {
        return 'common.error.'.$this->slug().'.description';
    }
}
