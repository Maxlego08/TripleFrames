<?php

namespace App\Actions\Game;

use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\PlayerConnectionState;
use App\Events\Game\SeatUpdated;
use App\Jobs\Game\SweepSeatPresence;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Settings\EngineConstants;
use App\Support\Game\CurrentGame;
use App\Support\Game\SeatViewPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Le battement de présence d'un siège — spec 60 § 13.1, contrat C7 § 4.10.
 * Action interne (nom libre) qui porte la transaction du § 13.1, partagée par
 * le battement de salon (`room.heartbeat`, `RoomHeartbeatController`), par
 * celui du solo (`solo.heartbeat`, `SoloHeartbeatController`) et par chaque
 * geste solo, qui « vaut battement » (§ 16.5 : `RevealSoloAnswer`,
 * `SkipSoloRound`, `solo.next`), à l'instant du geste. **Seuls les battements
 * HTTP écrivent `last_seen_at`** ; la présence Reverb ne fait jamais foi.
 *
 * Dans une transaction qui respecte l'ordre global du § 4.5 — `room FOR
 * UPDATE` d'abord, **seulement** s'il écrit `room.last_activity_at` ou ramène
 * un siège à `connected`, puis `player FOR UPDATE`, puis `game` par
 * {@see ResumeGame} :
 *
 * - écrit `player.last_seen_at` **par Eloquent**, jamais `DB::table()` (la
 *   milliseconde serait perdue, 10 § 1.2), et `room.last_activity_at` au plus
 *   une fois par `heartbeatIntervalMs` et par salon (10 § 7.1 ; source
 *   d'activité des échéances de 50 § 16.1) ;
 * - ramène un siège `disconnected` ou `left` (non expulsé) à `connected` :
 *   efface `disconnected_at` et `left_at`, repasse `game_player.status` de
 *   `left` à `playing` pour la partie en cours (points acquis conservés ;
 *   jamais pour une partie dont la pause a passé son échéance, que la
 *   reprise gèle à cet instant), émet `seat.updated` **en multijoueur
 *   seulement** (§ 11.2). C'est une
 *   reconnexion, jamais une arrivée tardive : ni `allowLateJoin`, ni place
 *   consommée (§ 13.6) ; l'ancien hôte ne récupère pas le rôle ;
 * - si la partie en cours du siège est en pause et qu'il en est un **siège
 *   présent** (ligne `game_player` non expulsée, § 1.2 — critère inverse de
 *   la pause, retardataire admis compris), appelle {@see ResumeGame} (§ 14.2),
 *   qui vérifie d'abord l'échéance de la pause ;
 * - puis, après commit, arme le balayage de présence du salon (ou du siège
 *   solo) à l'échéance de ce siège, `now + disconnectAfterMs` — sans effet si
 *   un balayage est déjà en attente ({@see SweepSeatPresence}).
 *
 * N'appelle pas `CatchUpGame` : le battement reste léger. Un battement
 * ordinaire — siège déjà connecté, activité du salon récente — ne prend que la
 * ligne `player`.
 *
 * **Sans lecture avant les verrous** : la décision de prendre le salon se lit
 * sur les instances que l'appelant a déjà chargées (siège résolu par le jeton,
 * salon lié par la route). Si, sous le verrou du siège, le siège se révèle
 * déconnecté alors que le salon n'a pas été pris (balayage concurrent), rien
 * n'est écrit et le passage est rejoué avec le salon : prendre `room` après
 * `player` inverserait l'ordre de {@see SweepSeatPresence}, qui prend `room`
 * puis `player`, et les deux s'interbloqueraient.
 */
final readonly class RecordHeartbeat
{
    /** Issue d'un passage : écrit. */
    private const string BEAT = 'beat';

    /** Issue d'un passage : rien écrit, le siège revient et le salon n'est pas verrouillé. */
    private const string NEEDS_ROOM = 'needs_room';

    /** Issue d'un passage : siège introuvable, expulsé ou effacé — 403. */
    private const string REFUSED = 'refused';

    public function __construct(private ResumeGame $resume) {}

    /**
     * @param  Player  $seat  Siège résolu par le `player_token` courant, expulsé exclu.
     * @param  Room|null  $room  Son salon, déjà chargé ; `null` en solo.
     * @param  CarbonImmutable|null  $at  Instant du battement : celui d'un geste solo, qui
     *                                    « vaut battement » à son propre instant (spec 60
     *                                    § 16.5, L60-16) ; l'horloge courante sinon.
     * @return bool `false` si le siège n'existe plus, a été expulsé ou effacé
     *              (archivage) sous le verrou : l'appelant répond 403.
     */
    public function handle(Player $seat, ?Room $room, ?CarbonImmutable $at = null): bool
    {
        $outcome = DB::transaction(fn (): string => $this->beat($seat, self::needsRoom($seat, $room), $at));

        if ($outcome === self::NEEDS_ROOM) {
            $outcome = DB::transaction(fn (): string => $this->beat($seat, true, $at));
        }

        return $outcome === self::BEAT;
    }

    /**
     * Le salon doit-il être verrouillé ? Seulement pour ramener le siège à
     * `connected`, ou pour écrire l'activité du salon, qu'un battement
     * n'écrit qu'une fois par `heartbeatIntervalMs`. Lu sur les instances
     * chargées ; revérifié sous les verrous.
     */
    private static function needsRoom(Player $seat, ?Room $room): bool
    {
        if ($seat->room_id === null || ! $room instanceof Room) {
            return false;
        }

        return $seat->connection_state !== PlayerConnectionState::Connected
            || self::activityDue($room, Date::now()->toImmutable());
    }

    /** L'activité du salon est-elle à réécrire ? Au plus une fois par `heartbeatIntervalMs`. */
    private static function activityDue(Room $room, CarbonImmutable $now): bool
    {
        return $room->last_activity_at->lessThanOrEqualTo($now->subMilliseconds(EngineConstants::heartbeatIntervalMs()));
    }

    /**
     * Un passage sous les verrous : `room` si `$withRoom`, puis `player`.
     */
    private function beat(Player $seat, bool $withRoom, ?CarbonImmutable $at): string
    {
        $lockedRoom = null;

        if ($withRoom && $seat->room_id !== null) {
            $lockedRoom = Room::query()->whereKey($seat->room_id)->lockForUpdate()->first();

            if (! $lockedRoom instanceof Room || $lockedRoom->archived_at !== null) {
                return self::REFUSED;
            }
        }

        $lockedSeat = Player::query()->whereKey($seat->id)->lockForUpdate()->first();

        // Expulsé, déplacé, ou jeton effacé par l'archivage : plus un siège.
        if (! $lockedSeat instanceof Player
            || $lockedSeat->kicked_at !== null
            || $lockedSeat->room_id !== $seat->room_id
            || $lockedSeat->player_token_hash === null) {
            return self::REFUSED;
        }

        $returning = $lockedSeat->connection_state !== PlayerConnectionState::Connected;

        // Un siège de salon ne revient que sous le verrou du salon, pris AVANT
        // le sien : rien n'est écrit, le passage est rejoué avec le salon.
        if ($returning && $lockedSeat->room_id !== null && ! $lockedRoom instanceof Room) {
            return self::NEEDS_ROOM;
        }

        $now = ($at ?? Date::now()->toImmutable())->startOfMillisecond();

        $lockedSeat->forceFill(['last_seen_at' => $now]);

        if ($returning) {
            $lockedSeat->forceFill([
                'connection_state' => PlayerConnectionState::Connected,
                'disconnected_at' => null,
                'left_at' => null,
            ]);
        }

        $lockedSeat->save();

        if ($lockedRoom instanceof Room) {
            if ($returning) {
                self::rejoinGame($lockedRoom, $lockedSeat, $now);
                self::announceReturn($lockedRoom, $lockedSeat);
            }

            if (self::activityDue($lockedRoom, $now)) {
                Room::query()->whereKey($lockedRoom->id)->update(['last_activity_at' => $now]);
            }
        }

        $game = CurrentGame::of($lockedSeat);

        if ($game instanceof Game && $game->status === GameStatus::Paused && self::presentIn($game, $lockedSeat)) {
            $this->resume->handle($game, $now);
        }

        self::armSweep($lockedSeat, $now);

        return self::BEAT;
    }

    /**
     * La participation du siège revenu à la partie en cours du salon repasse
     * de `left` à `playing` — la dernière partie du salon, relue sous son
     * verrou après celui du siège (room → player → game) : une participation
     * figée par un gel (`ended_at` non nul) n'est jamais réécrite, ni une
     * expulsion.
     *
     * Ni celle d'une partie en pause dont l'échéance est passée : close à
     * `paused_at + pauseTimeoutMs` même si `InterruptPausedGame`, en retard en
     * tête de file, ne l'a pas encore gelée — {@see ResumeGame} la gèle
     * aussitôt à cet instant. Un siège parti avant l'échéance le reste dans
     * le podium figé : l'issue ne dépend jamais du retard du job (§ 14.2).
     */
    private static function rejoinGame(Room $lockedRoom, Player $lockedSeat, CarbonImmutable $now): void
    {
        $game = Game::query()
            ->where('room_id', $lockedRoom->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $game instanceof Game || $game->ended_at !== null || self::pauseExpired($game, $now)) {
            return;
        }

        GamePlayer::query()
            ->whereBelongsTo($game)
            ->where('player_id', $lockedSeat->id)
            ->where('status', GamePlayerStatus::Left->value)
            ->update(['status' => GamePlayerStatus::Playing->value]);
    }

    /**
     * La partie est-elle en pause au-delà de son échéance, `now ≥ paused_at +
     * pauseTimeoutMs` — la même comparaison que {@see ResumeGame} ?
     */
    private static function pauseExpired(Game $game, CarbonImmutable $now): bool
    {
        return $game->status === GameStatus::Paused
            && $game->paused_at !== null
            && $now->greaterThanOrEqualTo($game->paused_at->addMilliseconds(EngineConstants::pauseTimeoutMs()));
    }

    /**
     * `seat.updated` du siège revenu, dans une transaction IMBRIQUÉE validée
     * avant la reprise (E90-7) : les rappels après commit d'une transaction
     * imbriquée partent avant ceux de ses aînées validées plus tard, si bien
     * que, sur le fil, le retour du siège précède `game.resumed` et le
     * `round.scheduled` qu'il déclenche — ou le `game.ended` d'un battement
     * tardif.
     */
    private static function announceReturn(Room $lockedRoom, Player $lockedSeat): void
    {
        DB::transaction(static function () use ($lockedRoom, $lockedSeat): void {
            SeatUpdated::dispatch($lockedRoom, CurrentGame::of($lockedSeat), [
                'seat' => SeatViewPresenter::ofSeat($lockedSeat, CurrentGame::forState($lockedSeat), $lockedRoom->host_player_id),
            ]);
        });
    }

    /**
     * Le siège — connecté, non expulsé — est-il un siège présent de la partie
     * (§ 1.2) : une ligne `game_player` non expulsée ?
     */
    private static function presentIn(Game $game, Player $lockedSeat): bool
    {
        return GamePlayer::query()
            ->whereBelongsTo($game)
            ->where('player_id', $lockedSeat->id)
            ->where('status', '<>', GamePlayerStatus::Kicked->value)
            ->exists();
    }

    /**
     * Après commit, le balayage de présence armé à l'échéance de ce siège ;
     * sans effet si un balayage du salon (ou du siège solo) attend déjà.
     */
    private static function armSweep(Player $lockedSeat, CarbonImmutable $now): void
    {
        $sweep = $lockedSeat->room_id !== null
            ? SweepSeatPresence::forRoom($lockedSeat->room_id)
            : SweepSeatPresence::forSoloSeat($lockedSeat->id);
        $at = $now->addMilliseconds(EngineConstants::disconnectAfterMs());

        DB::afterCommit(static fn () => $sweep->armAt($at));
    }
}
