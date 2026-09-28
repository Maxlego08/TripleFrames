import { usePage } from '@inertiajs/react';
import { GameFrame } from '@/components/game/game-frame';
import { GameThemeScope } from '@/components/game/game-theme-scope';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import type { FrameLevel } from '@/types/admin';

type Props = {
    /**
     * `game_url` de l'image (route `admin.catalog.frames.game`, C9-bis) : les
     * MÊMES octets que ceux servis aux joueurs. `null` tant qu'aucun rendu
     * n'existe — le cadre dit alors l'indisponibilité.
     */
    gameUrl: string | null;
    level: FrameLevel;
    /** Variante dense de l'écran de revue, qui garde les deux largeurs. */
    compact?: boolean;
    className?: string;
};

/**
 * L'image telle qu'un joueur la verra (spec 20 § 6.7 ; D8 du 23/09, A-23) :
 * le conteneur de jeu `GameFrame` (C16 § 2.5), sous les tokens sombres du jeu
 * (`GameThemeScope`, C16 § 2.2), quelle que soit l'apparence choisie par le
 * curateur. Le rendu final, jamais l'aperçu de recadrage.
 *
 * Deux largeurs : celle du viewport minimal déclaré par `90` (360 px),
 * simulée par la classe d'échelle `w-90` (22,5 rem — aucune valeur en `px`
 * dans un fichier surveillé), et celle d'un ordinateur, qui prend la place
 * restante. Même composant pour l'éditeur de la banque et, au lot L20-12,
 * pour la revue.
 *
 * Aucun portail n'est ouvert dans la portée sombre (C16 § 2.2) : un portail
 * se rendrait hors d'elle et perdrait ses tokens.
 */
export function GameConditionsPreview({
    gameUrl,
    level,
    compact = false,
    className,
}: Props) {
    const { t } = useTranslations();
    const format = usePage().props.frameFormat;

    const alt = t('admin.frame.preview.alt', { level });
    const loadingLabel = t('admin.frame.preview.loading');
    const unavailableLabel = t('admin.frame.preview.unavailable');

    return (
        <GameThemeScope
            className={cn(
                'flex flex-col rounded-md lg:flex-row lg:items-start',
                compact ? 'gap-3 p-3 lg:justify-center' : 'gap-4 p-4',
                className,
            )}
        >
            <figure
                className={cn(
                    'flex max-w-full shrink-0 flex-col gap-2',
                    compact ? 'w-64' : 'w-90',
                )}
            >
                <GameFrame
                    src={gameUrl}
                    alt={alt}
                    loadingLabel={loadingLabel}
                    unavailableLabel={unavailableLabel}
                    format={format}
                    className="rounded-sm"
                />
                <figcaption className="text-xs text-muted-foreground">
                    {t('admin.frame.preview.mobile')}
                </figcaption>
            </figure>

            <figure
                className={cn(
                    'flex min-w-0 flex-1 flex-col gap-2',
                    compact && 'lg:max-w-2xl',
                )}
            >
                <GameFrame
                    src={gameUrl}
                    alt={alt}
                    loadingLabel={loadingLabel}
                    unavailableLabel={unavailableLabel}
                    format={format}
                    className="rounded-sm"
                />
                <figcaption className="text-xs text-muted-foreground">
                    {t('admin.frame.preview.desktop')}
                </figcaption>
            </figure>
        </GameThemeScope>
    );
}
