import type { UserRole } from '@/types/auth';

/**
 * Miroir front de `App\Enums\UserRole::level()` : `player` ⊂ `curator` ⊂
 * `admin`.
 *
 * Il ne sert **qu'à l'affichage** — masquer une entrée de navigation qu'un
 * curateur ne peut pas ouvrir. L'autorisation reste serveur : chaque route du
 * groupe `/admin` porte son `can:` et sa policy, et aucune d'elles ne lit ce
 * fichier. Un front qui mentirait sur le rôle n'ouvrirait rien de plus.
 */
const ROLE_LEVEL: Record<UserRole, number> = {
    player: 1,
    curator: 2,
    admin: 3,
};

/** Transcription de `UserRole::atLeast()`. */
export function hasAtLeastRole(role: UserRole, threshold: UserRole): boolean {
    return ROLE_LEVEL[role] >= ROLE_LEVEL[threshold];
}
