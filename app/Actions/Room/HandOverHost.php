<?php

namespace App\Actions\Room;

use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'hôte confie son rôle à un autre siège — transfert manuel (spec 50
 * § 11.4 ; nom libre pour 50, contrat C6 § 8). Permis au lobby comme en
 * partie (§ 11.1). La confirmation se fait côté client
 * (`room.lobby.transfer_confirm`). Une transaction :
 *
 * 1. verrou du salon, puis `$now` ;
 * 2. autorité d'hôte relue sous ce verrou (`not_host`, 403 ; § 11.1) — un
 *    salon archivé n'a plus d'hôte ;
 * 3. cible résolue par `public_id` **dans ce salon**, sa ligne verrouillée
 *    (ordre `room → player`) ; une cible absente répond 404 ;
 * 4. cible = hôte courant → **aucune écriture** (idempotent) ; cible non
 *    `connected` ou expulsée → erreur de validation sous `publicId`
 *    (`room.lobby.transfer_unavailable`) ;
 * 5. {@see TransferHost::to()}, unique écrivain de `host_player_id`, qui émet
 *    `host.changed { hostPublicId, previousHostPublicId }` après la
 *    validation ;
 * 6. `room.last_activity_at = $now`, par mise à jour ciblée (§ 16.1).
 *
 * Le rôle ne se rend jamais de lui-même : l'ancien hôte ne le récupère pas,
 * sauf à ce qu'on le lui confie à son tour (§ 11.2).
 */
final readonly class HandOverHost
{
    /** Champ posté qui désigne la cible, et sous lequel le refus revient. */
    public const string FIELD = 'publicId';

    public function __construct(private TransferHost $transferHost) {}

    /**
     * @param  Player  $host  Siège du demandeur, résolu par son `player_token`.
     * @param  string  $targetPublicId  `public_id` du siège désigné.
     *
     * @throws AuthorizationException Le demandeur n'est pas l'hôte sous le verrou.
     * @throws ModelNotFoundException Aucun siège de ce salon ne porte ce `public_id`.
     * @throws ValidationException La cible n'est pas un siège connecté et non expulsé.
     */
    public function handle(Room $room, Player $host, string $targetPublicId): void
    {
        DB::transaction(function () use ($room, $host, $targetPublicId): void {
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

            // 3 — la cible, dans ce salon, verrouillée.
            $target = Player::query()
                ->whereBelongsTo($locked)
                ->where('public_id', $targetPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            // 4 — soi-même : rien ; absent ou expulsé : refus sous le champ.
            if ($target->id === $locked->host_player_id) {
                return;
            }

            if ($target->wasKicked() || $target->connection_state !== PlayerConnectionState::Connected) {
                throw ValidationException::withMessages([self::FIELD => [self::text(__('room.lobby.transfer_unavailable'))]]);
            }

            // 5 — l'unique écrivain de la référence d'hôte.
            $this->transferHost->to($locked, $target, $now);

            // 6 — source d'activité, par mise à jour ciblée.
            Room::query()->whereKey($locked->id)->update(['last_activity_at' => $now]);
        });
    }

    /** Un message traduit, dans la langue de la requête. */
    private static function text(mixed $message): string
    {
        return is_string($message) ? $message : '';
    }
}
