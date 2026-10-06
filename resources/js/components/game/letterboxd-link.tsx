import { ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

type LetterboxdLinkProps = {
    /** `RevealMovie.letterboxdUrl` : rien n'est rendu quand elle est nulle. */
    url: string | null;
    /** Le titre affiché du film, pour nommer le lien parmi d'autres (récapitulatif). */
    title: string;
};

/**
 * Le lien vers la fiche Letterboxd d'un film révélé (D58 du 06/10), d'où le
 * joueur l'ajoute à sa watchlist : à la révélation, au récapitulatif du
 * podium et dans l'historique du compte (J2).
 *
 * **Toujours dans un nouvel onglet** (`target="_blank"`) : la page de jeu
 * reste ouverte, la partie continue. `noreferrer` est obligatoire, l'URL de
 * jeu portant le `room_code`. Lien texte et icône lucide, jamais le logo de
 * Letterboxd.
 *
 * Composant de présentation (C16 § 2.9) : des props seulement ; tokens
 * seulement.
 */
export function LetterboxdLink({ url, title }: LetterboxdLinkProps) {
    const { t } = useTranslations();

    if (url === null) {
        return null;
    }

    return (
        <Button asChild variant="outline" size="sm" className="self-start">
            <a
                href={url}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={t('game.reveal.letterboxd_label', { title })}
            >
                {t('game.reveal.letterboxd')}
                <ExternalLink aria-hidden="true" />
            </a>
        </Button>
    );
}
