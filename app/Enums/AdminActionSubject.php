<?php

namespace App\Enums;

/**
 * Nature du sujet d'un geste consigné, en alias court et non en nom de classe —
 * ce n'est pas un `morphTo`, `admin_action.subject_id` n'a aucune clé
 * étrangère : cast de `admin_action.subject_type`.
 *
 * Deux sujets SANS identifiant : `site` (23/09) — la fermeture et la
 * réouverture visent l'instance entière, pas une ligne — et `accounts`
 * (D41 du 30/09), l'ensemble des comptes que montre une lecture sensible
 * (annuaire, écran des accès). `subject_id` est NULL si et seulement si le
 * sujet n'a pas d'identifiant ({@see self::hasIdentifier()}).
 *
 * `import_run` (D41 du 30/09) : le balayage d'import qu'un curateur a lancé
 * ou repris depuis le back-office.
 *
 * `theme` (D43 du 01/10) : un thème de l'écran des thèmes du back-office —
 * création, correction, publication.
 */
enum AdminActionSubject: string
{
    case Movie = 'movie';

    case Frame = 'frame';

    case User = 'user';

    case Player = 'player';

    case TakedownRequest = 'takedown_request';

    case Site = 'site';

    case ImportRun = 'import_run';

    case Accounts = 'accounts';

    case Theme = 'theme';

    /** Une partie inspectée (D46 du 01/10). */
    case Game = 'game';

    /** L'ensemble des parties que montre la liste d'inspection (D46 du 01/10). */
    case Games = 'games';

    /** L'ensemble des sièges que montre l'annuaire des joueurs (D46 du 01/10). */
    case Players = 'players';

    /**
     * Un groupe de signalements de contenu clos ensemble (D63 du 07/10) :
     * `subject_id` désigne le plus ancien signalement du groupe, `details`
     * la cible et le nombre de signalements clos.
     */
    case ContentReport = 'content_report';

    /** Préfixe des libellés du back-office, un par cas. */
    public const string LABEL_PREFIX = 'admin.enum.admin_action_subject.';

    /** Faux pour les sujets qui ne désignent aucune ligne : le site et les ensembles de comptes, de parties et de sièges. */
    public function hasIdentifier(): bool
    {
        return ! in_array($this, [self::Site, self::Accounts, self::Games, self::Players], true);
    }

    /** Clé du libellé au back-office. */
    public function labelKey(): string
    {
        return self::LABEL_PREFIX.$this->value;
    }
}
