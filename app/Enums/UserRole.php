<?php

namespace App\Enums;

/** Les trois rôles hiérarchiques `player` ⊂ `curator` ⊂ `admin` : cast de `users.role`, `frame_review.reviewer_role`, `admin_action.role_before` et `admin_action.role_after`. */
enum UserRole: string
{
    case Player = 'player';

    case Curator = 'curator';

    case Admin = 'admin';

    /**
     * Seuil hiérarchique, posé par chaque policy pour son propre compte.
     * Jamais de `Gate::before` global accordant tout à l'administrateur : il contournerait
     * la propriété d'une `saved_config`, déclarée strictement privée.
     */
    public function atLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    public function level(): int
    {
        return match ($this) {
            self::Player => 1,
            self::Curator => 2,
            self::Admin => 3,
        };
    }
}
