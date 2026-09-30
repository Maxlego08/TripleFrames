import { Head, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { ConnectionBanner } from '@/components/game/connection-banner';
import { GameHelp } from '@/components/game/game-help';
import { GameStage } from '@/components/game/game-stage';
import { NextRoundButton } from '@/components/game/next-round-button';
import { Podium } from '@/components/game/podium';
import { SoloRelaunch } from '@/components/game/solo-relaunch';
import { SoloRoundActions } from '@/components/game/solo-round-actions';
import type { PresetOption } from '@/components/room/preset-picker';
import { LoadingState } from '@/components/state/loading-state';
import { ReadOnlyNotice } from '@/components/state/read-only-notice';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useMaintenanceRefresh } from '@/hooks/game/use-maintenance-refresh';
import { useRoundStage } from '@/hooks/game/use-round-stage';
import { useSoloGestures } from '@/hooks/game/use-solo-gestures';
import { useSoloState } from '@/hooks/game/use-solo-state';
import type {
    SoloSettingsNotice,
    SoloStateView,
} from '@/hooks/game/use-solo-state';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { parseIsoMs } from '@/lib/game/wire';
import type { GameStatePacket } from '@/types/game-wire';
import type { PlatformLimitsPayload } from '@/types/room-settings';

/** Props de la page de partie solo (spec 60 § 10.1 et § 16.4). */
type SoloPageProps = {
    /**
     * Le paquet de 60 : la partie solo en cours du siège, sinon sa dernière
     * partie close pour son podium, sinon le paquet sans partie.
     */
    state: GameStatePacket;
    /** Jeton d'onglet (`ClaimSeatTab`), jamais gardé ailleurs qu'en mémoire. */
    seatToken: string;
    /** `N` ramené d'office à la relance (D19 du 23/09), ou nul. */
    settingsNotice: SoloSettingsNotice | null;
    /** `PlatformLimits::toArray()` : `speedBonusMaxPercent` de l'aide (90 § 7.7). */
    limits: PlatformLimitsPayload;
    /** Les quatre presets et leur `N` jouable le plus proche, pour la relance. */
    presets: PresetOption[];
};

/**
 * La partie solo — `solo.show` (spec 60 § 16 ; 90 § 2.1 et § 10, « Solo ») :
 * **une seule page**, sous `GameLayout`, forcée en sombre, de la première
 * manche au podium et aux relances. Le changement d'écran vient du magasin
 * de 60, jamais d'une navigation ; le solo ne reçoit aucun événement et vit
 * de ses lectures de `solo.state` ({@see useSoloState}).
 *
 * **États de partie** (manche, joueur verrouillé, révélation, pause) : la
 * scène de 60 (`GameStage`, L60-14), sans autres joueurs ni canal, avec
 * les gestes d'entraînement assisté sous la saisie tant qu'elle est ouverte
 * — « Voir la réponse », « Passer la manche » (D18 du 23/09) — et
 * « Manche suivante » pendant la révélation ({@link SoloGame}).
 *
 * **Podium** (partie figée, rang « — ») : le `Podium` de 80, et sous son
 * en-tête la relance — le choix d'un des quatre presets (D19 du 23/09),
 * `solo.store`. **Sans partie à montrer** : la relance seule (§ 16.4).
 * L'absence de mémoire des films déjà vus (`game.solo.no_room_memory`,
 * § 16.7) y est dite. L'aide (`GameHelp`, 90 § 7.7) est rendue en partie
 * hors palier ouvert (décompte, révélation, pause), pour le `N` de la partie
 * en cours, et sur l'écran de relance pour celui de la dernière partie.
 *
 * **États obligatoires** : chargement (paquet attendu, gestes et relance en
 * vol) ; erreur (geste refusé ou en échec, relance refusée, podium
 * illisible — rejouable) rendue dans la page et annoncée, jamais un toast ;
 * déconnexion (`ConnectionBanner` : hors ligne, ou lecture de `solo.state`
 * en échec ; gestes et relance désactivés) ; onglet supplanté
 * (`ReadOnlyNotice`, lecture seule, battement arrêté). Le `N` ramené d'office
 * est annoncé et rendu hors de la scène d'une manche ouverte, qui ne défile
 * jamais. Titre sans paramètre : `room.solo.title`.
 */
export default function Solo({
    state: packet,
    seatToken,
    settingsNotice,
    limits,
    presets,
}: SoloPageProps) {
    const { t } = useTranslations();
    const { maintenance } = usePage().props;
    const solo = useSoloState({ state: packet, seatToken, settingsNotice });
    const {
        state,
        store,
        connection,
        seatNotice,
        active,
        canWrite,
        noticeMessage,
    } = solo;

    // La relance est désactivée pendant un drainage : la page relit le
    // drapeau tant qu'elle l'affiche, sans quoi elle le garderait après sa
    // levée (BUG-P2).
    useMaintenanceRefresh(maintenance && active);

    const gameEnded =
        state.status === 'completed' || state.status === 'interrupted';
    const onPodium =
        state.gameRef !== null && (state.podium !== null || gameEnded);
    // Le paquet d'une partie porte toujours `maxAnswerLength` (snapshot) ;
    // sans lui, l'écran attend la lecture suivante.
    const maxAnswerLength = state.maxAnswerLength;

    const banners = (
        <>
            <ConnectionBanner state={connection} />

            {seatNotice !== null && <ReadOnlyNotice message={seatNotice} />}
        </>
    );
    const notice =
        noticeMessage === null ? null : (
            <Alert role="note">
                <Info aria-hidden="true" />
                <AlertDescription className="text-foreground">
                    {noticeMessage}
                </AlertDescription>
            </Alert>
        );

    // L'aide (90 § 7.7), pour le `N` de la partie du paquet — en cours, ou la
    // dernière pour l'écran de relance (la prop `presets` ne porte pas le `N`
    // d'un preset jouable tel quel) : jamais un littéral, et rien sans partie
    // connue.
    const speedBonusMaxPercent =
        state.framesPerRound === null
            ? undefined
            : limits.speedBonusMaxPercent[String(state.framesPerRound)];
    const help =
        speedBonusMaxPercent === undefined ? null : (
            <div>
                <GameHelp speedBonusMaxPercent={speedBonusMaxPercent} />
            </div>
        );
    const relaunch = (
        <SoloRelaunch
            presets={presets}
            disabled={!canWrite}
            motives={
                maintenance ? [t('common.maintenance.launch_blocked')] : []
            }
            onRefused={announce}
        />
    );

    return (
        <>
            <Head title={t('room.solo.title')} />

            {state.gameRef !== null && !onPodium && maxAnswerLength !== null ? (
                <SoloGame
                    solo={solo}
                    maxAnswerLength={maxAnswerLength}
                    banners={banners}
                    notice={notice}
                    help={help}
                />
            ) : (
                <ScrollArea className="h-full">
                    <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-6">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('room.solo.title')}
                        </h1>

                        {banners}

                        {notice}

                        {onPodium ? (
                            <Podium
                                podium={state.podium}
                                failed={!state.resyncing}
                                onRetry={() => store.requestResync('retry')}
                            >
                                {relaunch}
                            </Podium>
                        ) : state.gameRef === null ? (
                            relaunch
                        ) : (
                            <LoadingState label={t('common.state.loading')} />
                        )}

                        <p className="text-sm text-muted-foreground">
                            {t('game.solo.no_room_memory')}
                        </p>

                        {help}
                    </div>
                </ScrollArea>
            )}
        </>
    );
}

type SoloGameProps = {
    /** L'état de la page (`useSoloState`). */
    solo: SoloStateView;
    /** `maxAnswerLength` du snapshot de la partie (paquet). */
    maxAnswerLength: number;
    /** Bandeaux : connexion, lecture seule. */
    banners: ReactNode;
    /** L'annonce D19 rendue, ou nulle. */
    notice: ReactNode;
    /** L'aide (`GameHelp`) pour le `N` de la partie en cours, ou nulle. */
    help: ReactNode;
};

/**
 * L'état de partie de la page solo, du lancement au podium exclu (spec 60
 * § 16.4 et § 16.5, lot L60-16) : l'horloge d'affichage, les annonces de
 * manche, la valeur du palier et la saisie (`useRoundStage`), les gestes du
 * joueur solo (`useSoloGestures`), et la scène de 60 (`GameStage`). Monté
 * seulement en partie : sans partie, aucune horloge de manche ne tourne.
 *
 * - **Tentatives** : aucune source hors du paquet en solo (pas de réglages de
 *   salon) — la saisie suit `self.input`, que le paquet porte dès la manche
 *   ouverte (`attemptsPerRound` nul).
 * - **Gestes** : « Voir la réponse » et « Passer la manche » sous la saisie
 *   tant qu'elle est ouverte ; « Manche suivante » (`game.solo.next_round`)
 *   pendant la révélation. Chaque réponse est le paquet à jour, appliqué au
 *   magasin ; un échec se dit sous son bouton et s'annonce.
 * - **Annonce D19 et aide** (`GameHelp`, 90 § 7.7) : rendues hors de la scène
 *   d'une manche ouverte — décompte, révélation, pause —, jamais au-dessus
 *   d'une image qui court ni pendant qu'un palier est ouvert.
 */
function SoloGame({
    solo,
    maxAnswerLength,
    banners,
    notice,
    help,
}: SoloGameProps) {
    const { t } = useTranslations();
    const { frameFormat } = usePage().props;
    const { state, store, frames, canWrite } = solo;
    const { clock, tierValue, submission } = useRoundStage({ state, store });
    const gestures = useSoloGestures({
        store,
        gameRef: state.gameRef,
        sequenceIndex: clock.round?.sequenceIndex ?? null,
    });

    const round = clock.round;
    // La scène d'une manche ouverte ne défile jamais (principe 5) : l'annonce
    // D19 n'y prend pas de place, une fois `T₁` franchi et jusqu'à la
    // révélation ; l'aide non plus, jamais pendant un palier ouvert
    // (90 § 7.7).
    const liveScene =
        state.status === 'running' &&
        round !== null &&
        !(round.phase === 'revealing' && round.reveal !== null) &&
        clock.nowMs >= parseIsoMs(round.startsAt);
    const roundError =
        gestures.error !== null && gestures.error.kind !== 'next'
            ? gestures.error.message
            : null;
    const nextError =
        gestures.error?.kind === 'next' ? gestures.error.message : null;
    const roundPending =
        gestures.pending === 'reveal' || gestures.pending === 'skip'
            ? gestures.pending
            : null;

    return (
        <GameStage
            state={state}
            clock={clock}
            tierValue={tierValue}
            submission={submission}
            frames={frames}
            frameFormat={frameFormat}
            attemptsPerRound={null}
            maxAnswerLength={maxAnswerLength}
            title={t('room.solo.title')}
            banners={
                <>
                    {banners}
                    {liveScene ? null : notice}
                    {liveScene ? null : help}
                </>
            }
            revealAction={
                <NextRoundButton
                    label={t('game.solo.next_round')}
                    pending={gestures.pending === 'next'}
                    disabled={!canWrite}
                    error={nextError}
                    onAdvance={() => gestures.run('next')}
                />
            }
            roundActions={
                <SoloRoundActions
                    pending={roundPending}
                    disabled={!canWrite}
                    error={roundError}
                    onReveal={() => gestures.run('reveal')}
                    onSkip={() => gestures.run('skip')}
                />
            }
        />
    );
}
