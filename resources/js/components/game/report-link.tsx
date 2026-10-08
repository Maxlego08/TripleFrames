import { Flag } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { create as reportContent } from '@/routes/content-report';

type ReportLinkProps = {
    /** `RevealMovie.tmdb` : rien n'est rendu quand il est nul. */
    tmdb: number | null;
} & (
    | {
          /** Le film entier : son titre affiché nomme le lien parmi d'autres. */
          kind: 'movie';
          title: string;
      }
    | {
          /** Une image servie : `reveal.frames[].framePublicId`. */
          kind: 'frame';
          framePublicId: string;
          /** Le numéro de palier déjà formaté dans la langue du joueur. */
          index: string;
      }
);

/**
 * Le lien « Signaler » d'un film ou d'une image révélés (D63 du 07/10), vers
 * la page publique `/report?movie=<tmdb>&frame=<public_id>` : à la
 * révélation (une fois par image, une fois pour le film) et au récapitulatif
 * du podium (film seulement). **Jamais avant la révélation** (règle 3) : le
 * `public_id` d'une image ne voyage qu'avec elle.
 *
 * **Toujours dans un nouvel onglet** (`target="_blank"`) : la partie
 * continue. `noreferrer` est obligatoire, l'URL de jeu portant le
 * `room_code`. Calqué sur `letterboxd-link.tsx`.
 *
 * Composant de présentation (C16 § 2.9) : des props seulement ; tokens
 * seulement.
 */
export function ReportLink(props: ReportLinkProps) {
    const { t } = useTranslations();

    if (props.tmdb === null) {
        return null;
    }

    const href = reportContent({
        query:
            props.kind === 'frame'
                ? { movie: props.tmdb, frame: props.framePublicId }
                : { movie: props.tmdb },
    }).url;

    return (
        <Button
            asChild
            variant="ghost"
            size="sm"
            className="self-start text-muted-foreground"
        >
            <a
                href={href}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={
                    props.kind === 'frame'
                        ? t('game.report.report_frame_label', {
                              index: props.index,
                          })
                        : t('game.report.report_movie_label', {
                              title: props.title,
                          })
                }
            >
                <Flag aria-hidden="true" />
                {props.kind === 'frame'
                    ? t('game.report.report_frame')
                    : t('game.report.report_movie')}
            </a>
        </Button>
    );
}
