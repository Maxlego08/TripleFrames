<?php

namespace App\Enums;

/**
 * Nature du sujet d'un geste consigné, en alias court et non en nom de classe —
 * ce n'est pas un `morphTo`, `admin_action.subject_id` n'a aucune clé
 * étrangère : cast de `admin_action.subject_type`.
 *
 * `site` (23/09) est le seul sujet SANS identifiant : `subject_id` est NULL si
 * et seulement si le sujet est le site — la fermeture et la réouverture
 * visent l'instance entière, pas une ligne.
 */
enum AdminActionSubject: string
{
    case Movie = 'movie';

    case Frame = 'frame';

    case User = 'user';

    case Player = 'player';

    case TakedownRequest = 'takedown_request';

    case Site = 'site';

    /** Préfixe des libellés du back-office, un par cas. */
    public const string LABEL_PREFIX = 'admin.enum.admin_action_subject.';

    /** Vrai pour le seul sujet qui ne désigne aucune ligne. */
    public function hasIdentifier(): bool
    {
        return $this !== self::Site;
    }

    /** Clé du libellé au back-office. */
    public function labelKey(): string
    {
        return self::LABEL_PREFIX.$this->value;
    }
}
