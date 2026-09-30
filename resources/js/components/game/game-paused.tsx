import { CirclePause } from 'lucide-react';
import { useEffect, useEffectEvent, useId, useRef } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { parseIsoMs } from '@/lib/game/wire';
import type { IsoMs } from '@/types/game-wire';

export type GamePausedProps = {
    /**
     * `paused_at + pauseTimeoutMs` (`game.paused.interruptsAt`, `pause` du
     * paquet) : l'instant où la partie sera close sans retour d'un joueur.
     */
    interruptsAt: IsoMs;
};

/**
 * La partie en pause (spec 60 § 14 ; 90 § 10, état « Manche », pause) : en
 * fin de révélation, aucun siège n'était présent, la manche suivante est
 * déprogrammée. Elle reprend au retour d'un joueur — le battement de cette
 * page suffit, et `game.resumed` puis `round.scheduled` ramènent le décompte
 * de reprise —, sinon elle est close sur un podium « interrompue » à
 * `interruptsAt`.
 *
 * L'heure de clôture est formatée par le client, dans la langue et le fuseau
 * du joueur (`Intl.DateTimeFormat`), jamais par le serveur (05). Aucun
 * chrono ici : l'horloge d'une manche ne se met jamais en pause, et aucune
 * manche ne court.
 *
 * **Focus** : l'écran précédent (révélation) démonté emporte le focus ; le
 * titre (`tabIndex={-1}`) le reprend, seulement s'il est perdu.
 *
 * Composant de présentation : ni Echo, ni horloge, des props seulement.
 */
export function GamePaused({ interruptsAt }: GamePausedProps) {
    const { t, locale } = useTranslations();
    const headingId = useId();
    const headingRef = useRef<HTMLHeadingElement>(null);
    const time = new Intl.DateTimeFormat(locale, {
        hour: 'numeric',
        minute: '2-digit',
    }).format(parseIsoMs(interruptsAt));

    const reclaimFocus = useEffectEvent((): void => {
        const focused = document.activeElement;

        if (focused === null || focused === document.body) {
            headingRef.current?.focus();
        }
    });

    useEffect(() => reclaimFocus(), []);

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
            <p>{t('game.pause.description')}</p>
            <p className="text-muted-foreground">
                {t('game.pause.interrupts_at', { time })}
            </p>
        </section>
    );
}
