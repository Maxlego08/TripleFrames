import { useEffect, useEffectEvent, useRef } from 'react';
import type { ReactNode } from 'react';
import { CirclePause, Star } from 'lucide-react';
import { AuthBrand } from '@/components/auth/auth-brand';
import type { FrameFormat } from '@/components/game/game-frame';
import { GamePaused } from '@/components/game/game-paused';
import { RoundInput } from '@/components/game/round-input';
import { PlayersSheet, RoundPlayers } from '@/components/game/round-players';
import { RoundReveal } from '@/components/game/round-reveal';
import { RoundScene } from '@/components/game/round-scene';
import type {
    RoundSceneFrame,
    RoundSceneStage,
} from '@/components/game/round-scene';
import { LoadingState } from '@/components/state/loading-state';
import { ScrollArea } from '@/components/ui/scroll-area';
import type { AnswerSubmission } from '@/hooks/game/use-answer-submission';
import type { GameFrames } from '@/hooks/game/use-game-state';
import type { RoundClockView } from '@/hooks/game/use-round-clock';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { isMemberOfRound, roundKeyOf } from '@/lib/game/store';
import type { GameStoreState } from '@/lib/game/store';
import { parseIsoMs } from '@/lib/game/wire';
import type { RoundState } from '@/types/game-wire';
import type { TranslationKey } from '@/types/translations';

export type GameStageProps = {
    /** L'état du magasin de la page (`useGameState().state`), en partie. */
    state: GameStoreState;
    /** L'horloge d'affichage (`useRoundStage().clock`). */
    clock: RoundClockView;
    /** La valeur du palier, déjà masquée par `visibleTierValue()`. */
    tierValue: number | null;
    /** La saisie de la manche montrée (`useRoundStage().submission`). */
    submission: AnswerSubmission;
    /** Les images de jeu (`useGameState().frames`). */
    frames: GameFrames;
    /** Prop partagée `frameFormat` (C9). */
    frameFormat: FrameFormat;
    /**
     * Tentatives de texte d'une manche, repli de `attemptsLeft` tant que la
     * saisie du siège est inconnue (début de manche, avant toute soumission) :
     * `attemptsPerRound` des réglages figés du lancement au podium. Nul
     * quand la page n'en a aucune source — en solo, sans réglages de salon :
     * la saisie attend alors la vue de saisie du paquet (`self.input`), que
     * le solo reçoit toujours avec la manche ouverte, puisqu'il ne vit que de
     * paquets (60 § 16.4).
     */
    attemptsPerRound: number | null;
    /** `maxAnswerLength` de la partie (paquet), à défaut des réglages figés. */
    maxAnswerLength: number;
    /** Titre de la page, déjà traduit (`h1`, `sr-only` pendant la manche). */
    title: string;
    /** Bandeaux de la page : connexion, lecture seule, refus d'un geste. */
    banners: ReactNode;
    /**
     * Sièges, gestes d'hôte et départ : dans la feuille « Joueurs » pendant
     * la manche, sous l'écran ailleurs. Nul en solo.
     */
    seatsPanel?: ReactNode;
    /** Le geste « manche suivante » de la révélation ; nul sinon. */
    revealAction?: ReactNode;
    /**
     * Gestes de la manche en cours, sous la saisie, tant que la saisie du
     * siège est ouverte (`open` ou `text_exhausted`) : en solo, « Voir la
     * réponse » et « Passer la manche » (60 § 16.5, D18 du 23/09). Jamais en
     * multijoueur (barrière 1 de 10 § 7.10, `lone_player` compris, § 9.3).
     */
    roundActions?: ReactNode;
    /**
     * Le geste de pause manuelle offert au siège (D64 du 07/10) : « Pause »
     * ou « Annuler la pause » pendant la partie, « Reprendre » sur l'écran de
     * pause ; nul sans autorité (`pauseControlOf()`).
     */
    pauseControl?: ReactNode;
    /** « Reprendre » est offert parce que l'hôte n'est plus un siège présent. */
    pauseHostAbsent?: boolean;
};

/**
 * Le dernier palier ouvert à `elapsedMs` du début de la manche : fenêtres
 * semi-ouvertes, `inclusive` pour un instant d'affichage (le palier qui
 * s'ouvre à cet instant compte), exclusif pour `ended_at` (un palier qui
 * s'ouvrirait à la clôture ne s'ouvre jamais, 60 § 4.3).
 */
function lastOpenedTier(
    round: RoundState,
    elapsedMs: number,
    inclusive: boolean,
): number {
    let opened = 1;

    for (const tier of round.tiers) {
        const started = inclusive
            ? tier.startsAtOffsetMs <= elapsedMs
            : tier.startsAtOffsetMs < elapsedMs;

        if (started && tier.tierIndex > opened) {
            opened = tier.tierIndex;
        }
    }

    return opened;
}

/** Où en est la manche montrée, vu de l'écran (voir `RoundSceneStage`). */
function stageOf(round: RoundState, nowMs: number): RoundSceneStage {
    if (round.phase === 'cancelled') {
        return 'cancelled';
    }

    if (
        round.endedAt !== null ||
        round.phase === 'closed' ||
        round.phase === 'revealing'
    ) {
        return 'closed';
    }

    return nowMs < parseIsoMs(round.startsAt) ? 'countdown' : 'running';
}

/** Une seconde en millisecondes : une unité, pas une valeur de jeu. */
const MS_PER_SECOND = 1000;

/**
 * Manches dont l'annulation a déjà été annoncée, au niveau du module : le
 * double montage de `strictMode` et un remontage de l'écran ne la redisent
 * pas (même patron que l'annonce du QCM, 70).
 */
const announcedCancellations = new Set<string>();

/**
 * L'état de partie d'une page de jeu (spec 60 § 10.1, § 11.8 ; 90 § 10) :
 * manche, joueur verrouillé, révélation, pause — **sans navigation**, sur
 * le seul état du magasin. Le podium reste à la page (80, L80-7, avec
 * « Rejouer » de 50).
 *
 * - **Pause** (`status = paused`) : l'écran de pause, heure de clôture
 *   comprise.
 * - **Aucune manche à montrer** (juste après le lancement, entre la fin de
 *   la dernière révélation et le podium, pendant une relecture) :
 *   chargement.
 * - **Révélation** (phase `revealing`, paquet reçu) : `RoundReveal`, dans une
 *   zone qui défile, suivie des sièges et du départ.
 * - **Sinon, la manche** — décompte, en cours, close avant la révélation,
 *   annulée : `RoundScene`, **plein écran sans défilement** (principe 5),
 *   titre de page en `sr-only`, sièges et gestes de salon dans la feuille
 *   « Joueurs ».
 *
 * Règles du siège, lues sur les sièges gelés de la partie :
 * - un siège **membre** de la manche montrée voit ses images et sa saisie ;
 * - un **retardataire admis** avant sa manche voit le chrono, le fil « a
 *   trouvé » et la révélation, mais aucune image (que le service lui refuse)
 *   et aucune saisie : `game.round.waiting_next` (60 § 13.7) ;
 * - un siège **sans participation** (entré pendant la partie) attend la
 *   partie suivante : `room.lobby.waiting_next_game` (50 § 15.3) ;
 * - un membre sans ligne de manche (parti à `T₁`, revenu depuis) entre à la
 *   manche suivante (60 § 13.6).
 *
 * **En solo** (`game/solo`, L60-16) : aucune bande des joueurs — le seul
 * siège est le joueur —, et les gestes d'entraînement de la page
 * (`roundActions`) sous la saisie tant qu'elle est ouverte.
 *
 * Composant de présentation : ni Echo, ni horloge, ni requête (C16 § 2.9) —
 * l'instant et la manche montrée viennent de `clock`.
 */
export function GameStage({
    state,
    clock,
    tierValue,
    submission,
    frames,
    frameFormat,
    attemptsPerRound,
    maxAnswerLength,
    title,
    banners,
    seatsPanel,
    revealAction,
    roundActions,
    pauseControl = null,
    pauseHostAbsent = false,
}: GameStageProps) {
    const { t, tChoice, locale } = useTranslations();
    const number = new Intl.NumberFormat(locale);
    const gameRef = state.gameRef;
    const round = clock.round;
    const nowMs = clock.nowMs;

    // La pause manuelle (D64 du 07/10) se dit dans l'unique région vivante
    // (C16 § 4) à ses transitions seulement — demandée, retirée, reprise —,
    // jamais au montage : l'écran de pause, lui, prend le focus.
    const pauseRequested = state.pauseRequested;
    const paused = state.status === 'paused';
    const previousPause = useRef({ gameRef, pauseRequested, paused });
    const announcePause = useEffectEvent((key: TranslationKey): void => {
        announce(t(key));
    });

    useEffect(() => {
        const previous = previousPause.current;

        previousPause.current = { gameRef, pauseRequested, paused };

        if (previous.gameRef !== gameRef) {
            return;
        }

        if (pauseRequested && !previous.pauseRequested) {
            announcePause('game.pause.requested_announce');
        } else if (!pauseRequested && previous.pauseRequested && !paused) {
            announcePause('game.pause.request_cancelled_announce');
        } else if (!paused && previous.paused) {
            announcePause('game.pause.resumed_announce');
        }
    }, [gameRef, pauseRequested, paused]);

    // Une annulation remplace l'image par un texte, qu'un lecteur d'écran
    // n'entendrait pas : elle passe par l'unique région vivante (C16 § 4),
    // une fois par manche. Sans motif ni titre (60 § 15.2).
    const cancelledKey =
        gameRef !== null && round?.phase === 'cancelled'
            ? roundKeyOf(gameRef, round.sequenceIndex)
            : null;
    const announceCancelled = useEffectEvent((): void => {
        announce(t('game.round.cancelled'));
    });

    useEffect(() => {
        if (
            cancelledKey !== null &&
            !announcedCancellations.has(cancelledKey)
        ) {
            announcedCancellations.add(cancelledKey);
            announceCancelled();
        }
    }, [cancelledKey]);

    if (gameRef === null) {
        return null;
    }

    const startsIn = (instantMs: number): string => {
        const seconds = Math.max(
            0,
            Math.ceil((instantMs - nowMs) / MS_PER_SECOND),
        );

        return tChoice('game.round.starts_in', seconds, {
            seconds: number.format(seconds),
        });
    };

    // Manche d'entrée du siège dans la partie : nulle, aucune participation.
    const entry =
        state.seats.find((seat) => seat.publicId === state.self.publicId)
            ?.firstRoundNumber ?? null;
    const waitingFor = (roundNumber: number | null): string | null => {
        if (entry === null) {
            return t('room.lobby.waiting_next_game');
        }

        return roundNumber !== null && entry > roundNumber
            ? t('game.round.waiting_next', { number: number.format(entry) })
            : null;
    };

    const scrolling = (content: ReactNode, waiting: string | null) => (
        <ScrollArea className="h-full">
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>

                {banners}

                {pauseRequested && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <CirclePause
                            aria-hidden="true"
                            className="size-4 shrink-0"
                        />
                        {t('game.pause.requested')}
                    </p>
                )}

                {pauseControl}

                {waiting !== null && (
                    <p className="text-muted-foreground">{waiting}</p>
                )}

                {content}

                {seatsPanel}
            </div>
        </ScrollArea>
    );

    // La barre du haut de l'écran de manche (maquette `game.html`) : la
    // marque à gauche ; « Joueurs » et le geste de pause à droite.
    const topbar = (withPause: boolean) => (
        <header className="game__topbar">
            <AuthBrand />
            <div className="game__controls">
                {seatsPanel !== undefined && seatsPanel !== null && (
                    <PlayersSheet panel={seatsPanel} />
                )}
                {withPause && pauseControl}
            </div>
        </header>
    );

    const notices = (waiting: string | null) => (
        <div className="game__notices">
            {banners}
            {pauseRequested && (
                <p className="game__notice">
                    <CirclePause aria-hidden="true" />
                    {t('game.pause.requested')}
                </p>
            )}
            {waiting !== null && <p className="game__notice">{waiting}</p>}
        </div>
    );

    if (state.status === 'paused' && state.pause !== null) {
        return (
            <div className="game game--paused">
                <h1 className="sr-only">{title}</h1>
                {topbar(false)}
                {notices(waitingFor(null))}
                <GamePaused
                    interruptsAt={state.pause.interruptsAt}
                    kind={state.pause.kind}
                    solo={state.mode === 'solo'}
                    remainingMs={
                        state.pause.kind === 'manual'
                            ? parseIsoMs(state.pause.interruptsAt) - nowMs
                            : null
                    }
                    action={pauseControl}
                    hostAbsent={pauseHostAbsent}
                />
            </div>
        );
    }

    if (round === null) {
        return scrolling(
            <LoadingState label={t('common.state.loading')} />,
            waitingFor(null),
        );
    }

    const key = roundKeyOf(gameRef, round.sequenceIndex);
    const member = isMemberOfRound(state.seats, state.self.publicId, round);
    const waiting = waitingFor(round.roundNumber);
    const next =
        state.rounds.find(
            (each) =>
                each.sequenceIndex !== round.sequenceIndex &&
                each.phase === 'scheduled' &&
                parseIsoMs(each.startsAt) > nowMs,
        ) ?? null;

    if (round.phase === 'revealing' && round.reveal !== null) {
        return (
            <div className="game game--reveal">
                <h1 className="sr-only">{title}</h1>
                {topbar(true)}
                {notices(waiting)}
                <RoundReveal
                    key={key}
                    roundNumber={round.roundNumber}
                    roundsCount={round.roundsCount}
                    movie={round.reveal.movie}
                    frames={round.reveal.frames}
                    finders={round.reveal.finders}
                    seats={state.seats}
                    leaderboard={state.leaderboard}
                    selfPublicId={state.self.publicId}
                    images={
                        member
                            ? round.images.map((image) => ({
                                  tierIndex: image.tierIndex,
                                  view: frames.view(
                                      gameRef,
                                      round.sequenceIndex,
                                      image.tierIndex,
                                  ),
                              }))
                            : null
                    }
                    tierCount={round.tiers.length}
                    frameFormat={frameFormat}
                    nextStartsInMs={
                        next === null ? null : parseIsoMs(next.startsAt) - nowMs
                    }
                    action={revealAction}
                />
            </div>
        );
    }

    const stage = stageOf(round, nowMs);
    const origin = parseIsoMs(round.startsAt);
    const frameTier =
        stage === 'running'
            ? (clock.tier?.tierIndex ??
              lastOpenedTier(round, nowMs - origin, true))
            : stage === 'closed'
              ? round.endedAt === null
                  ? round.tiers.length
                  : lastOpenedTier(
                        round,
                        parseIsoMs(round.endedAt) - origin,
                        false,
                    )
              : null;
    const frame: RoundSceneFrame | null =
        member && frameTier !== null
            ? {
                  key,
                  view: frames.view(gameRef, round.sequenceIndex, frameTier),
                  tierIndex: frameTier,
                  tierCount: round.tiers.length,
              }
            : null;

    const notice: string[] = [];

    if (stage === 'countdown') {
        notice.push(startsIn(origin));
    }

    if (stage === 'cancelled') {
        notice.push(t('game.round.cancelled'));

        if (next !== null) {
            notice.push(startsIn(parseIsoMs(next.startsAt)));
        }
    }

    if (waiting !== null) {
        notice.push(waiting);
    }

    const self = state.self;
    // Le verrou se lit aussi dans `round.locked` (`player.locked`, serveur) :
    // si la réponse HTTP de la soumission acceptée s'est perdue, la saisie du
    // siège reste inconnue au magasin (nulle), mais le siège est verrouillé.
    const lockRank =
        round.locked.find((each) => each.publicId === self.publicId)
            ?.lockRank ?? null;
    const locked = self.input?.inputState === 'locked' || lockRank !== null;
    // Tentatives restantes : la vue de saisie du siège, à défaut le plafond
    // des réglages figés ; sans l'une ni l'autre (solo), rien n'est monté
    // avant le paquet qui porte la saisie — sauf le verrou, qui n'en dit rien.
    const attemptsLeft = self.input?.attemptsLeft ?? attemptsPerRound;
    const roundInput =
        state.inputDifficulty === null ||
        (attemptsLeft === null && !locked) ? null : (
            <RoundInput
                roundKey={key}
                difficulty={state.inputDifficulty}
                input={self.input}
                choices={
                    self.input?.choices ??
                    (state.offeredChoices?.sequenceIndex === round.sequenceIndex
                        ? state.offeredChoices.payload
                        : null)
                }
                choicesUnavailable={round.choicesUnavailable}
                attemptsLeft={attemptsLeft ?? 0}
                maxLength={maxAnswerLength}
                submission={submission}
                disabled={!self.seatActive}
                lockRank={lockRank}
            />
        );

    // La saisie n'existe qu'une fois la manche ouverte par le serveur
    // (`tier.opened` du palier 1, ou un paquet en phase `running`) : c'est
    // alors que naît la ligne de manche du siège. À la clôture, elle se
    // retire — seul le verrou reste, jamais la réponse saisie.
    let input: ReactNode = null;

    // Les gestes de la manche en cours (solo) : tant que la saisie du siège
    // est ouverte, comme le serveur l'exige (409 `round_not_running` sinon).
    const inputOpen =
        !locked &&
        (self.input === null ||
            self.input.inputState === 'open' ||
            self.input.inputState === 'text_exhausted');

    if (member && stage === 'running' && round.phase === 'running') {
        input =
            self.participates || locked ? (
                roundActions !== undefined &&
                roundActions !== null &&
                self.participates &&
                inputOpen ? (
                    <div className="answer-zone">
                        {roundInput}
                        <div className="game__actions">{roundActions}</div>
                    </div>
                ) : (
                    roundInput
                )
            ) : round.roundNumber < round.roundsCount ? (
                <p className="answer-zone__note">
                    {t('game.round.waiting_next', {
                        number: number.format(round.roundNumber + 1),
                    })}
                </p>
            ) : null;
    } else if (stage === 'closed') {
        // Hors verrou, la barre du chrono dit déjà « la réponse arrive ».
        input = member && locked ? roundInput : null;
    }

    // Les sièges de la partie (lignes `game_player`, 60 § 12.2) : tous portent
    // un `firstRoundNumber`. Un siège qui attend la partie suivante, ajouté au
    // magasin par `seat.joined` en vue de lobby (`firstRoundNumber` nul), n'est
    // ni sur la bande de la manche ni compté pour `lone_player` (§ 9.3) ; il
    // reste dans la feuille « Joueurs » (`seatsPanel`, la liste du salon).
    const gameSeats = state.seats.filter(
        (seat) => seat.firstRoundNumber !== null,
    );
    const connected = gameSeats.filter(
        (seat) => seat.connection === 'connected' && !seat.kicked,
    ).length;

    // Le score du joueur (classement publié), quand aucune bande ne le
    // montre : en solo (maquette `game.html`, `current-score`).
    const selfScore = state.leaderboard.scoreless
        ? null
        : (state.leaderboard.rows.find((row) => row.publicId === self.publicId)
              ?.score ?? 0);

    return (
        <div className="game">
            <h1 className="sr-only">{title}</h1>

            {topbar(true)}

            {notices(null)}

            <RoundScene
                stage={stage}
                roundNumber={round.roundNumber}
                roundsCount={round.roundsCount}
                durationMs={round.durationMs}
                remainingMs={
                    stage === 'running'
                        ? Math.max(0, origin + round.durationMs - nowMs)
                        : null
                }
                tierValue={tierValue}
                frame={frame}
                frameFormat={frameFormat}
                notice={notice}
            />

            {state.mode === 'solo' ? (
                // En solo, aucune bande : le seul siège est le joueur (90
                // § 10, « Solo » : sans autres joueurs) ; son score à la place.
                selfScore !== null && (
                    <p className="current-score">
                        <Star aria-hidden="true" />
                        <span>
                            {tChoice('game.score.points', selfScore, {
                                count: number.format(selfScore),
                            })}
                        </span>
                    </p>
                )
            ) : (
                <RoundPlayers
                    seats={gameSeats}
                    selfPublicId={self.publicId}
                    locked={round.locked}
                    standings={state.leaderboard.rows}
                    scoreless={state.leaderboard.scoreless}
                    lonePlayer={connected === 1}
                />
            )}

            {input}
        </div>
    );
}
