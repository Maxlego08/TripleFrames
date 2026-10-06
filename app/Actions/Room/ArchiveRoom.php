<?php

namespace App\Actions\Room;

use App\Enums\RoomStatus;
use App\Events\Game\RoomArchived;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * L'archivage d'un salon — spec 50 § 16.2 ; 10 § 6.2 et § 11.1.
 *
 * **Unique chemin d'archivage** : l'archivage anticipé du lobby (périmètre
 * `stale_lobby`) et l'archivage à 24 h passent par le balayage de 50
 * (`App\Jobs\Room\ArchiveIdleRooms`), le filet `stale_room` à 48 h par la
 * purge de `100` ; tous appellent cette action, jamais une écriture directe.
 * L'archivage est l'**unique** événement qui recycle le `room_code`, efface
 * le jeton des sièges et ferme la fenêtre de rattachement tardif. **Le pseudo
 * survit** à l'archivage (D62 du 06/10) : il sert l'analyse des parties
 * pendant 12 mois, puis le périmètre `guest_nickname` de la purge
 * l'anonymise.
 *
 * Dans une transaction, sous le verrou du salon (premier verrou de l'ordre
 * global `room → player → game`, E10-51), `$now` pris après le verrou :
 *
 * 1. **le critère est relu sous verrou** : salon absent ou déjà archivé,
 *    `last_activity_at ≥ $idleBefore`, ou (`$lobbyOnly` et `launched_at` non
 *    nul) → `false`, sans écriture. Entre la sélection du balayage et le
 *    verrou, un joueur a pu entrer ou battre : archiver un salon redevenu
 *    actif effacerait les pseudos de joueurs présents ;
 * 2. dernière partie du salon à `ended_at` NULL → `false` et journal —
 *    **défensif** : la terminaison bornée de `60` (contrat C17) rend ce cas
 *    impossible, et la sonde n° 1 de 10 § 11.3 le verrait. Lecture simple,
 *    sans verrou de la partie : un lancement exige le verrou du salon, que
 *    l'action tient, et une partie ne peut que passer de « en cours » à
 *    « figée » ;
 * 3. `status = archived`, `archived_at = $now`, `room_code_active = NULL`
 *    (le code redevient attribuable), par mise à jour ciblée, jamais par
 *    `save()` sur une instance dont `settings` a été lu (§ 2.5) ; puis
 *    {@see TransferHost::clear()} ;
 * 4. tous les sièges du salon — partis et expulsés compris — :
 *    `player_token_hash` à NULL, par une mise à jour Eloquent (`updated_at` à
 *    la milliseconde, `$dateFormat` de `Player`) ; ce qui ferme le
 *    rattachement tardif et lève le refus d'un siège expulsé. Pseudo, forme
 *    normalisée et pseudo figé des parties restent (D62 du 06/10).
 *
 * **L'effacement dans la même transaction** (10 § 11.1) : aucune
 * interruption ne laisse un salon archivé dont un jeton ouvrirait encore un
 * siège.
 *
 * **Après validation** : `room.archived` au canal du salon
 * (`ShouldDispatchAfterCommit`), charge `{}` hors enveloppe, sans partie — la
 * dernière partie est figée (étape 2). Les clients encore ouverts quittent
 * leurs canaux et visitent `room.show`, qui rend « salon expiré » en 410.
 *
 * **Aucune ligne n'est supprimée** : les lignes `room` et `player` partent
 * plus tard, par dépendance (10 § 11.1, `orphan_player`).
 */
final readonly class ArchiveRoom
{
    public function __construct(
        private TransferHost $transferHost,
    ) {}

    /**
     * @param  CarbonImmutable  $idleBefore  Échéance : le salon n'est archivé que si sa dernière activité lui est strictement antérieure.
     * @param  bool  $lobbyOnly  Archivage anticipé du lobby : un salon déjà lancé une fois n'est jamais touché.
     * @return bool `true` si CET appel a archivé le salon.
     */
    public function handle(Room $room, CarbonImmutable $idleBefore, bool $lobbyOnly = false): bool
    {
        return DB::transaction(function () use ($room, $idleBefore, $lobbyOnly): bool {
            $locked = Room::query()->whereKey($room->getKey())->lockForUpdate()->first();
            $now = Date::now()->toImmutable();

            if (! $locked instanceof Room
                || $locked->archived_at !== null
                || $locked->status === RoomStatus::Archived
                || $locked->last_activity_at->greaterThanOrEqualTo($idleBefore)
                || ($lobbyOnly && $locked->launched_at !== null)) {
                return false;
            }

            $lastGame = Game::query()
                ->whereBelongsTo($locked)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();

            if ($lastGame !== null && $lastGame->ended_at === null) {
                // Aucune donnée de joueur : des identifiants internes, pour
                // l'exploitant qui croise la sonde n° 1.
                Log::warning('Archivage d’un salon refusé : sa dernière partie n’est pas figée.', [
                    'room_id' => $locked->id,
                    'game_id' => $lastGame->id,
                    'lobby_only' => $lobbyOnly,
                ]);

                return false;
            }

            $archived = [
                'status' => RoomStatus::Archived,
                'archived_at' => $now,
                'room_code_active' => null,
            ];

            Room::query()->whereKey($locked->id)->update($archived);
            $locked->forceFill($archived)->syncOriginalAttributes(array_keys($archived));

            $this->transferHost->clear($locked, $now);

            Player::query()->whereBelongsTo($locked)->update([
                'player_token_hash' => null,
            ]);

            RoomArchived::dispatch($locked, null, []);

            return true;
        });
    }
}
