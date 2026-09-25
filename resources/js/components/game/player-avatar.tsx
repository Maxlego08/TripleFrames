import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { AvatarData } from '@/types/player';

export type PlayerAvatarProps = {
    /** `PlayerIdentity.avatar`, résolu par le serveur. */
    avatar: AvatarData;
    /**
     * Déjà traduit par l'appelant, depuis `avatarAltKey(avatar.altKey)`.
     * **Chaîne vide à côté d'un pseudo affiché** : l'avatar est alors
     * décoratif, et un lecteur d'écran ne lit pas deux fois l'identité d'un
     * joueur (I5.9).
     */
    alt: string;
    /** Taille et typographie, par classes de tokens (`size-10 text-sm`…). */
    className?: string;
};

/**
 * Avatar d'un joueur (spec 40 § 7.4, contrat C5 ; liste close de 90 § 9.2).
 *
 * **Aucun appel à `t()`, aucune dépendance à Inertia** : l'avatar arrive
 * résolu (`PlayerIdentity.avatar`), le texte alternatif arrive traduit. La
 * même primitive sert le lobby, la bande des joueurs et le podium.
 *
 * Chaîne d'affichage, tranchée par le serveur : l'image prédéfinie (fichier
 * statique de `public/avatars/`, jamais la route des images de jeu) quand
 * `url` existe, sinon les initiales. Tant que l'image n'est pas chargée, ou
 * si elle échoue, les initiales la remplacent — `?` pour un siège masqué,
 * dont les initiales trahiraient le pseudo (I5.10).
 *
 * Accessibilité (I5.9) : `alt` vide, l'avatar entier sort de l'arbre
 * d'accessibilité, initiales comprises ; `alt` rempli, l'image le porte, et
 * les initiales de repli se lisent comme une image nommée par lui, jamais
 * lettre à lettre.
 */
export function PlayerAvatar({ avatar, alt, className }: PlayerAvatarProps) {
    const decorative = alt === '';

    return (
        <Avatar
            aria-hidden={decorative ? true : undefined}
            className={className}
        >
            {avatar.url !== null ? (
                <AvatarImage src={avatar.url} alt={alt} draggable={false} />
            ) : null}
            <AvatarFallback
                role={decorative ? undefined : 'img'}
                aria-label={decorative ? undefined : alt}
                className="select-none"
            >
                {avatar.initials}
            </AvatarFallback>
        </Avatar>
    );
}
