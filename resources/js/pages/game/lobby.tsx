import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, router, usePage } from '@inertiajs/react';
import { CircleAlert, Play } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import LaunchController from '@/actions/App/Http/Controllers/Room/LaunchController';
import { ConnectionBanner } from '@/components/game/connection-banner';
import { GameHelp } from '@/components/game/game-help';
import { PoolStatus } from '@/components/room/pool-status';
import { ReplayButton } from '@/components/room/replay-button';
import { RoomSettingsForm } from '@/components/room/room-settings-form';
import { LeaveRoomAction } from '@/components/room/seat-actions';
import type { RoomGestureContext } from '@/components/room/seat-actions';
import { SeatList } from '@/components/room/seat-list';
import { ShareCode } from '@/components/room/share-code';
import { ReadOnlyNotice } from '@/components/state/read-only-notice';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { useLobbyState } from '@/hooks/game/use-lobby-state';
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
} from '@/types/room-settings';

/** Aide de grisage d'un preset, calculée au rendu (spec 50 § 5.3). */
type LobbyPresetOption = {
    key: 'classic' | 'fast' | 'hardcore' | 'discovery';
    grayed: boolean;
    nearestPlayableFramesPerRound: number | null;
};

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
    presets: LobbyPresetOption[];
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
 * - pour tous : le code et le lien de partage, les réglages — éditables
 *   par l'hôte seul, dont l'interrupteur des retardataires (§ 15.4) —, le
 *   nombre de joueurs et la liste des sièges, le compteur de vivier et le
 *   blocage, qui nomme le réglage fautif — non-répétition comprise (D28 du
 *   23/09) —, l'aide ;
 * - pour l'hôte : les remèdes du vivier et « Lancer la partie », désactivé
 *   avec son motif quand le vivier est bloqué, quand moins de
 *   `launch.minConnected` sièges sont connectés, ou pendant un drainage
 *   (prop partagée `maintenance`) — une aide seulement : le serveur relit
 *   tout sous verrou ; et, sur chaque autre siège, « Retirer du salon » et
 *   « Nommer hôte » (§ 11.3, § 11.4), au lobby comme en partie ;
 * - pour les autres : l'attente de l'hôte, en lecture seule ;
 * - pour tous : « Quitter le salon », confirmé, qui mène à l'accueil.
 *
 * **États de partie** (manche, révélation, pause, podium) : composants de 60
 * et de 80 à venir (L60-14, L80-7), montés ici selon le magasin. D'ici là,
 * l'état de partie montre la liste des sièges, et au siège sans
 * participation, l'attente de la partie suivante (§ 15.3). Sur le podium
 * (partie figée), « Rejouer » (§ 13, `ReplayButton`) : le geste de l'hôte,
 * désactivé avec son motif pendant un drainage, qui le refuse ; les autres
 * attendent l'hôte. Au retour au lobby, un focus perdu avec l'état de
 * partie démonté revient au titre de la page.
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
    limits,
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
        connection,
        seatNotice,
        settings,
        phase,
        active,
        canWrite,
        onHttpException,
    } = lobby;
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

    // Le podium : la partie est figée (`game.ended`, ou le paquet relu). Le
    // drainage refuse « Rejouer » (§ 14) : le bouton le dit, le serveur
    // décide.
    const onPodium = phase === 'game' && state.podium !== null;
    const replayMotives = maintenance
        ? [t('common.maintenance.launch_blocked')]
        : [];

    return (
        <>
            <Head title={t('room.lobby.title')} />

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

                    <ConnectionBanner state={connection} />

                    {seatNotice !== null && (
                        <ReadOnlyNotice message={seatNotice} />
                    )}

                    {refusal !== null && (
                        <Alert role="note">
                            <CircleAlert aria-hidden="true" />
                            <AlertDescription className="text-foreground">
                                {refusal}
                            </AlertDescription>
                        </Alert>
                    )}

                    {phase === 'lobby' ? (
                        <>
                            <ShareCode code={room.code} url={shareUrl} />

                            <RoomSettingsForm
                                roomCode={room.code}
                                settings={settings.settings}
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
                                                  remedyPending || !canWrite,
                                              onApply: applyRemedy,
                                              error: remedyError,
                                          }
                                        : null
                                }
                            />

                            {isHost ? (
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
                                        const message = firstError(formErrors);

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
                                                        aria-label={undefined}
                                                        className="motion-reduce:animate-none"
                                                    />
                                                ) : (
                                                    <Play aria-hidden="true" />
                                                )}
                                                {processing
                                                    ? t('room.lobby.launching')
                                                    : t('room.lobby.launch')}
                                            </Button>

                                            {motives.length > 0 && (
                                                <ul
                                                    id={motiveId}
                                                    className="flex flex-col gap-1 text-sm text-muted-foreground"
                                                >
                                                    {motives.map((motive) => (
                                                        <li key={motive}>
                                                            {motive}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </>
                                    )}
                                </Form>
                            ) : (
                                <ReadOnlyNotice
                                    message={t('room.lobby.read_only')}
                                />
                            )}

                            <SeatList
                                seats={state.seats}
                                selfPublicId={state.self.publicId}
                                capacity={settings.settings.capacity}
                                actions={isHost ? gestures : null}
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

                            {onPodium && (
                                <ReplayButton
                                    roomCode={room.code}
                                    isHost={isHost}
                                    disabled={!canWrite}
                                    motives={replayMotives}
                                    onHttpException={onHttpException}
                                    onRefused={announce}
                                />
                            )}

                            <SeatList
                                seats={state.seats}
                                selfPublicId={state.self.publicId}
                                capacity={settings.settings.capacity}
                                actions={isHost ? gestures : null}
                            />

                            <div>
                                <LeaveRoomAction {...leaveGesture} />
                            </div>
                        </>
                    )}
                </div>
            </ScrollArea>
        </>
    );
}
