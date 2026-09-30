<?php

namespace App\Jobs\Game;

use App\Actions\Game\RecordHeartbeat;
use App\Actions\Game\SeatInputClosed;
use App\Actions\Room\SeatDeparture;
use App\Actions\Room\TransferHost;
use App\Enums\GamePlayerStatus;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Events\Game\SeatUpdated;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Settings\EngineConstants;
use App\Support\Game\CurrentGame;
use App\Support\Game\SeatViewPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le balayage de présence — spec 60 § 3.4, § 13.2 et § 13.3, contrat C7
 * § 2.5 (job interne, nom donné par le contrat) : le seul écrivain des
 * transitions `connected → disconnected` et `disconnected → left` d'un
 * siège. La présence Reverb ne fait jamais foi ; seuls les battements HTTP
 * écrivent `last_seen_at` ({@see RecordHeartbeat}).
 *
 * Construit **par salon** ({@see self::forRoom()}) ou, en solo, **par siège**
 * ({@see self::forSoloSeat()}). Un battement l'arme s'il n'y en a aucun en
 * attente (`ShouldBeUniqueUntilProcessing`, `uniqueId()` = salon ou siège
 * solo) ; à chaque exécution, sous les verrous du salon puis de ses sièges
 * (room → player, ordre global du § 4.5 — le battement prend le même ordre),
 * il applique les transitions échues :
 *
 * - `connected → disconnected` si `now ≥ last_seen_at + disconnectAfterMs`
 *   (`EngineConstants`) : `disconnected_at = now` ;
 * - `disconnected → left` si `now ≥ disconnected_at + disconnectGraceSeconds
 *   × 1000` — réglage d'hôte lu dans `game.settings_snapshot` en partie, dans
 *   `room.settings` au lobby (et au podium) ; **jamais en solo** (§ 13.3) :
 *   `left_at = now`, la participation à la partie en cours passe `left`
 *   (points conservés, {@see SeatDeparture::markParticipation()}), et si le
 *   siège était l'hôte, {@see TransferHost::automatic()} **dans la même
 *   transaction**, salon déjà verrouillé — une fois, après toutes les
 *   transitions du passage, pour que la cible soit choisie sur l'état final ;
 * - après chaque transition, `seat.updated` (en multijoueur seulement : le
 *   siège solo n'a aucun canal, § 11.2), composé APRÈS le transfert d'hôte ;
 *   puis, après commit, la réévaluation de la fin anticipée de la manche
 *   `running` où le siège a une ligne `round_player`
 *   ({@see SeatDeparture::reevaluateAfterCommit()} → {@see SeatInputClosed}),
 *   avec pour `$now` l'instant de transition ÉCRIT (§ 9.1).
 *
 * Puis il **se réarme à la prochaine échéance, plafonnée à `now +
 * disconnectAfterMs`**, et s'arrête quand plus aucun siège n'est ni connecté
 * ni déconnecté, ou que le salon est archivé. Le plafond est ce qui rend
 * l'unicité sûre : un balayage armé à `t₀` s'exécute au plus tard à `t₀ +
 * disconnectAfterMs`, donc avant l'échéance de tout siège revenu à
 * `connected` après `t₀`. **En solo**, il s'arrête dès que le siège est
 * `disconnected` : il n'existe aucune échéance ultérieure, et le battement
 * suivant le réarme.
 *
 * **Délai en instant arrondi à la seconde supérieure** ({@see self::armAt()}) :
 * `delay()` lirait un entier en secondes, et `availableAt()` tronque un
 * instant à la seconde — réveillé jusqu'à 999 ms trop tôt, le balayage ne
 * trouverait rien d'échu et se réarmerait sur la même seconde, en boucle sur
 * la file `game`. Arrondi à la seconde supérieure, il ne part jamais avant
 * l'échéance, et au plus une seconde après : la présence n'est pas une
 * frontière de palier, aucun score n'en dépend, et l'instant écrit est celui
 * du passage (`disconnected_at = now`).
 *
 * **Idempotent** : un passage sans rien d'échu n'écrit rien. Un seul essai,
 * jamais de `release()` (contrat C7 § 2.5) : un balayage en échec reprend au
 * battement suivant, qui le réarme. Sur une file synchrone, il ne se réarme
 * pas — le job neuf s'exécuterait aussitôt, sans fin (même motif que
 * `AdvanceRound`).
 */
final class SweepSeatPresence implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Durée de vie du verrou d'unicité, en plafonds de réarmement : un
     * balayage en retard d'un plafond entier le tient encore ; au-delà, un
     * doublon est inoffensif (le balayage est idempotent), et un verrou orphelin
     * — job perdu avec sa file — ne bloque jamais la présence d'un salon.
     */
    private const int LOCK_CAPS = 2;

    private const int MILLISECONDS_PER_SECOND = 1000;

    /** Un seul essai : un job du moteur ne se rejoue jamais (`--tries=1`). */
    public int $tries = 1;

    private function __construct(
        public readonly ?int $roomId,
        public readonly ?int $soloSeatId,
    ) {
        $this->onQueue('game');
    }

    /** Le balayage des sièges d'un salon. */
    public static function forRoom(int $roomId): self
    {
        return new self($roomId, null);
    }

    /** Le balayage d'un siège solo (§ 13.3). */
    public static function forSoloSeat(int $seatId): self
    {
        return new self(null, $seatId);
    }

    /** Unique par salon, ou par siège solo, jusqu'à son traitement. */
    public function uniqueId(): string
    {
        return $this->roomId !== null ? 'room:'.$this->roomId : 'seat:'.$this->soloSeatId;
    }

    /** Durée de vie du verrou d'unicité, en secondes ({@see self::LOCK_CAPS}). */
    public function uniqueFor(): int
    {
        return (int) ceil(
            self::LOCK_CAPS * (EngineConstants::disconnectAfterMs() + EngineConstants::JOB_DELAY_RESOLUTION_MS)
            / self::MILLISECONDS_PER_SECOND,
        );
    }

    /**
     * Arme ce balayage pour `$at`, arrondi à la seconde supérieure — sans
     * effet si un balayage du même salon (ou du même siège solo) est déjà en
     * attente. L'appelant l'invoque hors transaction, ou après commit.
     */
    public function armAt(CarbonImmutable $at): void
    {
        dispatch($this->delay($at->ceilSecond()));
    }

    public function handle(TransferHost $transferHost, SeatDeparture $departure): void
    {
        $next = $this->roomId !== null
            ? DB::transaction(fn (): ?CarbonImmutable => self::sweepRoom((int) $this->roomId, $transferHost, $departure))
            : DB::transaction(fn (): ?CarbonImmutable => self::sweepSoloSeat((int) $this->soloSeatId, $departure));

        if (! $next instanceof CarbonImmutable || $this->job instanceof SyncJob) {
            return;
        }

        (new self($this->roomId, $this->soloSeatId))->armAt($next);
    }

    /**
     * Un passage sur les sièges d'un salon ; rend la prochaine échéance,
     * plafonnée, ou `null` pour s'arrêter.
     */
    private static function sweepRoom(int $roomId, TransferHost $transferHost, SeatDeparture $departure): ?CarbonImmutable
    {
        $lockedRoom = Room::query()->whereKey($roomId)->lockForUpdate()->first();

        if (! $lockedRoom instanceof Room || $lockedRoom->archived_at !== null || $lockedRoom->status === RoomStatus::Archived) {
            return null;
        }

        $seats = Player::query()
            ->whereBelongsTo($lockedRoom)
            ->whereIn('connection_state', [PlayerConnectionState::Connected->value, PlayerConnectionState::Disconnected->value])
            ->whereNull('kicked_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $now = Date::now()->toImmutable()->startOfMillisecond();
        $game = Game::query()
            ->where('room_id', $lockedRoom->id)
            ->inProgress()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
        $settings = $game instanceof Game ? $game->settings_snapshot : $lockedRoom->settings;
        $graceMs = $settings->disconnectGraceSeconds * self::MILLISECONDS_PER_SECOND;

        /** @var list<Player> $transitioned */
        $transitioned = [];
        $hostLeft = false;

        foreach ($seats as $seat) {
            if ($seat->connection_state === PlayerConnectionState::Connected
                && $now->greaterThanOrEqualTo(self::disconnectsAt($seat))) {
                $seat->forceFill([
                    'connection_state' => PlayerConnectionState::Disconnected,
                    'disconnected_at' => $now,
                ])->save();
                $transitioned[] = $seat;
            } elseif ($seat->connection_state === PlayerConnectionState::Disconnected
                && $now->greaterThanOrEqualTo(self::leavesAt($seat, $graceMs))) {
                $seat->forceFill([
                    'connection_state' => PlayerConnectionState::Left,
                    'left_at' => $now,
                ])->save();
                $departure->markParticipation($lockedRoom, $seat, GamePlayerStatus::Left);
                $transitioned[] = $seat;
                $hostLeft = $hostLeft || $lockedRoom->host_player_id === $seat->id;
            }
        }

        if ($hostLeft) {
            $transferHost->automatic($lockedRoom, $now);
        }

        foreach ($transitioned as $seat) {
            SeatUpdated::dispatch($lockedRoom, CurrentGame::of($seat), [
                'seat' => SeatViewPresenter::ofSeat($seat, CurrentGame::forState($seat), $lockedRoom->host_player_id),
            ]);

            $departure->reevaluateAfterCommit($seat, self::transitionInstant($seat));
        }

        return self::capped(self::nextDeadline($seats, $graceMs), $now);
    }

    /**
     * Un passage sur un siège solo : il ne passe jamais `left` (§ 13.3), et le
     * balayage s'arrête dès qu'il est `disconnected`.
     */
    private static function sweepSoloSeat(int $seatId, SeatDeparture $departure): ?CarbonImmutable
    {
        $seat = Player::query()
            ->whereKey($seatId)
            ->whereNull('room_id')
            ->whereNull('kicked_at')
            ->lockForUpdate()
            ->first();

        if (! $seat instanceof Player || $seat->connection_state !== PlayerConnectionState::Connected) {
            return null;
        }

        $now = Date::now()->toImmutable()->startOfMillisecond();
        $disconnectsAt = self::disconnectsAt($seat);

        if ($now->lessThan($disconnectsAt)) {
            return self::capped($disconnectsAt, $now);
        }

        // Aucune diffusion en solo (§ 11.2) ; la réévaluation reste due.
        $seat->forceFill([
            'connection_state' => PlayerConnectionState::Disconnected,
            'disconnected_at' => $now,
        ])->save();

        $departure->reevaluateAfterCommit($seat, $now);

        return null;
    }

    /** L'échéance `connected → disconnected` d'un siège. */
    private static function disconnectsAt(Player $seat): CarbonImmutable
    {
        return $seat->last_seen_at->addMilliseconds(EngineConstants::disconnectAfterMs());
    }

    /**
     * L'échéance `disconnected → left` d'un siège de salon. Un siège
     * `disconnected` sans `disconnected_at` (base incohérente) compte depuis
     * son dernier battement.
     */
    private static function leavesAt(Player $seat, int $graceMs): CarbonImmutable
    {
        return ($seat->disconnected_at ?? $seat->last_seen_at)->addMilliseconds($graceMs);
    }

    /**
     * L'instant de transition écrit, relu sur le siège : `left_at` ou
     * `disconnected_at`.
     *
     * @throws LogicException Siège sans instant de transition.
     */
    private static function transitionInstant(Player $seat): CarbonImmutable
    {
        return ($seat->connection_state === PlayerConnectionState::Left ? $seat->left_at : $seat->disconnected_at)
            ?? throw new LogicException('SweepSeatPresence : transition sans instant écrit.');
    }

    /**
     * La prochaine échéance des sièges encore connectés ou déconnectés, ou
     * `null` s'il n'en reste aucun.
     *
     * @param  iterable<Player>  $seats
     */
    private static function nextDeadline(iterable $seats, int $graceMs): ?CarbonImmutable
    {
        $next = null;

        foreach ($seats as $seat) {
            $deadline = match ($seat->connection_state) {
                PlayerConnectionState::Connected => self::disconnectsAt($seat),
                PlayerConnectionState::Disconnected => self::leavesAt($seat, $graceMs),
                PlayerConnectionState::Left => null,
            };

            if ($deadline !== null && ($next === null || $deadline->lessThan($next))) {
                $next = $deadline;
            }
        }

        return $next;
    }

    /** L'échéance, plafonnée à `now + disconnectAfterMs` ; `null` reste `null`. */
    private static function capped(?CarbonImmutable $deadline, CarbonImmutable $now): ?CarbonImmutable
    {
        if (! $deadline instanceof CarbonImmutable) {
            return null;
        }

        $cap = $now->addMilliseconds(EngineConstants::disconnectAfterMs());

        return $deadline->lessThan($cap) ? $deadline : $cap;
    }
}
