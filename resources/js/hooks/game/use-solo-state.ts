import { router } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { useGameState } from '@/hooks/game/use-game-state';
import type { GameStateView } from '@/hooks/game/use-game-state';
import { useHeartbeat } from '@/hooks/game/use-heartbeat';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { fetchGameState } from '@/lib/game/store';
import type { ResyncOutcome } from '@/lib/game/store';
import type { PresetKey } from '@/components/room/preset-picker';
import { PRESET_KEYS } from '@/components/room/preset-picker';
import { heartbeat, show, state as soloState } from '@/routes/solo';
import type { GameStatePacket } from '@/types/game-wire';

/**
 * L'annonce D19 du 23/09 (spec 60 § 16.3, contrat C7 § 4.12) : prop
 * `settingsNotice` de `game/solo`, posée par `solo.store` quand le `N` du
 * preset a été ramené d'office au `N` jouable le plus proche. Des données,
 * jamais une phrase : le client la rend par `game.solo.frames_adjusted`.
 */
export type SoloSettingsNotice = {
    preset: PresetKey;
    requestedFramesPerRound: number;
    appliedFramesPerRound: number;
};

export type UseSoloStateOptions = {
    /** Prop `state`. */
    state: GameStatePacket;
    /** Prop `seatToken`. */
    seatToken: string;
    /** Prop `settingsNotice`. */
    settingsNotice: SoloSettingsNotice | null;
};

export type SoloStateView = GameStateView & {
    /** Onglet actif et siège toujours tenu : le battement part, les gestes aussi. */
    active: boolean;
    /** Ce siège peut écrire : onglet actif, et l'état se lit (connexion). */
    canWrite: boolean;
    /** L'annonce D19, déjà traduite, ou nulle. */
    noticeMessage: string | null;
};

/**
 * L'état de la page `game/solo` (spec 60 § 16.4, 90 § 10, « Solo ») : le
 * magasin de 60, monté par {@see useGameState} avec la lecture du solo
 * (`solo.state`), et ce que le solo y ajoute. **Le solo ne reçoit aucun
 * événement** (aucun canal, 10 § 7.10) : il vit de ses lectures.
 *
 * - **Sondage** : le client tire `solo.state` au montage — ici, une fois par
 *   montage de la page, le double montage de `strictMode` compris —, à
 *   chaque `nextTransitionAt` et à chaque `fetchNotBefore` (minuteurs du
 *   magasin), au retour de visibilité et en ligne (`useGameState`), et après
 *   chaque geste, dont la réponse est le paquet à jour.
 * - **Connexion** : une lecture de `solo.state` en échec (réseau, 5xx) passe
 *   le bandeau à `offline` (90 § 10) jusqu'à la lecture suivante réussie,
 *   que le magasin retente après `heartbeatIntervalMs` ; son retour est
 *   annoncé (`common.connection.restored`) par `useGameState`.
 * - **Battement** (`solo.heartbeat`, § 13.1) : tant que l'onglet tient le
 *   siège ; jamais depuis un onglet supplanté, qui n'écrit plus (§ 12.7).
 *   Un 403 — plus de siège solo tenu par ce jeton — se lit par une
 *   relecture, dont le 403 fait quitter la page.
 * - **Sortie** : un 403 de lecture (`exit`) visite `solo.show`, qui mène à
 *   la page d'entrée `room/solo` quand le jeton ne tient plus de siège.
 * - **Annonce D19** : `settingsNotice` est rendue par la page et annoncée
 *   une fois à son arrivée (`announce()`, C16 § 4) — au premier rendu comme
 *   après une relance.
 *
 * Idempotent sous React Compiler et en mode strict : chaque réaction compare
 * l'état à celui qu'elle a déjà vu (références gardées d'un montage à
 * l'autre), jamais à un compteur local d'effets.
 */
export function useSoloState(options: UseSoloStateOptions): SoloStateView {
    const { t, locale } = useTranslations();
    const [unreachable, setUnreachable] = useState(false);

    const view = useGameState({
        state: options.state,
        seatToken: options.seatToken,
        resync: async (): Promise<ResyncOutcome> => {
            const outcome = await fetchGameState(soloState.url());

            setUnreachable(outcome.kind === 'failed');

            return outcome;
        },
        onExit: () => router.visit(show()),
        unreachable,
    });
    const { state, store, connection } = view;

    const active = state.exit === null && state.self.seatActive;
    const canWrite = active && connection === 'connected';

    // --- Lecture au montage (§ 16.4) ------------------------------------------

    const polled = useRef(false);

    useEffect(() => {
        if (!polled.current) {
            polled.current = true;
            store.requestResync('solo_poll');
        }
    }, [store]);

    // --- Battement (§ 13.1) ----------------------------------------------------

    const heartbeatStatus = useHeartbeat(active ? heartbeat.url() : null);

    useEffect(() => {
        if (heartbeatStatus === 'refused') {
            store.requestResync('heartbeat_refused');
        }
    }, [heartbeatStatus, store]);

    // --- Annonce D19 -------------------------------------------------------------

    const notice = options.settingsNotice;
    const number = new Intl.NumberFormat(locale);
    const noticeMessage =
        notice === null
            ? null
            : t('game.solo.frames_adjusted', {
                  preset: t(PRESET_KEYS[notice.preset].label),
                  requested: number.format(notice.requestedFramesPerRound),
                  applied: number.format(notice.appliedFramesPerRound),
              });
    const announceNotice = useEffectEvent((message: string): void => {
        announce(message);
    });
    const announcedNotice = useRef<SoloSettingsNotice | null>(null);

    useEffect(() => {
        if (
            notice !== null &&
            noticeMessage !== null &&
            announcedNotice.current !== notice
        ) {
            announcedNotice.current = notice;
            announceNotice(noticeMessage);
        }
    }, [notice, noticeMessage]);

    return { ...view, active, canWrite, noticeMessage };
}
