import { useEffect, useId, useRef } from 'react';
import type { ReactNode } from 'react';
import { PodiumHighlights } from '@/components/game/podium-highlights';
import { RoundRecap } from '@/components/game/round-recap';
import { StandingsTable } from '@/components/game/standings-table';
import { ErrorState } from '@/components/state/error-state';
import { LoadingState } from '@/components/state/loading-state';
import { useTranslations } from '@/hooks/use-translations';
import type { Podium as PodiumData } from '@/types/scoring';

export type PodiumProps = {
    /**
     * Le podium (`game.ended`, ou le paquet relu : 60 le rejoue à l'identique
     * jusqu'à l'archivage du salon ; en solo, la prop `state.podium`). Nul
     * tant qu'il est attendu sans être encore arrivé : l'écran dit le
     * chargement.
     */
    podium: PodiumData | null;
    /**
     * Le podium attendu n'a pas pu être relu (resynchronisation en échec) :
     * l'écran dit l'erreur et propose de réessayer. Sans effet quand le
     * podium est là.
     */
    failed?: boolean;
    /** « Réessayer » : la resynchronisation de la page (60). */
    onRetry?: () => void;
    /**
     * Les gestes d'après-partie, composés par la page sous l'en-tête :
     * « Rejouer » de l'hôte ou l'attente des autres (50, `ReplayButton`).
     * Rendus seulement quand le podium est là.
     */
    children?: ReactNode;
};

/**
 * Le podium (spec 80 § 11, lot L80-7 ; 90 § 10, « Podium ») : l'en-tête —
 * partie terminée (`game.podium.completed`) ou interrompue à la manche `k`
 * sur `M` (`game.podium.interrupted`) —, les gestes de la page (« Rejouer »),
 * le classement final avec avatars (`StandingsTable`, rang « — » en solo),
 * les faits marquants (D25 du 23/09) et le récapitulatif des films, localisé,
 * en texte seul.
 *
 * États (§ 20, L80-7) :
 *
 * - **chargement** : `LoadingState` tant que le podium attendu n'est pas
 *   arrivé, par `game.ended` ou par la resynchronisation ;
 * - **erreur** : `ErrorState`, dont « Réessayer » relance la
 *   resynchronisation (`onRetry`, 60) ;
 * - **déconnexion** : le bandeau `ConnectionBanner` de la page (90, C16) ;
 *   le podium reste affiché, et toute resynchronisation le rejoue à
 *   l'identique (60) ;
 * - **clavier** : au montage du podium, le focus va à son titre
 *   (`tabIndex={-1}`, C16 § 2.12) — sur mobile, cela ferme le clavier, ce
 *   qui est voulu : la saisie est close. Le tableau n'a rien de
 *   focalisable ; il se parcourt par les commandes de tableau du lecteur
 *   d'écran et défile avec la page.
 *
 * Le titre du podium est aussi, en `sr-only`, la légende du tableau du
 * classement (`game.podium.title`). Rien de ce que rend le podium n'est une
 * région vivante : le déplacement du focus suffit à le faire lire (C16 § 4).
 *
 * Composant de présentation (C16 § 2.9) : ni Echo, ni horloge, des props
 * seulement ; tokens seulement.
 */
export function Podium({
    podium,
    failed = false,
    onRetry,
    children,
}: PodiumProps) {
    const { t, locale } = useTranslations();
    const titleId = useId();
    const titleRef = useRef<HTMLHeadingElement>(null);
    const ready = podium !== null;

    // Focus au titre à l'arrivée du podium (C16 § 2.12), une fois par
    // arrivée ; le double montage de `strictMode` le repose au même endroit.
    useEffect(() => {
        if (ready) {
            titleRef.current?.focus();
        }
    }, [ready]);

    if (podium === null) {
        return failed && onRetry !== undefined ? (
            <ErrorState
                title={t('game.podium.unavailable')}
                onRetry={onRetry}
                retryLabel={t('game.podium.retry')}
            />
        ) : (
            <LoadingState label={t('common.state.loading')} />
        );
    }

    const number = new Intl.NumberFormat(locale);

    return (
        <section aria-labelledby={titleId} className="flex flex-col gap-6">
            <header className="flex flex-col gap-1">
                <h2
                    id={titleId}
                    ref={titleRef}
                    tabIndex={-1}
                    className="text-2xl font-semibold tracking-tight focus-visible:outline-none"
                >
                    {t('game.podium.title')}
                </h2>
                <p className="text-muted-foreground">
                    {podium.gameStatus === 'interrupted'
                        ? t('game.podium.interrupted', {
                              k: number.format(podium.roundsCompleted),
                              m: number.format(podium.roundsCount),
                          })
                        : t('game.podium.completed', {
                              m: number.format(podium.roundsCount),
                          })}
                </p>
            </header>

            {children}

            <StandingsTable standings={podium.standings} captionHidden />

            <PodiumHighlights
                highlights={podium.highlights}
                standings={podium.standings}
                recap={podium.recap}
            />

            <RoundRecap recap={podium.recap} />
        </section>
    );
}
