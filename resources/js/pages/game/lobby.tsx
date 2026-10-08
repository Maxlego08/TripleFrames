import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, Play, Settings, X } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import LaunchController from '@/actions/App/Http/Controllers/Room/LaunchController';
import { ConnectionBanner } from '@/components/game/connection-banner';
import { GameHelp } from '@/components/game/game-help';
import { GameStage } from '@/components/game/game-stage';
import { NextRoundButton } from '@/components/game/next-round-button';
import { PauseButton } from '@/components/game/pause-button';
import { PlayerOrdinalsProvider } from '@/components/game/player-ordinals';
import { Podium } from '@/components/game/podium';
import { AuthBrand } from '@/components/auth/auth-brand';
import { GameToast } from '@/components/game/game-toast';
import type { GameToastMessage } from '@/components/game/game-toast';
import { AvatarDialog } from '@/components/room/avatar-dialog';
import { CinemaSeatMap } from '@/components/room/cinema-seat-map';
import { PoolStatus } from '@/components/room/pool-status';
import { PresetPicker } from '@/components/room/preset-picker';
import type { PresetOption } from '@/components/room/preset-picker';
import { ReplayButton } from '@/components/room/replay-button';
import { RoomSettingsForm } from '@/components/room/room-settings-form';
import { LeaveRoomAction } from '@/components/room/seat-actions';
import type { RoomGestureContext } from '@/components/room/seat-actions';
import { SeatList } from '@/components/room/seat-list';
import { SettingsChanges } from '@/components/room/settings-changes';
import { ShareCode } from '@/components/room/share-code';
import { ReadOnlyNotice } from '@/components/state/read-only-notice';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { useLobbyState } from '@/hooks/game/use-lobby-state';
import type { LobbyStateView } from '@/hooks/game/use-lobby-state';
import { useMaintenanceRefresh } from '@/hooks/game/use-maintenance-refresh';
import { useNextRound } from '@/hooks/game/use-next-round';
import { usePauseGesture } from '@/hooks/game/use-pause-gesture';
import { useRoundStage } from '@/hooks/game/use-round-stage';
import { useSeatAvatar } from '@/hooks/game/use-seat-avatar';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { lobbyAvatarData } from '@/lib/game/lobby-avatars';
import type { LobbyAvatars } from '@/lib/game/lobby-avatars';
import { pauseControlOf } from '@/lib/game/pause-gesture';
import { show } from '@/routes/room';
import { update as updateSettings } from '@/routes/room/settings';
import type { GameStatePacket, LocaleCode } from '@/types/game-wire';
import type { PoolRemedy } from '@/types/pool';
import type {
    PlatformLimitsPayload,
    RoomSettingsBoundsPayload,
    RoomSettingsState,
    RoomSettingsView,
} from '@/types/room-settings';

/** Props de la page du salon (spec 50 § 8.1, `LobbyPageProps`). */
type LobbyPageProps = {
    room: { code: string };
    /** Le paquet de 60 : sans partie au lobby, la partie en `playing`. */
    state: GameStatePacket;
    /** Jeton d'onglet (`ClaimSeatTab`), jamais gardé ailleurs qu'en mémoire. */
    seatToken: string;
    /** L'état des réglages, recalculé à chaque rendu. */
    settings: RoomSettingsState;
    bounds: RoomSettingsBoundsPayload;
    limits: PlatformLimitsPayload;
    /** Aide de grisage de chaque preset, calculée au rendu (§ 5.3). */
    presets: PresetOption[];
    launch: { minConnected: number };
    editor: {
        advancedAvailable: boolean;
        themeSelectorVisible: boolean;
        lateJoinAvailable: boolean;
    };
    /** [J2] Thèmes proposables, `null` tant que le sélecteur est masqué. */
    themes: { key: string; labels: Record<LocaleCode, string> }[] | null;
    /** [J2] Configurations du compte, `null` pour un invité. */
    configs: { key: string; name: string; isDefault: boolean }[] | null;
    /**
     * Le sélecteur d'avatar du siège (D55 du 02/10) : catalogue, clés des
     * autres sièges, choix courant, image du compte. Rechargeable seule.
     */
    avatars: LobbyAvatars;
};

/**
 * Le corps de l'écriture qu'un remède propose (spec 50 § 9.2), ou `null`
 * pour « nouveau salon », qui est un lien. Chaque remède débloque à lui
 * seul ; le serveur relit tout sous le verrou du salon.
 */
function remedyBody(
    remedy: PoolRemedy,
): Record<string, FormDataConvertible> | null {
    switch (remedy.kind) {
        case 'open_new_room':
            return null;
        case 'disable_no_repeat':
            return { advanced: true, noRepeatMovies: false };
        case 'clear_themes':
            return { themeKeys: [] };
        case 'lower_frames_per_round':
            return remedy.value === null
                ? null
                : { framesPerRound: remedy.value };
        case 'reduce_rounds_count':
            return remedy.value === null ? null : { roundsCount: remedy.value };
    }
}

/** Premier message d'erreur d'une écriture refusée, déjà traduit. */
function firstError(errors: Record<string, string>): string | null {
    return Object.values(errors).find((message) => message !== '') ?? null;
}

/**
 * La page du salon — `room.show` (spec 50 § 7.2 et § 8.1 ; 90 § 2.1 et § 10)
 * : **une seule page du lobby au podium**, sous `GameLayout`. Le changement
 * d'écran vient du magasin de 60, jamais d'une navigation : `game.launched`
 * passe à l'état de partie, `room.replayed` ramène au lobby, sans démonter la souscription, l'horloge ni l'annonceur.
 *
 * **État de lobby**, composé ici :
 * - pour tous : le code et le lien de partage, « Votre avatar » — le sélecteur
 *   du siège, au lobby seulement (D55 du 02/10) —, les réglages des onglets
 *   Simple (L50-5) et Avancé (L50-10) — éditables par l'hôte seul, en lecture seule pour les
 *   autres —, leurs avertissements, le nombre de joueurs et la liste des
 *   sièges, le compteur de vivier et le blocage, qui nomme le réglage fautif —
 *   non-répétition comprise (D28 du 23/09) —, l'aide ;
 * - pour l'hôte : les presets du site, grisés quand le vivier du salon ne
 *   les tient pas (§ 5.3), le rapport des réglages que le serveur a ajustés
 *   de lui-même à sa dernière écriture (§ 2.6), les remèdes du vivier et
 *   « Lancer la partie », désactivé
 *   avec son motif quand le vivier est bloqué, quand moins de
 *   `launch.minConnected` sièges sont connectés, ou pendant un drainage
 *   (prop partagée `maintenance`) — une aide seulement : le serveur relit
 *   tout sous verrou ; et, sur chaque autre siège, « Retirer du salon » et
 *   « Nommer hôte » (§ 11.3, § 11.4), au lobby comme en partie ;
 * - pour les autres : l'attente de l'hôte, en lecture seule ;
 * - pour tous : « Quitter le salon », confirmé, qui mène à l'accueil.
 *
 * **États de partie** (manche, joueur verrouillé, révélation, pause) : la
 * scène de 60 (`GameStage`, L60-14), montée par {@link LobbyGameStage} selon
 * le magasin — manche plein écran sans défilement, liste des sièges, gestes
 * d'hôte et départ dans la feuille « Joueurs » ; révélation, pause et
 * chargement dans une zone qui défile, sièges et départ dessous ; « manche
 * suivante » à l'hôte seul, pendant la révélation. Au siège sans
 * participation, l'attente de la partie suivante (§ 15.3) ; au retardataire
 * admis, l'attente de sa manche (60 § 13.7). Sur le podium (partie figée),
 * le `Podium` de 80 (L80-7 : classement final, faits marquants,
 * récapitulatif ; focus à son titre), composé autour de « Rejouer » (§ 13,
 * `ReplayButton`) : le geste de l'hôte, désactivé avec son motif pendant un
 * drainage, qui le refuse ; les autres attendent l'hôte. Au retour au lobby,
 * un focus perdu avec l'état de partie démonté revient au titre de la page.
 *
 * États obligatoires (§ 8.1) : chargement (`processing` des boutons),
 * erreur (refus de lancement, de remède ou de geste d'hôte — erreur `room`
 * ou `publicId` — rendu dans la page, en `Alert` au rôle `note`, et annoncé —
 * jamais un toast, aucun `Toaster` sous `GameLayout`), déconnexion
 * (`ConnectionBanner`, contrôles d'hôte désactivés, départ toujours
 * possible), onglet supplanté
 * (`ReadOnlyNotice`, 409 intercepté). Le titre ne porte jamais le code
 * (§ 6.5) : `room.lobby.title`.
 */
export default function Lobby({
    room,
    state: packet,
    seatToken,
    settings: settingsProp,
    bounds,
    limits,
    presets,
    launch,
    editor,
    avatars,
}: LobbyPageProps) {
    const { t, locale } = useTranslations();
    const { errors, maintenance } = usePage().props;
    const lobby = useLobbyState({
        code: room.code,
        state: packet,
        seatToken,
        settings: settingsProp,
    });
    const {
        state,
        store,
        connection,
        seatNotice,
        settings,
        phase,
        active,
        canWrite,
        onHttpException,
        settingsChanges,
    } = lobby;

    // « Lancer la partie » et « Rejouer » sont désactivés pendant un
    // drainage : la page relit le drapeau tant qu'elle l'affiche, sans quoi
    // elle le garderait après sa levée (BUG-P2).
    useMaintenanceRefresh(maintenance && active);

    const [remedyPending, setRemedyPending] = useState(false);
    const [remedyError, setRemedyError] = useState<string | null>(null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [avatarOpen, setAvatarOpen] = useState(false);
    // Toast d'erreur visuel (`GameToast`) ; le même texte part à l'annonceur,
    // seule région `aria-live` d'une page de jeu.
    const [toast, setToast] = useState<GameToastMessage | null>(null);
    const showToast = (message: string): void => {
        setToast((previous) => ({ id: (previous?.id ?? 0) + 1, message }));
        announce(message);
    };
    const dismissToast = useCallback(() => setToast(null), []);
    const motiveId = useId();
    const titleRef = useRef<HTMLHeadingElement>(null);
    const previousPhase = useRef(phase);

    // Retour au lobby (« Rejouer ») : l'état de partie est démonté avec le
    // contrôle qui avait le focus — le bouton « Rejouer » lui-même chez
    // l'hôte. Le focus perdu revient au titre, jamais volé à un contrôle
    // qui l'a gardé. Idempotent au double montage de `strictMode`.
    useEffect(() => {
        const before = previousPhase.current;

        previousPhase.current = phase;

        if (before !== 'game' || phase !== 'lobby') {
            return;
        }

        const focused = document.activeElement;

        if (focused === null || focused === document.body) {
            titleRef.current?.focus();
        }
    }, [phase]);

    const isHost = state.self.isHost;
    const pool = settings.pool;
    const framesPerRound = settings.settings.framesPerRound;
    const speedBonusMaxPercent =
        limits.speedBonusMaxPercent[String(framesPerRound)];
    // Refus d'un geste rendu dans la page : erreur `room` (lancement,
    // retrait de soi-même, remède) ou `publicId` (transfert vers un siège
    // non connecté, § 11.4).
    const refusal =
        typeof errors.room === 'string'
            ? errors.room
            : typeof errors.publicId === 'string'
              ? errors.publicId
              : null;
    const shareUrl = new URL(
        show.url({ room: room.code }),
        window.location.origin,
    ).href;
    const connectedSeats = state.seats.filter(
        (seat) => seat.connection === 'connected' && !seat.kicked,
    ).length;
    // Effectif présent (§ 10) : sièges ni partis ni expulsés — la capacité
    // n'est jamais abaissée en dessous.
    const headcount = state.seats.filter(
        (seat) => seat.connection !== 'left' && !seat.kicked,
    ).length;
    const number = new Intl.NumberFormat(locale);
    const motives: string[] = [];

    if (maintenance) {
        motives.push(t('common.maintenance.launch_blocked'));
    }

    if (pool.blocked) {
        motives.push(t('room.pool.blocked'));
    }

    if (connectedSeats < launch.minConnected) {
        motives.push(
            t('room.lobby.need_players', {
                min: number.format(launch.minConnected),
            }),
        );
    }

    const applyRemedy = (remedy: PoolRemedy): void => {
        const body = remedyBody(remedy);

        if (body === null) {
            return;
        }

        router.patch(updateSettings.url({ room: room.code }), body, {
            preserveScroll: true,
            preserveState: true,
            onHttpException,
            onStart: () => {
                setRemedyPending(true);
                setRemedyError(null);
            },
            onError: (fieldErrors) => {
                const message = firstError(fieldErrors);

                setRemedyError(message);

                if (message !== null) {
                    announce(message);
                }
            },
            onFinish: () => setRemedyPending(false),
        });
    };

    // Gestes de salon (§ 11.3, § 11.4) : retirer et nommer hôte pour l'hôte,
    // quitter pour tous — au lobby comme en partie (§ 11.1). Les gestes
    // d'hôte attendent la connexion (§ 8.1, état « déconnexion ») ; le
    // départ, geste HTTP de tout joueur, ne cède qu'à un onglet supplanté ou
    // à un siège déjà sorti.
    const gestures: RoomGestureContext = {
        roomCode: room.code,
        disabled: !canWrite,
        onHttpException,
        onRefused: announce,
    };
    const leaveGesture: RoomGestureContext = { ...gestures, disabled: !active };

    // L'avatar du siège (D55 du 02/10, amendé le 06/10) : flèches ‹ › et
    // grille du clic sur l'avatar, optimistes et regroupés (`useSeatAvatar`).
    const seatAvatar = useSeatAvatar({
        roomCode: room.code,
        avatars,
        onHttpException,
        onRefused: announce,
        onTooManyRequests: () => showToast(t('room.lobby.avatar.too_fast')),
    });

    // En partie, les sièges sont les participations gelées au lancement
    // (`firstRoundNumber` 1) ou à l'admission d'un retardataire (sa manche
    // d'entrée) : un siège qui n'y figure pas, ou qui n'y figure qu'en vue de
    // lobby (`firstRoundNumber` nul — son propre `seat.updated` de présence,
    // reçu en partie), n'a aucune participation et attend la partie suivante
    // (§ 15.3). Le retardataire admis, lui, attend sa manche (`member` faux).
    const selfSeat = state.seats.find(
        (seat) => seat.publicId === state.self.publicId,
    );
    const waitingNextGame =
        phase === 'game' &&
        (selfSeat === undefined || selfSeat.firstRoundNumber === null);

    // Le podium (80 § 11, L80-7) : la partie est figée — `game.ended`, ou le
    // paquet relu, qui le rejoue à l'identique jusqu'à l'archivage. Un statut
    // terminal sans podium (paquet incomplet) le dit attendu : chargement
    // pendant la relecture, erreur rejouable sinon, « réessayer » relisant
    // l'état. Le drainage refuse « Rejouer » (§ 14) : le bouton le dit, le
    // serveur décide.
    const gameEnded =
        state.status === 'completed' || state.status === 'interrupted';
    const onPodium = phase === 'game' && (state.podium !== null || gameEnded);
    const replayMotives = maintenance
        ? [t('common.maintenance.launch_blocked')]
        : [];

    // Bandeaux communs aux deux états : connexion, onglet supplanté, refus
    // d'un geste (§ 8.1).
    const banners = (
        <>
            <ConnectionBanner state={connection} />

            {seatNotice !== null && <ReadOnlyNotice message={seatNotice} />}

            {/* Avis discret au seul siège masqué (spec 40 § 13.3, n° 26) :
                il se reconnaît dans sa propre identité, rien n'est diffusé
                aux autres. */}
            {selfSeat?.masked === true && (
                <Alert role="note">
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription className="text-foreground">
                        {t('common.player.masked_notice')}
                    </AlertDescription>
                </Alert>
            )}

            {refusal !== null && (
                <Alert role="note">
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription className="text-foreground">
                        {refusal}
                    </AlertDescription>
                </Alert>
            )}
        </>
    );

    // La liste des sièges et le départ, en partie (§ 11.1) : gestes d'hôte
    // et « Quitter le salon » restent atteignables à tout instant.
    const seatsPanel = (
        <>
            <SeatList
                seats={state.seats}
                selfPublicId={state.self.publicId}
                capacity={settings.settings.capacity}
                actions={isHost ? gestures : null}
                reports={gestures}
            />

            <div>
                <LeaveRoomAction {...leaveGesture} />
            </div>
        </>
    );

    const lobbySettings = (
        <div className="waiting-room-dialog__sections">
            {isHost && (
                <PresetPicker
                    roomCode={room.code}
                    presets={presets}
                    disabled={!canWrite}
                    onHttpException={onHttpException}
                    onRefused={announce}
                />
            )}

            {settingsChanges !== null && (
                <SettingsChanges changes={settingsChanges} />
            )}

            <RoomSettingsForm
                roomCode={room.code}
                state={settings}
                bounds={bounds}
                limits={limits}
                headcount={headcount}
                editable={isHost}
                advancedAvailable={editor.advancedAvailable}
                lateJoinAvailable={editor.lateJoinAvailable}
                disabled={!canWrite}
                onHttpException={onHttpException}
                onRefused={announce}
            />

            <PoolStatus
                pool={pool}
                remedies={
                    isHost
                        ? {
                              disabled: remedyPending || !canWrite,
                              onApply: applyRemedy,
                              error: remedyError,
                          }
                        : null
                }
            />

            <SeatList
                seats={state.seats}
                selfPublicId={state.self.publicId}
                capacity={settings.settings.capacity}
                actions={isHost ? gestures : null}
                reports={gestures}
            />

            {speedBonusMaxPercent !== undefined && (
                <GameHelp speedBonusMaxPercent={speedBonusMaxPercent} />
            )}
        </div>
    );

    const launchControl = isHost ? (
        <Form
            {...LaunchController.store.form({ room: room.code })}
            options={{ preserveScroll: true, preserveState: true }}
            onHttpException={onHttpException}
            onError={(formErrors) => {
                const message = firstError(formErrors);

                if (message !== null) {
                    announce(message);
                }
            }}
            className="waiting-room-launch"
        >
            {({ processing }) => (
                <>
                    <Button
                        type="submit"
                        disabled={processing || !canWrite || motives.length > 0}
                        aria-busy={processing}
                        aria-describedby={
                            motives.length > 0 ? motiveId : undefined
                        }
                        className="waiting-room-action waiting-room-action--launch"
                    >
                        {processing
                            ? t('room.lobby.launching')
                            : t('room.lobby.launch')}
                        {processing ? (
                            <Spinner
                                aria-hidden="true"
                                role="presentation"
                                aria-label={undefined}
                                className="motion-reduce:animate-none"
                            />
                        ) : (
                            <Play aria-hidden="true" />
                        )}
                    </Button>

                    {motives.length > 0 && (
                        <ul
                            id={motiveId}
                            className="waiting-room-launch__motives"
                        >
                            {motives.map((motive) => (
                                <li key={motive}>{motive}</li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </Form>
    ) : (
        <p className="waiting-room__host-status">
            {t('room.lobby.waiting_for_host')}
        </p>
    );

    return (
        <PlayerOrdinalsProvider seats={state.seats}>
            <Head title={t('room.lobby.title')} />

            {phase === 'game' && !onPodium ? (
                <LobbyGameStage
                    roomCode={room.code}
                    lobby={lobby}
                    settings={settings.settings}
                    banners={banners}
                    seatsPanel={seatsPanel}
                />
            ) : phase === 'lobby' ? (
                <div className="waiting-room-scroll h-full overflow-auto">
                    <div className="waiting-room">
                        <h1 ref={titleRef} tabIndex={-1} className="sr-only">
                            {t('room.lobby.title')}
                        </h1>

                        <div className="waiting-room__topbar">
                            <AuthBrand />
                            <p className="waiting-room__players">
                                {t('room.lobby.player_count', {
                                    count: number.format(headcount),
                                    capacity: number.format(
                                        settings.settings.capacity,
                                    ),
                                })}
                            </p>
                        </div>

                        <div className="waiting-room__notices">{banners}</div>

                        <ShareCode code={room.code} url={shareUrl} />

                        <CinemaSeatMap
                            seats={state.seats}
                            selfPublicId={state.self.publicId}
                            onCycleAvatar={seatAvatar.cycle}
                            avatarBusy={!canWrite}
                            selfAvatar={
                                selfSeat === undefined
                                    ? null
                                    : lobbyAvatarData(
                                          seatAvatar.effective,
                                          avatars,
                                          selfSeat.avatar,
                                      )
                            }
                            onOpenAvatar={() => {
                                setAvatarOpen(true);
                                // Clés prises fraîches à l'ouverture.
                                router.reload({
                                    only: ['avatars'],
                                    onHttpException: (response) => {
                                        if (response.status === 429) {
                                            showToast(
                                                t('room.lobby.avatar.too_fast'),
                                            );

                                            return false;
                                        }

                                        return onHttpException(response);
                                    },
                                });
                            }}
                        />

                        <GameToast toast={toast} onDismiss={dismissToast} />

                        <AvatarDialog
                            open={avatarOpen}
                            onOpenChange={setAvatarOpen}
                            avatars={avatars}
                            value={seatAvatar.effective}
                            onChoose={seatAvatar.choose}
                            disabled={!canWrite}
                        />

                        <div className="waiting-room__actions">
                            <div className="waiting-room-leave">
                                <LeaveRoomAction
                                    {...leaveGesture}
                                    icon={ArrowLeft}
                                />
                            </div>

                            {launchControl}

                            <Dialog
                                open={settingsOpen}
                                onOpenChange={setSettingsOpen}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="waiting-room-action waiting-room-action--settings"
                                    >
                                        <Settings aria-hidden="true" />
                                        {t('room.lobby.configure')}
                                    </Button>
                                </DialogTrigger>

                                <DialogContent className="waiting-room-dialog [&>button:last-child]:hidden">
                                    <DialogHeader className="waiting-room-dialog__header">
                                        <div className="waiting-room-dialog__heading">
                                            <DialogTitle>
                                                {t('room.lobby.settings_title')}
                                            </DialogTitle>
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <X aria-hidden="true" />
                                                    {t('common.action.close')}
                                                </Button>
                                            </DialogClose>
                                        </div>
                                        <DialogDescription>
                                            {isHost
                                                ? t('room.lobby.you_are_host')
                                                : t('room.lobby.read_only')}
                                        </DialogDescription>
                                    </DialogHeader>

                                    <ScrollArea className="waiting-room-dialog__scroll">
                                        {lobbySettings}
                                    </ScrollArea>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </div>
                </div>
            ) : (
                <ScrollArea className="h-full">
                    <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-6">
                        <h1
                            ref={titleRef}
                            tabIndex={-1}
                            className="text-2xl font-semibold tracking-tight focus-visible:outline-none"
                        >
                            {t('room.lobby.title')}
                        </h1>

                        {banners}

                        {waitingNextGame && (
                            <p className="text-muted-foreground">
                                {t('room.lobby.waiting_next_game')}
                            </p>
                        )}

                        <Podium
                            podium={state.podium}
                            failed={!state.resyncing}
                            onRetry={() => store.requestResync('retry')}
                        >
                            <ReplayButton
                                roomCode={room.code}
                                isHost={isHost}
                                disabled={!canWrite}
                                motives={replayMotives}
                                onHttpException={onHttpException}
                                onRefused={announce}
                            />
                        </Podium>

                        {seatsPanel}
                    </div>
                </ScrollArea>
            )}
        </PlayerOrdinalsProvider>
    );
}

type LobbyGameStageProps = {
    /** Code du salon (prop `room.code`), pour Wayfinder. */
    roomCode: string;
    /** L'état de la page (`useLobbyState`). */
    lobby: LobbyStateView;
    /**
     * Les réglages du salon, figés du lancement au podium (00 § Réglages
     * figés au lancement) : ceux du snapshot de la partie.
     */
    settings: RoomSettingsView;
    banners: ReactNode;
    seatsPanel: ReactNode;
};

/**
 * L'état de partie de la page du salon, du lancement au podium exclu (spec
 * 60 § 10.1 et § 11.8, lot L60-14) : l'horloge d'affichage, les annonces de
 * manche, la valeur du palier et la saisie (`useRoundStage`), le geste
 * « manche suivante » de l'hôte (`useNextRound`), et la scène de 60
 * (`GameStage`). Monté seulement en partie : au lobby, aucune horloge de
 * manche ne tourne.
 *
 * Deux replis, lus dans les réglages du salon, figés du lancement au podium
 * — même lecture que `maxAnswerLength` au `game.launched` du magasin :
 * `attemptsPerRound`, tant que la saisie du siège est inconnue (début de
 * manche, avant toute soumission : la ligne naît `open`, toutes tentatives
 * restantes) ; `maxAnswerLength`, si le paquet ne l'a pas encore porté.
 */
function LobbyGameStage({
    roomCode,
    lobby,
    settings,
    banners,
    seatsPanel,
}: LobbyGameStageProps) {
    const { t } = useTranslations();
    const { frameFormat } = usePage().props;
    const { state, store, frames, canWrite } = lobby;
    const { clock, tierValue, submission } = useRoundStage({ state, store });
    const revealed = clock.round?.phase === 'revealing' ? clock.round : null;
    const nextRound = useNextRound({
        roomCode,
        gameRef: state.gameRef,
        sequenceIndex: revealed?.sequenceIndex ?? null,
    });
    // Pause manuelle (D64 du 07/10) : l'hôte met en pause ou retire sa
    // demande ; l'hôte reprend, ou tout siège présent s'il ne l'est plus.
    const pauseControl = pauseControlOf(state, clock.nowMs);
    const pause = usePauseGesture({
        store,
        roomCode,
        stateKey:
            state.gameRef === null
                ? null
                : `${state.gameRef}|${state.status ?? '-'}|${String(state.pauseRequested)}`,
    });

    return (
        <GameStage
            state={state}
            clock={clock}
            tierValue={tierValue}
            submission={submission}
            frames={frames}
            frameFormat={frameFormat}
            attemptsPerRound={settings.attemptsPerRound}
            maxAnswerLength={state.maxAnswerLength ?? settings.maxAnswerLength}
            title={t('room.lobby.title')}
            banners={banners}
            seatsPanel={seatsPanel}
            revealAction={
                state.self.isHost ? (
                    <NextRoundButton
                        label={t('game.host.next_round')}
                        pending={nextRound.pending}
                        disabled={!canWrite}
                        error={nextRound.error}
                        onAdvance={nextRound.advance}
                    />
                ) : null
            }
            pauseControl={
                pauseControl === null ? null : (
                    <PauseButton
                        kind={pauseControl.kind}
                        pending={pause.pending !== null}
                        disabled={!canWrite}
                        error={pause.error}
                        onPress={pause.run}
                    />
                )
            }
            pauseHostAbsent={pauseControl?.hostAbsent ?? false}
        />
    );
}
