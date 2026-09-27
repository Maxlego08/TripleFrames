import { useHttp } from '@inertiajs/react';
import { useRef, useState } from 'react';
import NextRoundController from '@/actions/App/Http/Controllers/Game/NextRoundController';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { readNextRoundFailure } from '@/lib/game/next-round';
import type { NextRoundFailure } from '@/lib/game/next-round';
import { roundKeyOf, seatTokenHeaders } from '@/lib/game/store';

export type UseNextRoundOptions = {
    /** Code du salon (prop `room.code`), pour Wayfinder. */
    roomCode: string;
    /** `gameRef` de la partie suivie (`state.gameRef`) ; nul : rien ne part. */
    gameRef: string | null;
    /**
     * `sequenceIndex` de la manche révélée que l'écran montre : l'échec d'un
     * geste n'appartient qu'à elle, et s'efface avec elle. Nul : rien ne part.
     */
    sequenceIndex: number | null;
};

export type NextRoundGesture = {
    /** Envoie le geste ; un seul à la fois. */
    advance: () => void;
    /** Le geste est en vol. */
    pending: boolean;
    /** Dernier échec du geste sur la manche montrée, déjà traduit ; nul sinon. */
    error: string | null;
};

/** Corps vide : la route lit la partie sur le siège (`seat.active`). */
type NextRoundBody = Record<string, never>;

/** Un échec, rattaché à la manche où il a eu lieu. */
type Failure = { key: string; message: string };

/**
 * « Manche suivante », le seul pouvoir de l'hôte en partie (spec 60 § 5.4,
 * § 10.1 et § 13.5) : `POST room.round.next`, adressé par Wayfinder, envoyé
 * par `useHttp()` sous l'en-tête `X-Seat-Token` (`seatTokenHeaders()`, que
 * `useGameState` pose aussi sur toute requête). Le geste **raccourcit `R`,
 * jamais `D`**, et le serveur en décide seul : autorité relue sous le verrou
 * du salon, révélation relue après rattrapage.
 *
 * - **204** : rien à faire ici — la manche suivante, reprogrammée, arrive par
 *   `round.scheduled` réémis, que le magasin retient au `serverNow` le plus
 *   grand (C7 § 4.2).
 * - **Échec** (`readNextRoundFailure`) : rendu sous le bouton et annoncé dans
 *   l'unique région vivante (`announce()`, C16 § 4), jamais la fenêtre
 *   d'erreur brute d'Inertia — `not_revealing` → `game.errors.not_revealing`,
 *   onglet supplanté → `game.errors.seat_superseded` (la relecture est
 *   laissée à l'écouteur de `use-game-state`), tout le reste →
 *   `common.connection.offline` hors ligne, `common.state.error` sinon.
 * - L'échec n'appartient qu'à la manche montrée (clé `roundKeyOf`), comme
 *   les verdicts de `useAnswerSubmission` : la révélation suivante repart
 *   de rien.
 */
export function useNextRound(options: UseNextRoundOptions): NextRoundGesture {
    const { roomCode, gameRef, sequenceIndex } = options;
    const { t } = useTranslations();
    const http = useHttp<NextRoundBody, null>({});
    const inFlight = useRef(false);
    const [pending, setPending] = useState(false);
    const [failure, setFailure] = useState<Failure | null>(null);

    const key =
        gameRef === null || sequenceIndex === null
            ? null
            : roundKeyOf(gameRef, sequenceIndex);

    const messageFor = (kind: NextRoundFailure): string | null => {
        switch (kind) {
            case 'not_revealing':
                return t('game.errors.not_revealing');
            case 'superseded':
                return t('game.errors.seat_superseded');
            case 'cancelled':
                return null;
            case 'failed':
                return typeof navigator !== 'undefined' && !navigator.onLine
                    ? t('common.connection.offline')
                    : t('common.state.error');
        }
    };

    const advance = (): void => {
        if (key === null || inFlight.current) {
            return;
        }

        const current = key;

        inFlight.current = true;
        setPending(true);
        setFailure(null);

        http.submit(NextRoundController.store({ room: roomCode }), {
            headers: seatTokenHeaders(),
        })
            .catch((error: unknown) => {
                const message = messageFor(readNextRoundFailure(error));

                if (message !== null) {
                    setFailure({ key: current, message });
                    announce(message);
                }
            })
            .finally(() => {
                inFlight.current = false;
                setPending(false);
            });
    };

    return {
        advance,
        pending,
        error: failure !== null && failure.key === key ? failure.message : null,
    };
}
