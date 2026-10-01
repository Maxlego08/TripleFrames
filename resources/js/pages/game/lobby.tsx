import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, router, usePage } from '@inertiajs/react';
import { CircleAlert, Play } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import LaunchController from '@/actions/App/Http/Controllers/Room/LaunchController';
import { ConnectionBanner } from '@/components/game/connection-banner';
import { GameHelp } from '@/components/game/game-help';
import { GameStage } from '@/components/game/game-stage';
import { NextRoundButton } from '@/components/game/next-round-button';
import { Podium } from '@/components/game/podium';
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
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { useLobbyState } from '@/hooks/game/use-lobby-state';
import type { LobbyStateView } from '@/hooks/game/use-lobby-state';
import { useMaintenanceRefresh } from '@/hooks/game/use-maintenance-refresh';
import { useNextRound } from '@/hooks/game/use-next-round';
import { useRoundStage } from '@/hooks/game/use-round-stage';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
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
 * : **une seule page du lobby au podium**, sous `GameLayout`, forcée en
 * sombre. Le changement d'écran vient du magasin de 60, jamais d'une
 * navigation : `game.launched` passe à l'état de partie, `room.replayed`
 * ramène au lobby, sans démonter la souscription, l'horloge ni l'annonceur.
 *
 * **État de lobby**, composé ici :
 * - pour tous : le code et le lien de partage, les réglages de l'onglet
 *   Simple (L50-5) — éditables par l'hôte seul, en lecture seule pour les
 *   autres —, leurs avertissements, le nombre de joueurs et la liste des
 *   sièges, le compteur de vivier et le blocage, qui nomme le réglage
 *   fautif — non-répétition comprise (D28 du 23/09) —, l'aide ;
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

    return (
        <>
            <Head title={t('room.lobby.title')} />

            {phase === 'game' && !onPodium ? (
                <LobbyGameStage
                    roomCode={room.code}
                    lobby={lobby}
                    settings={settings.settings}
                    banners={banners}
                    seatsPanel={seatsPanel}
                />
            ) : (
                <ScrollArea className="h-full">
                    <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-6">
                        <header className="flex flex-col gap-1">
                            <h1
                                ref={titleRef}
                                tabIndex={-1}
                                className="text-2xl font-semibold tracking-tight focus-visible:outline-none"
                            >
                                {t('room.lobby.title')}
                            </h1>
                            {phase === 'lobby' && (
                                <p className="text-muted-foreground">
                                    {isHost
                                        ? t('room.lobby.you_are_host')
                                        : t('room.lobby.waiting_for_host')}
                                </p>
                            )}
                        </header>

                        {banners}

                        {phase === 'lobby' ? (
                            <>
                                <ShareCode code={room.code} url={shareUrl} />

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
                                    <SettingsChanges
                                        changes={settingsChanges}
                                    />
                                )}

                                <RoomSettingsForm
                                    roomCode={room.code}
                                    state={settings}
                                    bounds={bounds}
                                    headcount={headcount}
                                    editable={isHost}
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
                                                  disabled:
                                                      remedyPending ||
                                                      !canWrite,
                                                  onApply: applyRemedy,
                                                  error: remedyError,
                                              }
                                            : null
                                    }
                                />

                                {isHost && (
                                    <Form
                                        {...LaunchController.store.form({
                                            room: room.code,
                                        })}
                                        options={{
                                            preserveScroll: true,
                                            preserveState: true,
                                        }}
                                        onHttpException={onHttpException}
                                        onError={(formErrors) => {
                                            const message =
                                                firstError(formErrors);

                                            if (message !== null) {
                                                announce(message);
                                            }
                                        }}
                                        className="flex flex-col gap-2"
                                    >
                                        {({ processing }) => (
                                            <>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        processing ||
                                                        !canWrite ||
                                                        motives.length > 0
                                                    }
                                                    aria-busy={processing}
                                                    aria-describedby={
                                                        motives.length > 0
                                                            ? motiveId
                                                            : undefined
                                                    }
                                                    className="min-h-11 w-full sm:w-auto sm:self-start"
                                                >
                                                    {processing ? (
                                                        <Spinner
                                                            aria-hidden="true"
                                                            role="presentation"
                                                            aria-label={
                                                                undefined
                                                            }
                                                            className="motion-reduce:animate-none"
                                                        />
                                                    ) : (
                                                        <Play aria-hidden="true" />
                                                    )}
                                                    {processing
                                                        ? t(
                                                              'room.lobby.launching',
                                                          )
                                                        : t(
                                                              'room.lobby.launch',
                                                          )}
                                                </Button>

                                                {motives.length > 0 && (
                                                    <ul
                                                        id={motiveId}
                                                        className="flex flex-col gap-1 text-sm text-muted-foreground"
                                                    >
                                                        {motives.map(
                                                            (motive) => (
                                                                <li
                                                                    key={motive}
                                                                >
                                                                    {motive}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}
                                            </>
                                        )}
                                    </Form>
                                )}

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

                                {speedBonusMaxPercent !== undefined && (
                                    <div>
                                        <GameHelp
                                            speedBonusMaxPercent={
                                                speedBonusMaxPercent
                                            }
                                        />
                                    </div>
                                )}
                            </>
                        ) : (
                            <>
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
                            </>
                        )}
                    </div>
                </ScrollArea>
            )}
        </>
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
        />
    );
}
