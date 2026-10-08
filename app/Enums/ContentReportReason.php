<?php

namespace App\Enums;

/**
 * Motif d'un signalement de contenu par un joueur (D63 du 07/10, spec 10
 * § 8.1 bis), liste fermée : cast de `content_report.reason`.
 *
 * Trois motifs n'ont de sens que pour une image ({@see self::targetsFrameOnly()}) :
 * la validation serveur les refuse quand le signalement vise le film entier.
 */
enum ContentReportReason: string
{
    /** L'image n'est pas de ce film, ou la fiche du film est fausse. */
    case WrongMovie = 'wrong_movie';

    /** Un titre ou un texte révélateur est lisible dans l'image. */
    case TitleVisible = 'title_visible';

    /** L'image est trop facile ou trop difficile pour sa place dans la manche. */
    case WrongLevel = 'wrong_level';

    case PoorQuality = 'poor_quality';

    case Offensive = 'offensive';

    case Other = 'other';

    /** Préfixe des libellés joueur (domaine `game`). */
    public const string LABEL_PREFIX = 'game.report.reasons.';

    /** Préfixe des libellés du back-office (domaine `admin`). */
    public const string ADMIN_LABEL_PREFIX = 'admin.content_report.reasons.';

    /** Vrai pour les motifs qui ne visent qu'une image, jamais le film entier. */
    public function targetsFrameOnly(): bool
    {
        return in_array($this, [self::TitleVisible, self::WrongLevel, self::PoorQuality], true);
    }

    /**
     * Les motifs admis pour une portée.
     *
     * @return list<self>
     */
    public static function allowedFor(ContentReportScope $scope): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $reason): bool => $scope === ContentReportScope::Frame || ! $reason->targetsFrameOnly(),
        ));
    }

    /** Libellé joueur. */
    public function labelKey(): string
    {
        return self::LABEL_PREFIX.$this->value;
    }

    /** Libellé du back-office. */
    public function adminLabelKey(): string
    {
        return self::ADMIN_LABEL_PREFIX.$this->value;
    }
}
