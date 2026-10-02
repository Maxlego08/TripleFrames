<?php

namespace App\Actions\Room;

use App\Avatars\SeatAvatar;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Events\Game\SeatUpdated;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Support\Game\SeatViewPresenter;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Room\LobbyAvatars;
use App\Support\Room\TakenAvatars;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un siège change d'avatar en salle d'attente — `room.avatar.update` (spec 50
 * § 8.1, spec 40 § 11.4 ; D55 du 02/10).
 *
 * Une transaction, sous le verrou du salon puis celui du siège, `$now` pris
 * après les verrous (ordre global `room → player`, E10-51), comme
 * {@see LeaveRoom} :
 *
 * 1. salon archivé → {@see RoomRefusal::RoomArchived} ; salon hors du lobby
 *    — partie en cours, **podium compris**, jusqu'au « Rejouer » — →
 *    {@see RoomRefusal::NotInLobby} : l'identité est gelée au lancement
 *    (`game_player.display_*`), un changement n'y aurait aucun effet ;
 * 2. siège expulsé ou parti : rien ;
 * 3. une clé du catalogue tenue par un AUTRE siège tenu du salon — prédéfini
 *    de repli d'un siège `upload` ou `provider` compris ({@see TakenAvatars})
 *    — est refusée sous `avatar` (`room.lobby.avatar_taken`), sans rien
 *    écrire ; « Mon avatar » relit le compte sous le verrou et reçoit un
 *    repli libre ({@see SeatAvatar::resolve()}) ;
 * 4. **idempotent** : un choix qui ne change rien n'écrit rien et ne diffuse
 *    rien ;
 * 5. `player.avatar_kind` et `avatar_preset`, puis `room.last_activity_at`
 *    par mise à jour ciblée ;
 * 6. après la validation : `seat.updated` au salon (vue de lobby), puis la
 *    re-signature du `player_token` avec le prédéfini retenu, préférence du
 *    salon suivant (I4.5).
 */
final readonly class ChangeSeatAvatar
{
    public function __construct(private PlayerTokenManager $tokens) {}

    /**
     * @param  Player  $seat  Siège du demandeur, résolu par son `player_token`.
     * @param  string  $choice  Clé validée du catalogue, ou {@see SeatAvatar::ACCOUNT}.
     * @return RoomRefusal|null le refus de règle, ou `null` (écrit, ou inchangé)
     *
     * @throws ValidationException La clé est tenue par un autre siège du salon.
     * @throws ModelNotFoundException Le siège n'appartient pas à ce salon.
     */
    public function handle(Room $room, Player $seat, string $choice, Request $request): ?RoomRefusal
    {
        return DB::transaction(function () use ($room, $seat, $choice, $request): ?RoomRefusal {
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $lockedSeat = Player::query()
                ->whereKey($seat->id)
                ->whereBelongsTo($locked)
                ->lockForUpdate()
                ->firstOrFail();
            $now = Date::now()->toImmutable()->startOfMillisecond();

            if ($locked->status === RoomStatus::Archived) {
                return RoomRefusal::RoomArchived;
            }

            if ($locked->status !== RoomStatus::Lobby) {
                return RoomRefusal::NotInLobby;
            }

            if ($lockedSeat->wasKicked() || $lockedSeat->connection_state === PlayerConnectionState::Left) {
                return null;
            }

            // « Mon avatar » déjà affiché : rien à changer.
            if ($choice === SeatAvatar::ACCOUNT && LobbyAvatars::current($lockedSeat) === SeatAvatar::ACCOUNT) {
                return null;
            }

            $taken = TakenAvatars::of($locked, $lockedSeat);

            if ($choice !== SeatAvatar::ACCOUNT && in_array($choice, $taken, true)) {
                throw ValidationException::withMessages(['avatar' => [self::text(__('room.lobby.avatar_taken'))]]);
            }

            // « Mon avatar » se lit sur le compte du siège, jamais sur un autre
            // compte connecté au même navigateur.
            // La FormRequest a déjà refusé `account` hors de ce compte.
            $user = SeatAvatar::seatAccount($lockedSeat, $request->user() instanceof User ? $request->user() : null);
            $avatar = SeatAvatar::resolve($choice, $user, $lockedSeat->avatar_preset, $taken);

            if ($lockedSeat->avatar_kind === $avatar->kind && $lockedSeat->avatar_preset === $avatar->preset) {
                return null;
            }

            $lockedSeat->forceFill([
                'avatar_kind' => $avatar->kind,
                'avatar_preset' => $avatar->preset,
            ])->save();

            Room::query()->whereKey($locked->id)->update(['last_activity_at' => $now]);

            SeatUpdated::dispatch($locked, null, [
                'seat' => SeatViewPresenter::lobby($lockedSeat, $locked->host_player_id),
            ]);

            $this->resignAfterCommit($request, $avatar->preset);

            return null;
        });
    }

    /** La préférence d'avatar du jeton, re-signée après la validation (I4.5). */
    private function resignAfterCommit(Request $request, string $preset): void
    {
        DB::afterCommit(function () use ($request, $preset): void {
            $token = $this->tokens->current($request);

            if ($token !== null) {
                $this->tokens->resign($request, $token->withAvatar($preset));
            }
        });
    }

    /** Un message traduit, dans la langue de la requête. */
    private static function text(mixed $message): string
    {
        return is_string($message) ? $message : 'room.lobby.avatar_taken';
    }
}
