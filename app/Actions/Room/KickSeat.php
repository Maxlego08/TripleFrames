<?php

namespace App\Actions\Room;

use App\Enums\GamePlayerStatus;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Events\Game\SeatKicked;
use App\Events\Game\SeatUpdated;
use App\Models\Player;
use App\Models\Room;
use App\Support\Game\CurrentGame;
use App\Support\Game\SeatViewPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'hôte retire un siège du salon — expulsion, D15 du 23/09 (spec 50 § 11.3,
 * contrat C4 I4.9). Une transaction, sous l'ordre de verrouillage global
 * `room → player → game → round → round_player` (E10-51) :
 *
 * 1. verrou du salon, puis `$now`, à la milliseconde ;
 * 2. autorité d'hôte relue sous ce verrou (`not_host`, 403) : la policy HTTP
 *    ne suffit jamais (§ 11.1) — un salon archivé n'a plus d'hôte ;
 * 3. la cible est l'hôte lui-même → erreur `room` (`room.lobby.cannot_kick_self`),
 *    jamais un 422 brut (§ 12.5) ; l'interface ne l'offre jamais ;
 * 4. et 5. verrou de la ligne de la cible, relue dans ce salon ; déjà
 *    expulsée → **aucune écriture** (un second geste ne fait rien). L'état
 *    d'expulsion est lu sur la ligne verrouillée, jamais sur l'instance reçue :
 *    deux expulsions se sérialisent sous le verrou du salon, et la seconde
 *    lirait sinon une instance chargée avant la première ;
 * 6. `connection_state = left`, `left_at = kicked_at = $now` — le même instant,
 *    à la milliseconde (C4 I4.9, E10-01) ;
 * 7. dernière partie du salon relue en `lockForUpdate` après le verrou du
 *    siège : en cours, la participation passe `kicked`, points conservés ;
 *    figée, rien ({@see SeatDeparture::markParticipation()}) ;
 * 8. `room.last_activity_at = $now` (source d'activité, § 16.1).
 *
 * **Après la validation** : `seat.updated` au salon (`SeatView` avec
 * `kicked: true`), `seat.kicked` au seul siège expulsé, puis la réévaluation
 * de la fin anticipée de la manche en cours, dans une seconde transaction
 * ({@see SeatDeparture::reevaluateAfterCommit()}) : ce geste ne tient jamais
 * le verrou de manche. « Aucune commande de l'hôte ne touche une manche en
 * cours » (§ 11.1) : l'expulsion n'en modifie ni l'horloge, ni les paliers,
 * ni les scores ; c'est un départ forcé.
 *
 * **Effets durables** : le jeton expulsé est refusé dans ce salon jusqu'à
 * l'archivage (`seatIn()` rend `null`, la prise de siège refuse par
 * `room.join.kicked` avant tout comptage) ; `kicked_at` n'est jamais remis à
 * NULL ; le refus tombe de lui-même à l'archivage, qui efface le hash.
 */
final readonly class KickSeat
{
    public function __construct(private SeatDeparture $departure) {}

    /**
     * @param  Player  $host  Siège du demandeur, résolu par son `player_token`.
     * @param  Player  $target  Siège visé, résolu par son `public_id` dans ce salon.
     *
     * @throws AuthorizationException Le demandeur n'est pas l'hôte sous le verrou.
     * @throws ValidationException L'hôte se vise lui-même (erreur `room`).
     * @throws ModelNotFoundException La cible n'est pas un siège de ce salon.
     */
    public function handle(Room $room, Player $host, Player $target): void
    {
        DB::transaction(function () use ($room, $host, $target): void {
            // 1 — premier verrou, puis l'instant.
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $now = Date::now()->toImmutable()->startOfMillisecond();

            // 2 — autorité relue sous le verrou.
            if ($locked->status === RoomStatus::Archived
                || $locked->host_player_id === null
                || $locked->host_player_id !== $host->id
                || $host->room_id !== $locked->id) {
                throw new AuthorizationException(self::text(__('room.refusal.not_host')));
            }

            // 3 — jamais soi-même.
            if ($target->id === $locked->host_player_id) {
                throw ValidationException::withMessages(['room' => [self::text(__('room.lobby.cannot_kick_self'))]]);
            }

            // 4 et 5 — la cible, verrouillée dans ce salon ; déjà expulsée : rien.
            $seat = Player::query()
                ->whereKey($target->id)
                ->whereBelongsTo($locked)
                ->lockForUpdate()
                ->firstOrFail();

            if ($seat->wasKicked()) {
                return;
            }

            // 6 — parti et expulsé au même instant.
            $seat->forceFill([
                'connection_state' => PlayerConnectionState::Left,
                'left_at' => $now,
                'kicked_at' => $now,
            ])->save();

            // 7 — la participation à la partie en cours, sous le verrou de `game`.
            $this->departure->markParticipation($locked, $seat, GamePlayerStatus::Kicked);

            // 8 — source d'activité, par mise à jour ciblée.
            Room::query()->whereKey($locked->id)->update(['last_activity_at' => $now]);

            // Après la validation : au salon, puis au seul siège expulsé,
            // puis le moteur réévalue la fin anticipée sous son propre verrou.
            $current = CurrentGame::of($seat);

            SeatUpdated::dispatch($locked, $current, [
                'seat' => SeatViewPresenter::ofSeat($seat, CurrentGame::forState($seat), $locked->host_player_id),
            ]);
            SeatKicked::dispatch($seat, $current, []);

            $this->departure->reevaluateAfterCommit($seat, $now);
        });
    }

    /** Un message traduit, dans la langue de la requête. */
    private static function text(mixed $message): string
    {
        return is_string($message) ? $message : '';
    }
}
