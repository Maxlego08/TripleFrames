<?php

namespace App\Actions\Room;

use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Events\Game\RoomReplayed;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Support\Deploy\DeployDrain;
use App\Support\Room\RoomSettingsPresenter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * « Rejouer » — le geste de l'hôte qui ramène le salon au lobby après le
 * podium : spec 50 § 13, contrat C6 § 3 (nom et signature figés). Une seule
 * transaction, dans cet ordre :
 *
 * | Étape | Action |
 * |---|---|
 * | R1 | Verrou du salon : premier verrou de l'ordre global `room → player → game → round → round_player` (E10-51). |
 * | R2 | `$now`, pris APRÈS le verrou, à la milliseconde. |
 * | R3 | Hôte sans cible valide → `TransferHost::automatic()` ; puis hôte ≠ demandeur → `not_host`. Un salon archivé est refusé `room_archived` AVANT cette réparation, qui écrirait un hôte sur un salon que l'archivage a vidé du sien (même lecture que le lancement, E102-1). |
 * | R4 | Déjà au lobby → `null` : le geste est idempotent (double clic, second onglet). |
 * | R5 | Dernière partie du salon (`game_room_started_idx`), relue sous verrou APRÈS le salon : `ended_at` nul → `game_not_ended`. Seul le gel (`FinalizeGame`, 80) pose `ended_at` et rend « Rejouer » possible (R-45). |
 * | R6 | Drapeau de drainage posé → `draining` (D32 du 23/09), message unique `common.maintenance.launch_blocked`. |
 * | R7 | `room.status = lobby`, `last_activity_at = $now`, par mise à jour ciblée. |
 * | R8 | APRÈS la validation : `room.replayed` au salon, qui porte `RoomSettingsState` recalculé — la non-répétition vient de réduire le vivier. |
 *
 * **Ce que « Rejouer » ne fait pas.** Il n'écrit ni les réglages, que l'hôte
 * retrouve tels qu'il les a figés au lancement et qui redeviennent
 * modifiables (§ 12.7), ni `launched_at`, posé au premier lancement
 * seulement (E10-28), ni aucun siège : les partis restent partis, les
 * expulsés restent expulsés, et un siège entré pendant la partie sans
 * participation devient un siège de lobby ordinaire (§ 15). Il ne lance rien
 * non plus : **le lancement suivant repasse par la garde de vivier**, rejouée
 * sous le verrou par `OpenGame` (00 § Déroulé d'une partie). La partie
 * terminée, ses colonnes et son instantané restent figés pour toujours
 * ({@see Game::FROZEN_COLUMNS}).
 *
 * **Autorité relue sous verrou** — hôte, statut, gel et drapeau : la policy
 * HTTP ne suffit jamais (§ 12.6, invariant 2). **Tout ou rien** : une
 * exception levée dans la transaction l'annule entière (le salon reste en
 * `playing`), et le contrôleur la rend traduite (§ 12.5). **Aucun effet
 * externe avant la validation** : la diffusion part après
 * (`ShouldDispatchAfterCommit`), sa charge précalculée sous le verrou.
 */
final readonly class ReplayRoom
{
    public function __construct(
        private TransferHost $transferHost,
        private DeployDrain $drain,
    ) {}

    /**
     * @param  Player  $requester  Siège du demandeur, résolu par son `player_token`.
     * @return RoomRefusal|null `null` si le salon est revenu au lobby, par cet
     *                          appel ou par un appel antérieur.
     *
     * @throws LogicException Un défaut d'appelant sous la transaction.
     */
    public function handle(Room $room, Player $requester): ?RoomRefusal
    {
        return DB::transaction(function () use ($room, $requester): ?RoomRefusal {
            // R1 — premier verrou, puis R2 — l'instant.
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $now = Date::now()->toImmutable()->startOfMillisecond();

            // R4, premier temps — un salon archivé n'a plus d'hôte (§ 16.2,
            // `TransferHost::clear()`) : il est refusé AVANT la réparation de
            // R3, qui lui en rendrait un.
            if ($locked->status === RoomStatus::Archived) {
                return RoomRefusal::RoomArchived;
            }

            // R3 — réparation de l'hôte, puis autorité relue sous le verrou.
            if (! TransferHost::hasValidHost($locked)) {
                $this->transferHost->automatic($locked, $now);
            }

            if ($locked->host_player_id === null
                || $locked->host_player_id !== $requester->id
                || $requester->room_id !== $locked->id) {
                return RoomRefusal::NotHost;
            }

            // R4 — déjà au lobby : fait, sans rien écrire ni diffuser.
            if ($locked->status === RoomStatus::Lobby) {
                return null;
            }

            // R5 — la dernière partie du salon doit être figée.
            $last = Game::query()
                ->where('room_id', $locked->id)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($last !== null && $last->ended_at === null) {
                return RoomRefusal::GameNotEnded;
            }

            // R6 — le drainage refuse « Rejouer », quel que soit son effet.
            if ($this->drain->isDraining()) {
                return RoomRefusal::Draining;
            }

            // R7 — le salon revient au lobby, par mise à jour ciblée : jamais
            // `save()` sur une instance dont les réglages ont été lus (§ 2.5).
            Room::query()->whereKey($locked->id)->update([
                'status' => RoomStatus::Lobby->value,
                'last_activity_at' => $now,
            ]);

            // R8 — après la validation : l'état des réglages recalculé, vivier
            // réduit par la non-répétition compris, identique pour tous.
            RoomReplayed::dispatch($locked, null, RoomSettingsPresenter::state($locked, $now));

            return null;
        });
    }
}
