<?php

namespace App\Actions\Room;

use App\Enums\PlayerConnectionState;
use App\Events\Game\HostChanged;
use App\Models\Player;
use App\Models\Room;
use App\Support\Game\CurrentGame;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Transfert du rôle d'hôte (spec 50 § 11.2 ; 10 § 6.2, E10-34bis).
 *
 * **Unique écrivain de `room.host_player_id`**, référence souple sans clé
 * étrangère (10 § 1.5) : aucun autre code n'écrit cette colonne. Tous ses
 * gestes exigent une transaction ouverte et la ligne du salon verrouillée
 * par l'appelant (`lockForUpdate`, premier verrou de l'ordre global
 * `room → player → game → round → round_player`, E10-51) ; l'instance reçue
 * est celle que l'appelant a relue sous ce verrou.
 *
 * L'écriture passe par une **mise à jour ciblée** (§ 2.5, invariant 2),
 * jamais par `save()` sur une instance dont `settings` a été lu ; l'instance
 * est ensuite réalignée sans devenir « sale », pour que l'écrivain unique des
 * réglages (`WriteRoomSettings`) la reçoive encore propre.
 *
 * Tout changement effectif émet `host.changed { hostPublicId,
 * previousHostPublicId }` APRÈS la validation de la transaction
 * (`ShouldDispatchAfterCommit`). Un transfert vers `NULL` n'émet rien : il
 * n'y a personne à prévenir. Un transfert vers l'hôte courant n'écrit rien.
 *
 * Règles de fond (00 § Cycle de vie et cas limites) : transfert automatique
 * au DÉPART, jamais à la déconnexion ; l'ancien hôte ne récupère pas le rôle
 * à son retour. `$now` est l'instant pris par l'appelant après le verrou :
 * le transfert n'écrit pas `last_activity_at`, qui reste à la charge du geste
 * (§ 16.1). `clear()`, appelé par l'archivage, arrive avec le lot L50-8.
 */
final readonly class TransferHost
{
    /**
     * Désigne ce siège comme hôte — création du salon (§ 6.4) et transfert
     * manuel (`HandOverHost`, § 11.4). La cible appartient au salon, n'est
     * pas expulsée et est `connected` (à la création, le siège vient de
     * naître).
     *
     * @throws LogicException Transaction absente, ou cible hors de ces
     *                        conditions : un défaut de l'appelant, jamais un
     *                        cas d'exécution.
     */
    public function to(Room $room, Player $seat, CarbonImmutable $now): void
    {
        self::assertTransaction();

        if ($seat->room_id !== $room->id) {
            throw new LogicException('TransferHost::to() : le siège n’appartient pas à ce salon.');
        }

        if ($seat->kicked_at !== null || $seat->connection_state !== PlayerConnectionState::Connected) {
            throw new LogicException('TransferHost::to() : la cible doit être un siège connecté et non expulsé.');
        }

        $this->write($room, $seat);
    }

    /**
     * Transfert automatique — départ de l'hôte, lecture sans cible valide
     * (prise de siège S8, lancement L3, « Rejouer » R3, rendu du salon),
     * passage de l'hôte à `left` par la présence (`60`).
     *
     * Cible, l'hôte courant toujours exclu : le siège `connected` non expulsé
     * de `joined_at` le plus ancien (puis `id`) ; à défaut, le siège non
     * parti (`disconnected`) le plus ancien ; à défaut, `NULL`.
     *
     * @return Player|null le nouvel hôte, ou `null` si aucun siège ne peut
     *                     l'être
     *
     * @throws LogicException Transaction absente.
     */
    public function automatic(Room $room, CarbonImmutable $now): ?Player
    {
        self::assertTransaction();

        $target = $this->oldestSeat($room, PlayerConnectionState::Connected)
            ?? $this->oldestSeat($room, PlayerConnectionState::Disconnected);

        $this->write($room, $target);

        return $target;
    }

    /**
     * Le siège non expulsé le plus ancien du salon dans cet état de présence,
     * hôte courant exclu.
     */
    private function oldestSeat(Room $room, PlayerConnectionState $state): ?Player
    {
        return Player::query()
            ->whereBelongsTo($room)
            ->where('connection_state', $state->value)
            ->whereNull('kicked_at')
            ->when($room->host_player_id !== null, static fn ($query) => $query->whereKeyNot($room->host_player_id))
            ->orderBy('joined_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * L'écriture ciblée et l'événement : rien si la cible est déjà l'hôte,
     * aucun événement vers `NULL`.
     */
    private function write(Room $room, ?Player $target): void
    {
        $previousId = $room->host_player_id;
        $targetId = $target?->id;

        if ($previousId === $targetId) {
            return;
        }

        Room::query()->whereKey($room->id)->update(['host_player_id' => $targetId]);
        $room->forceFill(['host_player_id' => $targetId])->syncOriginalAttribute('host_player_id');

        if ($target === null) {
            return;
        }

        $previousPublicId = $previousId === null
            ? null
            : Player::query()->whereKey($previousId)->value('public_id');

        HostChanged::dispatch($room, CurrentGame::of($target), [
            'hostPublicId' => $target->public_id,
            'previousHostPublicId' => is_string($previousPublicId) ? $previousPublicId : null,
        ]);
    }

    private static function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TransferHost : une transaction doit être ouverte par l’appelant, salon verrouillé.');
        }
    }
}
