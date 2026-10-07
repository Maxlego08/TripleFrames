import { CirclePause } from 'lucide-react';
import { useEffect, useEffectEvent, useId, useRef } from 'react';
import type { ReactNode } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { parseIsoMs } from '@/lib/game/wire';
import type { GamePauseKind, IsoMs } from '@/types/game-wire';

export type GamePausedProps = {
    /**
     * L'échéance de la pause (`game.paused.interruptsAt`, `pause` du
     * paquet) : l'instant où la partie sera close sans reprise.
     */
    interruptsAt: IsoMs;
    /** Nature de la pause (D64 du 07/10) ; `empty` si absente. */
    kind?: GamePauseKind;
    /** Partie solo : la pause manuelle est celle du joueur lui-même. */
    solo?: boolean;
    /**
     * Temps restant avant l'échéance, en millisecondes, calculé par la page
     * sur l'horloge d'affichage (instant serveur estimé) ; nul : non montré.
     * Affichage seulement : la clôture est décidée par le serveur.
     */
    remainingMs?: number | null;
    /** « Reprendre », pour un siège qui en a l'autorité ; nul sinon. */
    action?: ReactNode;
    /** La reprise est offerte parce que l'hôte n'est plus un siège présent. */
    hostAbsent?: boolean;
};

const MS_PER_SECOND = 1000;
const SECONDS_PER_MINUTE = 60;

/**
 * La partie en pause (spec 60 § 14 ; 90 § 10, état « Manche », pause), la
 * manche suivante déprogrammée :
 *
 * - pause **automatique** (`empty`) : en fin de révélation, aucun siège
 *   n'était présent. Elle reprend au retour d'un joueur — le battement de
 *   cette page suffit, et `game.resumed` puis `round.scheduled` ramènent le
 *   décompte de reprise ;
 * - pause **manuelle** (`manual`, D64 du 07/10) : l'hôte (ou le joueur solo)
 *   l'a voulue ; seul « Reprendre » la reprend (`action`), offert à l'hôte,
 *   au joueur solo, ou à tout siège présent si l'hôte n'en est plus un.
 *
 * Sinon elle est close sur un podium « interrompue » à `interruptsAt`.
 * L'heure de clôture est formatée par le client, dans la langue et le fuseau
 * du joueur (`Intl.DateTimeFormat`), jamais par le serveur (05) ; le temps
 * restant n'est qu'un affichage (aucune région vivante ici).
 *
 * **Focus** : l'écran précédent (révélation) démonté emporte le focus ; le
 * titre (`tabIndex={-1}`) le reprend, seulement s'il est perdu.
 *
 * Composant de présentation : ni Echo, ni horloge, des props seulement.
 */
export function GamePaused({
    interruptsAt,
    kind = 'empty',
    solo = false,
    remainingMs = null,
    action = null,
    hostAbsent = false,
}: GamePausedProps) {
    const { t, locale } = useTranslations();
    const headingId = useId();
    const headingRef = useRef<HTMLHeadingElement>(null);
    const time = new Intl.DateTimeFormat(locale, {
        hour: 'numeric',
        minute: '2-digit',
    }).format(parseIsoMs(interruptsAt));
    const manual = kind === 'manual';

    const reclaimFocus = useEffectEvent((): void => {
        const focused = document.activeElement;

        if (focused === null || focused === document.body) {
            headingRef.current?.focus();
        }
    });

    useEffect(() => reclaimFocus(), []);

    let remaining: string | null = null;

    if (remainingMs !== null) {
        const totalSeconds = Math.max(
            0,
            Math.ceil(remainingMs / MS_PER_SECOND),
        );
        const minutes = Math.floor(totalSeconds / SECONDS_PER_MINUTE);
        const seconds = totalSeconds % SECONDS_PER_MINUTE;
        const pad = new Intl.NumberFormat(locale, { minimumIntegerDigits: 2 });

        remaining = t('game.pause.remaining', {
            time: `${new Intl.NumberFormat(locale).format(minutes)}:${pad.format(seconds)}`,
        });
    }

    return (
        <section
            aria-labelledby={headingId}
            className="flex flex-col gap-2 rounded-md border border-border p-4"
        >
            <h2
                id={headingId}
                ref={headingRef}
                tabIndex={-1}
                className="flex items-center gap-2 text-lg font-semibold outline-none"
            >
                <CirclePause aria-hidden="true" className="size-5 shrink-0" />
                {t('game.pause.title')}
            </h2>
            <p>
                {manual
                    ? t(
                          solo
                              ? 'game.pause.manual_description_solo'
                              : 'game.pause.manual_description',
                      )
                    : t('game.pause.description')}
            </p>
            {hostAbsent && <p>{t('game.pause.host_absent')}</p>}
            <p className="text-muted-foreground">
                {t(
                    manual
                        ? 'game.pause.manual_interrupts_at'
                        : 'game.pause.interrupts_at',
                    { time },
                )}
            </p>
            {remaining !== null && (
                <p className="text-muted-foreground tabular-nums">
                    {remaining}
                </p>
            )}
            {action}
        </section>
    );
}
