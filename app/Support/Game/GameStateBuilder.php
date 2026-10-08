<?php

namespace App\Support\Game;

use App\Actions\Game\CatchUpGame;
use App\Actions\Game\ClaimSeatTab;
use App\Enums\GameMode;
use App\Enums\GamePauseKind;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameWire;
use App\Support\Realtime\WireTime;
use App\Support\Scoring\Scoreboard;
use App\ValueObjects\Answers\SeatInputView;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Le paquet de resynchronisation `GameStatePacket` — spec 60 § 12.1 à
 * § 12.5, contrat C7 § 2.5 et § 3 (nom et signature figés).
 *
 * **Une seule forme, un seul constructeur** : c'est à la fois la prop
 * initiale `state` de toute page `game/*`, la réponse de `room.state` et
 * celle de `solo.state`. Réponse HTTP à destinataire unique, jamais
 * diffusée. Miroir de `GameStatePacket` (`resources/js/types/game-wire.ts`),
 * clés dans l'ordre du type : enveloppe, puis `mode`, `channels`, `status`,
 * `roundsCount`, `roundsCompleted`, `framesPerRound`, `inputDifficulty`,
 * `maxAnswerLength`, `seats`, `pause`, `round`, `self`, `leaderboard`,
 * `podium`, `nextTransitionAt`.
 *
 * **Le paquet décrit, il ne décide rien** : aucune écriture ici (hors le cas
 * défensif du QCM, propriété de 70 : {@see SeatInputView::forSeat()}), aucun
 * rattrapage — `room.state` et `solo.state` appellent {@see CatchUpGame}
 * AVANT de le construire (§ 12.1), si bien qu'il décrit l'état échu. Les
 * instants se calculent sur `$now`, pris par l'appelant avant toute lecture :
 * `serverNow` ne dépasse jamais l'instant des lectures, et un événement
 * émis après elles n'est jamais tenu pour déjà compris par le client.
 *
 * **Branche sans partie** (`$game` nul : lobby, solo pas encore lancé ;
 * lot L60-4) : les canaux du salon (nuls en solo seulement, écart (a) du
 * § 22 bis), les sièges du salon dans l'ordre de 50 (`joined_at`, § 8.1) ou
 * `[soi]` en solo, `self` avec `ownScore` à 0 sans appel à
 * `Scoreboard::seatScore()` (dont la signature exige une partie), aucune
 * manche, `maxAnswerLength` nul (écart (r)), classement vide
 * `{ scoreless: false, roundNumber: null, rows: [] }`.
 *
 * **Branche de partie** (lot L60-12, § 12.2 champ par champ) : enveloppe avec
 * `gameRef` ; `mode` de la partie, canaux nuls en solo ; colonnes de `game` ;
 * `maxAnswerLength` du snapshot ; sièges gelés par
 * {@see SeatViewPresenter::gameSeats()} ; `pause` ; la manche portée
 * ({@see self::carriedRound()}, § 12.3) et ses images, bornées
 * ({@see self::roundImages()}, § 12.4) ; `self` (appartenance à la manche
 * portée, participation, `SeatInputView` de 70, score propre de 80) ;
 * classement `Publishable` gelé à la dernière manche révélée ; podium après
 * le gel ; `nextTransitionAt` (§ 12.5).
 *
 * `self.seatActive` : jeton présenté = `player.active_seat_token`, comparé à
 * temps constant ({@see EnsureActiveSeat::holdsTab()}). À une requête JSON,
 * le jeton présenté est l'en-tête `X-Seat-Token` ; au rendu d'une page,
 * c'est le jeton **rendu par {@see ClaimSeatTab}**, appelée avant. Le jeton
 * lui-même ne figure jamais dans le paquet (§ 11.7).
 *
 * **Aucune transaction englobante** : la vue de saisie de 70 relit la
 * participation après l'écriture conditionnelle de son cas défensif, ce
 * qu'un instantané `REPEATABLE READ` rendrait périmé (E107-2) ; `Scoreboard`
 * lit déjà chacun de ses blocs sous un seul instantané (E82-6).
 *
 * Règle 3 : aucun identifiant interne, aucun titre avant `revealStartsAt`,
 * aucun niveau d'image ; les sièges passent par {@see SeatViewPresenter},
 * les chronologies par {@see RoundTimelinePresenter}, les images par
 * {@see TierImageRefPresenter} (le `serve_token` n'en sort que dans l'URL
 * signée), le film révélé par {@see RevealMovieBuilder}, les canaux par
 * {@see ChannelNames} (clé HMAC, jamais `room.id` ni `room_code`).
 *
 * @phpstan-import-type SeatViewPayload from SeatViewPresenter
 * @phpstan-import-type LeaderboardPayload from Scoreboard
 * @phpstan-import-type PodiumPayload from Scoreboard
 * @phpstan-import-type RoundFinderPayload from Scoreboard
 * @phpstan-import-type TierImageRefPayload from TierImageRefPresenter
 * @phpstan-import-type RevealMoviePayload from RevealMovieBuilder
 * @phpstan-import-type RevealFramePayload from RevealFramesPresenter
 *
 * @phpstan-type RoundPhase 'scheduled'|'running'|'closed'|'revealing'|'cancelled'
 * @phpstan-type RoundStatePayload array{sequenceIndex: int, roundNumber: int, roundsCount: int, startsAt: string, durationMs: int, tiers: list<array{tierIndex: int, startsAtOffsetMs: int, durationMs: int, points: int}>, choicesAtTierIndex: int|null, phase: RoundPhase, currentTierIndex: int|null, images: list<TierImageRefPayload>, locked: list<array{publicId: string, lockRank: int}>, endedAt: string|null, revealStartsAt: string|null, revealEndsAt: string|null, reveal: array{movie: RevealMoviePayload, frames: list<RevealFramePayload>, finders: list<RoundFinderPayload>}|null, choicesUnavailable: bool}
 * @phpstan-type SelfStatePayload array{publicId: string, seatActive: bool, isHost: bool, member: bool, participates: bool, input: array<string, mixed>|null, ownScore: int}
 * @phpstan-type GameStatePacketPayload array{v: int, serverNow: string, gameRef: string|null, mode: 'multiplayer'|'solo', channels: array{room: string, seat: string}|null, status: string|null, roundsCount: int|null, roundsCompleted: int|null, framesPerRound: int|null, inputDifficulty: string|null, maxAnswerLength: int|null, seats: list<SeatViewPayload>, pause: array{pausedAt: string, interruptsAt: string, kind: string}|null, pauseRequested: bool, round: RoundStatePayload|null, self: SelfStatePayload, leaderboard: LeaderboardPayload, podium: PodiumPayload|null, nextTransitionAt: string|null}
 */
final class GameStateBuilder
{
    /** Le palier de la plus cryptique des images, premier ouvert de toute manche. */
    private const int FIRST_TIER_INDEX = 1;

    /**
     * @param  Game|null  $game  la partie décrite (§ 12.2, {@see CurrentGame::forState()}),
     *                           nulle au lobby et pour un solo pas encore lancé
     * @param  CarbonImmutable  $now  instant serveur du paquet, pris avant toute lecture
     * @param  string|null  $presentedSeatToken  jeton d'onglet présenté, ou rendu par {@see ClaimSeatTab}
     * @return GameStatePacketPayload
     *
     * @throws LogicException siège de salon sans salon, ou siège étranger à
     *                        la partie décrite (autre salon, ou solo contre
     *                        salon).
     */
    public static function build(?Game $game, Player $seat, CarbonImmutable $now, ?string $presentedSeatToken): array
    {
        return $game === null
            ? self::withoutGame($seat, $now, $presentedSeatToken)
            : self::withGame($game, $seat, $now, $presentedSeatToken);
    }

    /**
     * Branche sans partie : lobby, ou solo pas encore lancé (lot L60-4).
     *
     * @return GameStatePacketPayload
     */
    private static function withoutGame(Player $seat, CarbonImmutable $now, ?string $presentedSeatToken): array
    {
        $room = $seat->room_id === null ? null : Room::query()->find($seat->room_id);

        if ($seat->room_id !== null && $room === null) {
            throw new LogicException('GameStateBuilder : le salon du siège est introuvable.');
        }

        $hostPlayerId = $room?->host_player_id;

        return [
            ...GameWire::envelope(null, $now),
            'mode' => $room === null ? GameMode::Solo->value : GameMode::Multiplayer->value,
            'channels' => $room === null ? null : [
                'room' => ChannelNames::room($room),
                'seat' => ChannelNames::seat($seat),
            ],
            'status' => null,
            'roundsCount' => null,
            'roundsCompleted' => null,
            'framesPerRound' => null,
            'inputDifficulty' => null,
            'maxAnswerLength' => null,
            'seats' => $room === null
                ? [SeatViewPresenter::lobby($seat, null)]
                : SeatViewPresenter::lobbySeats($room),
            'pause' => null,
            'pauseRequested' => false,
            'round' => null,
            'self' => [
                'publicId' => $seat->public_id,
                'seatActive' => EnsureActiveSeat::holdsTab($seat, $presentedSeatToken),
                'isHost' => $hostPlayerId !== null && $hostPlayerId === $seat->id,
                'member' => false,
                'participates' => false,
                'input' => null,
                'ownScore' => 0,
            ],
            'leaderboard' => self::emptyLeaderboard(),
            'podium' => null,
            'nextTransitionAt' => null,
        ];
    }

    /**
     * Branche de partie (lot L60-12) : § 12.2 champ par champ.
     *
     * @return GameStatePacketPayload
     */
    private static function withGame(Game $game, Player $seat, CarbonImmutable $now, ?string $presentedSeatToken): array
    {
        if ($seat->room_id !== $game->room_id) {
            throw new LogicException('GameStateBuilder : le siège n’appartient pas au salon de la partie décrite.');
        }

        $room = $game->room_id === null ? null : Room::query()->find($game->room_id);

        if ($game->room_id !== null && $room === null) {
            throw new LogicException('GameStateBuilder : le salon de la partie est introuvable.');
        }

        $solo = $game->mode === GameMode::Solo;
        $hostPlayerId = $room?->host_player_id;
        $rounds = self::liveRounds($game);
        $carried = self::carriedRound($game, $rounds, $now);
        $revealing = $rounds->first(static fn (Round $round): bool => $round->status === RoundStatus::Revealing);
        $participation = $carried === null ? null : RoundPlayer::query()
            ->where('round_id', $carried->id)
            ->where('player_id', $seat->id)
            ->first();

        return [
            ...GameWire::envelope($game, $now),
            'mode' => $solo ? GameMode::Solo->value : GameMode::Multiplayer->value,
            'channels' => $solo || $room === null ? null : [
                'room' => ChannelNames::room($room),
                'seat' => ChannelNames::seat($seat),
            ],
            'status' => $game->status->value,
            'roundsCount' => $game->rounds_count,
            'roundsCompleted' => $game->rounds_completed,
            'framesPerRound' => $game->frames_per_round,
            'inputDifficulty' => $game->input_difficulty->value,
            'maxAnswerLength' => $game->settings_snapshot->maxAnswerLength,
            'seats' => SeatViewPresenter::gameSeats($game, $hostPlayerId),
            'pause' => self::pause($game),
            'pauseRequested' => $game->ended_at === null && $game->status === GameStatus::Running && $game->pause_requested_at !== null,
            'round' => $carried === null ? null : self::roundState($game, $carried, $seat, $now),
            'self' => [
                'publicId' => $seat->public_id,
                'seatActive' => EnsureActiveSeat::holdsTab($seat, $presentedSeatToken),
                'isHost' => $hostPlayerId !== null && $hostPlayerId === $seat->id,
                'member' => self::isMember($game, $seat, $carried),
                'participates' => $participation !== null,
                'input' => $participation === null ? null : SeatInputView::forSeat($participation)->toArray(),
                'ownScore' => Scoreboard::seatScore($game, $seat)['ownScore'],
            ],
            'leaderboard' => $revealing === null ? Scoreboard::leaderboard($game) : Scoreboard::leaderboard($game, $revealing),
            'podium' => $game->ended_at === null ? null : Scoreboard::podium($game),
            'nextTransitionAt' => self::nextTransitionAt($game, $rounds, $now),
        ];
    }

    /**
     * Les manches vivantes de la partie — en cours, en révélation, ou
     * programmées (`pending`, `started_at` non nul) —, par `started_at` puis
     * `sequence_index`, chacune avec ses `N` paliers (et la variante servie
     * de chacun) et sa partie en relation : la garde de service, la frappe
     * et l'horloge les lisent sans autre requête.
     *
     * @return Collection<int, Round>
     */
    private static function liveRounds(Game $game): Collection
    {
        $rounds = Round::query()
            ->where('game_id', $game->id)
            ->where(static function (Builder $query): void {
                $query->whereIn('status', [RoundStatus::Running->value, RoundStatus::Revealing->value])
                    ->orWhere(static function (Builder $pending): void {
                        $pending->where('status', RoundStatus::Pending->value)->whereNotNull('started_at');
                    });
            })
            ->orderBy('started_at')
            ->orderBy('sequence_index')
            ->get();

        $tiers = RoundTier::query()
            ->whereIn('round_id', $rounds->modelKeys())
            ->with('servedFrame')
            ->orderBy('tier_index')
            ->get();

        foreach ($rounds as $round) {
            $roundTiers = $tiers->filter(static fn (RoundTier $tier): bool => $tier->round_id === $round->id)->values();

            foreach ($roundTiers as $tier) {
                $tier->setRelation('round', $round);
            }

            $round->setRelation('game', $game);
            $round->setRelation('tiers', $roundTiers);
        }

        return $rounds;
    }

    /**
     * La manche que porte le paquet (§ 12.3), seulement pour une partie en
     * cours — NULL en pause, partie close ou sans manche programmée :
     *
     * 1. une manche `running` ;
     * 2. sinon une manche `revealing` — **sauf** si la garde du palier 1 de
     *    la manche suivante programmée est franchie (`now ≥ T₁(k+1) −
     *    preload_lead_ms`) : le paquet porte alors la manche suivante, en
     *    phase `scheduled`, avec la seule URL de son palier 1 (écart (c) du
     *    § 22 bis) ;
     * 3. sinon une manche `pending` programmée ;
     * 4. sinon NULL.
     *
     * @param  Collection<int, Round>  $rounds  {@see self::liveRounds()}
     */
    private static function carriedRound(Game $game, Collection $rounds, CarbonImmutable $now): ?Round
    {
        if ($game->status !== GameStatus::Running || $game->ended_at !== null) {
            return null;
        }

        $running = $rounds->first(static fn (Round $round): bool => $round->status === RoundStatus::Running);

        if ($running !== null) {
            return $running;
        }

        $scheduled = $rounds->first(static fn (Round $round): bool => $round->status === RoundStatus::Pending);
        $revealing = $rounds->first(static fn (Round $round): bool => $round->status === RoundStatus::Revealing);

        if ($revealing === null) {
            return $scheduled;
        }

        $firstTier = $scheduled === null ? null : self::tierOf($scheduled, self::FIRST_TIER_INDEX);

        return $firstTier !== null && $firstTier->isOpenForServing($now, $game->preload_lead_ms)
            ? $scheduled
            : $revealing;
    }

    /**
     * `RoundState` (§ 12.1) : la chronologie publique de la manche
     * ({@see RoundTimelinePresenter}), puis sa phase dérivée, son palier
     * courant, ses images bornées, ses verrouillés, ses instants de clôture et
     * de révélation, et — seulement en révélation, à partir de
     * `revealStartsAt` — le film et ses trouveurs ; enfin le cas terminal du
     * QCM ({@see self::choicesUnavailable()}, D54 du 02/10).
     *
     * @return RoundStatePayload
     */
    private static function roundState(Game $game, Round $round, Player $seat, CarbonImmutable $now): array
    {
        $phase = self::phase($round);
        $currentTierIndex = self::currentTierIndex($round, $phase, $now);
        $revealStartsAt = $round->ended_at?->addMilliseconds($game->tier_grace_ms);

        return [
            ...RoundTimelinePresenter::timeline($game, $round),
            'phase' => $phase,
            'currentTierIndex' => $currentTierIndex,
            'images' => self::roundImages($game, $round, $phase, $currentTierIndex, $seat, $now),
            'locked' => self::locked($round),
            'endedAt' => $round->ended_at === null ? null : WireTime::iso($round->ended_at),
            'revealStartsAt' => $revealStartsAt === null ? null : WireTime::iso($revealStartsAt),
            'revealEndsAt' => $round->reveal_ends_at === null ? null : WireTime::iso($round->reveal_ends_at),
            'reveal' => $phase === 'revealing' && $revealStartsAt !== null && $now->greaterThanOrEqualTo($revealStartsAt)
                ? [
                    'movie' => RevealMovieBuilder::build(Movie::query()->findOrFail($round->movie_id)),
                    'frames' => RevealFramesPresenter::frames($round->tiers),
                    'finders' => Scoreboard::roundFinders($round),
                ]
                : null,
            'choicesUnavailable' => self::choicesUnavailable($game, $round),
        ];
    }

    /**
     * `round.choicesUnavailable` (D54 du 02/10, spec 70 § 10.7) : le palier
     * du QCM est ouvert (`served_at` non nul) et aucun leurre n'est écrit —
     * le prédicat du cas terminal, sans colonne propre. Faux en Expert (aucun
     * palier de QCM), avant l'ouverture de ce palier, et pour une manche
     * Facile annulée, dont l'ouverture n'a jamais marqué le palier servi. Le
     * même booléen que `tier.opened.choicesUnavailable` : un client qui
     * recharge après `T_N` affiche le même message. Booléen de manche,
     * identique pour tout siège : il ne dit rien de la réponse.
     */
    private static function choicesUnavailable(Game $game, Round $round): bool
    {
        $tierIndex = $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round);

        if ($tierIndex === null || $round->decoy_movie_id_1 !== null) {
            return false;
        }

        return self::tierOf($round, $tierIndex)?->served_at !== null;
    }

    /**
     * La phase dérivée d'une manche, jamais stockée (§ 3.2) : `closed` est
     * une manche `running` dont `ended_at` est écrit, jusqu'à sa révélation.
     *
     * @return RoundPhase
     *
     * @throws LogicException manche en attente sans origine de temps, ou
     *                        terminée : elle n'est jamais portée par un
     *                        paquet ({@see self::carriedRound()}).
     */
    private static function phase(Round $round): string
    {
        return match ($round->status) {
            RoundStatus::Pending => $round->started_at !== null
                ? 'scheduled'
                : throw new LogicException('GameStateBuilder : une manche non programmée n’a pas de phase.'),
            RoundStatus::Running => $round->ended_at === null ? 'running' : 'closed',
            RoundStatus::Revealing => 'revealing',
            RoundStatus::Cancelled => 'cancelled',
            RoundStatus::Completed => throw new LogicException('GameStateBuilder : une manche terminée n’est jamais portée par un paquet.'),
        };
    }

    /**
     * Le palier courant : celui dont la fenêtre contient `now` en phase
     * `running` ({@see RoundClock::currentTierIndex()} — à défaut, par une
     * lecture antérieure à tout rattrapage au-delà de `D`, le dernier palier
     * commencé) ; le dernier palier ouvert en phase `closed` (`Tᵢ <
     * ended_at`, la règle de la garde de service) ; NULL ailleurs — une
     * manche programmée n'a encore rien montré, une révélation montre ses
     * paliers ouverts, une manche annulée n'en montre aucun.
     *
     * @param  RoundPhase  $phase
     */
    private static function currentTierIndex(Round $round, string $phase, CarbonImmutable $now): ?int
    {
        if ($phase === 'running') {
            return RoundClock::currentTierIndex($round, $now) ?? self::lastTierBefore($round, $now, inclusive: true);
        }

        if ($phase === 'closed' && $round->ended_at !== null) {
            return self::lastTierBefore($round, $round->ended_at, inclusive: false);
        }

        return null;
    }

    /**
     * Le plus grand palier dont l'ouverture `Tᵢ` précède `$instant` (ou
     * l'égale, si `$inclusive`), sur le décalage entier de {@see RoundClock}.
     */
    private static function lastTierBefore(Round $round, CarbonImmutable $instant, bool $inclusive): ?int
    {
        if ($round->started_at === null) {
            return null;
        }

        $offsetMs = RoundClock::offsetMs($round, $instant);
        $last = null;

        foreach ($round->tiers as $tier) {
            $started = $inclusive
                ? $tier->starts_at_offset_ms <= $offsetMs
                : $tier->starts_at_offset_ms < $offsetMs;

            if ($started) {
                $last = $tier->tier_index;
            }
        }

        return $last;
    }

    /**
     * Les images du paquet — **la borne** du § 12.4 (D14 du 23/09, 10 § 12
     * et § 15) :
     *
     * - **pendant la manche** (`scheduled`, `running`, `closed`) : au plus
     *   deux URL — celle du palier courant (le palier 1 en phase
     *   `scheduled`), et celle du palier suivant si et seulement si sa garde
     *   `Tᵢ₊₁ − preload_lead_ms` est franchie. Les paliers antérieurs n'y
     *   figurent JAMAIS, même si la garde de service les autorise encore : un
     *   palier passé n'est jamais re-signé pendant la manche ;
     * - **pendant la révélation** : les URL des seuls paliers ouverts
     *   (`served_at` non nul), jusqu'à `reveal_ends_at` ; la bascule vers la
     *   manche suivante (§ 12.3) les retire dès la garde de son palier 1 ;
     * - **manche annulée** : aucune.
     *
     * {@see ServeGuard::allows()}, évalué pour le demandeur (le hash du jeton
     * du siège), est une **condition nécessaire** de chaque URL retenue,
     * jamais le critère de sélection : un retardataire en attente, un siège
     * expulsé, une frame devenue non servable ou une URL hors de sa fenêtre
     * n'obtiennent rien. Un palier sans jeton frappé n'a pas d'URL (§ 6.2).
     *
     * @param  RoundPhase  $phase
     * @return list<TierImageRefPayload>
     */
    private static function roundImages(Game $game, Round $round, string $phase, ?int $currentTierIndex, Player $seat, CarbonImmutable $now): array
    {
        $candidates = match ($phase) {
            'revealing' => $round->tiers->filter(static fn (RoundTier $tier): bool => $tier->served_at !== null)->all(),
            'cancelled' => [],
            default => self::inRoundCandidates($game, $round, $phase === 'scheduled' ? self::FIRST_TIER_INDEX : $currentTierIndex, $now),
        };

        $guard = app(ServeGuard::class);
        $images = [];

        foreach ($candidates as $tier) {
            if ($tier->serve_token !== null && $guard->allows($tier, $seat->player_token_hash, $now)) {
                $images[] = TierImageRefPresenter::image($game, $tier);
            }
        }

        return $images;
    }

    /**
     * Pendant la manche : le palier courant, puis le suivant si sa garde est
     * franchie — deux au plus, jamais un palier antérieur.
     *
     * @return list<RoundTier>
     */
    private static function inRoundCandidates(Game $game, Round $round, ?int $currentTierIndex, CarbonImmutable $now): array
    {
        if ($currentTierIndex === null) {
            return [];
        }

        $current = self::tierOf($round, $currentTierIndex);
        $next = self::tierOf($round, $currentTierIndex + 1);

        return array_values(array_filter([
            $current,
            $next !== null && $next->isOpenForServing($now, $game->preload_lead_ms) ? $next : null,
        ]));
    }

    /**
     * Les sièges verrouillés de la manche, par rang d'arrivée : `publicId`
     * et `lockRank` seulement — ni points, ni palier, ni saisie (§ 11.7).
     *
     * @return list<array{publicId: string, lockRank: int}>
     */
    private static function locked(Round $round): array
    {
        $locked = [];

        foreach (Guess::query()
            ->toBase()
            ->join('player', 'player.id', '=', 'guess.player_id')
            ->where('guess.round_id', $round->id)
            ->orderBy('guess.lock_rank')
            ->get(['player.public_id', 'guess.lock_rank']) as $row) {
            $locked[] = ['publicId' => (string) $row->public_id, 'lockRank' => (int) $row->lock_rank];
        }

        return $locked;
    }

    /**
     * `self.member` : une ligne `game_player` non expulsée, éligible pour la
     * manche portée (`first_round_number` NULL ou `≤ round_number`). Sans
     * manche portée, l'éligibilité se lit contre la manche suivante à jouer
     * (pause), à défaut la dernière manche jouée (partie close) ; sans l'une
     * ni l'autre, seul un siège du lancement est membre.
     */
    private static function isMember(Game $game, Player $seat, ?Round $carried): bool
    {
        if ($seat->kicked_at !== null) {
            return false;
        }

        $participation = GamePlayer::query()
            ->where('game_id', $game->id)
            ->where('player_id', $seat->id)
            ->first();

        if ($participation === null || $participation->status === GamePlayerStatus::Kicked) {
            return false;
        }

        if ($participation->first_round_number === null) {
            return true;
        }

        $roundNumber = $carried === null ? self::referenceRoundNumber($game) : $carried->round_number;

        return $roundNumber !== null && $participation->first_round_number <= $roundNumber;
    }

    /**
     * Sans manche portée : le numéro de la manche suivante à jouer (manche
     * `pending` numérotée de plus petit numéro, 60 § 1.2), à défaut celui de
     * la dernière manche jouée (`started_at` non nul).
     */
    private static function referenceRoundNumber(Game $game): ?int
    {
        $next = Round::query()
            ->where('game_id', $game->id)
            ->where('status', RoundStatus::Pending->value)
            ->whereNotNull('round_number')
            ->min('round_number');

        if ($next !== null) {
            return (int) $next;
        }

        $last = Round::query()
            ->where('game_id', $game->id)
            ->whereNotNull('round_number')
            ->whereNotNull('started_at')
            ->max('round_number');

        return $last === null ? null : (int) $last;
    }

    /**
     * `pause` : `paused_at`, l'échéance ({@see PauseDeadline}) et la nature
     * (`empty` / `manual`, D64 du 07/10) pour une partie en pause, NULL sinon.
     *
     * @return array{pausedAt: string, interruptsAt: string, kind: string}|null
     */
    private static function pause(Game $game): ?array
    {
        $interruptsAt = PauseDeadline::of($game);

        if ($interruptsAt === null || $game->paused_at === null) {
            return null;
        }

        return [
            'pausedAt' => WireTime::iso($game->paused_at),
            'interruptsAt' => WireTime::iso($interruptsAt),
            'kind' => ($game->pause_kind ?? GamePauseKind::Empty)->value,
        ];
    }

    /**
     * `nextTransitionAt` (§ 12.5) : le plus petit instant FUTUR parmi la
     * prochaine étape programmée des manches vivantes (`Tᵢ`, clôture à `D`,
     * début et fin de révélation), la prochaine **garde de palier** (`Tᵢ −
     * preload_lead_ms`) non franchie — palier 1 de la manche suivante compris
     * — et `interruptsAt` d'une pause. NULL pour une partie close. C'est la
     * cadence de sondage du solo et le filet du multijoueur (§ 12.6).
     *
     * Un palier que la fin anticipée a empêché de s'ouvrir (`Tᵢ ≥ ended_at`)
     * n'a ni étape ni garde.
     *
     * @param  Collection<int, Round>  $rounds  {@see self::liveRounds()}
     */
    private static function nextTransitionAt(Game $game, Collection $rounds, CarbonImmutable $now): ?string
    {
        if ($game->ended_at !== null) {
            return null;
        }

        $instants = [];

        $interruptsAt = PauseDeadline::of($game);

        if ($interruptsAt !== null) {
            $instants[] = $interruptsAt;
        }

        if ($game->status === GameStatus::Running) {
            foreach ($rounds as $round) {
                array_push($instants, ...self::roundInstants($game, $round));
            }
        }

        $next = null;

        foreach ($instants as $instant) {
            if ($instant->greaterThan($now) && ($next === null || $instant->lessThan($next))) {
                $next = $instant;
            }
        }

        return $next === null ? null : WireTime::iso($next);
    }

    /**
     * Les étapes et les gardes d'une manche vivante, futures ou non.
     *
     * @return list<CarbonImmutable>
     */
    private static function roundInstants(Game $game, Round $round): array
    {
        $startedAt = $round->started_at;

        if ($startedAt === null) {
            return [];
        }

        if ($round->status === RoundStatus::Revealing) {
            return $round->reveal_ends_at === null ? [] : [$round->reveal_ends_at];
        }

        // Clôture (à `D`, ou écrite par la fin anticipée), puis début de la
        // révélation, `tier_grace_ms` plus tard.
        $closesAt = $round->ended_at ?? $startedAt->addMilliseconds($round->duration_ms);
        $instants = [$closesAt, $closesAt->addMilliseconds($game->tier_grace_ms)];

        if ($round->reveal_ends_at !== null) {
            $instants[] = $round->reveal_ends_at;
        }

        foreach ($round->tiers as $tier) {
            $opensAt = $startedAt->addMilliseconds($tier->starts_at_offset_ms);

            if ($opensAt->lessThan($closesAt)) {
                $instants[] = $opensAt;
                $instants[] = $opensAt->subMilliseconds($game->preload_lead_ms);
            }
        }

        return $instants;
    }

    /**
     * Le palier `$tierIndex` d'une manche chargée par {@see self::liveRounds()}.
     */
    private static function tierOf(Round $round, int $tierIndex): ?RoundTier
    {
        return $round->tiers->first(static fn (RoundTier $tier): bool => $tier->tier_index === $tierIndex);
    }

    /**
     * Le classement sans partie (§ 12.2).
     *
     * @return LeaderboardPayload
     */
    private static function emptyLeaderboard(): array
    {
        return ['scoreless' => false, 'roundNumber' => null, 'rows' => []];
    }
}
