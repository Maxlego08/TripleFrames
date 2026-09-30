<?php

namespace App\Actions\Room;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Settings\RoomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Point d'écriture UNIQUE des réglages d'un salon (spec 50 § 2.5, contrat C0).
 *
 * Seul écrivain de `room.settings`, de `room.settings_version` et des cinq
 * projections typées — `capacity`, `frames_per_round`, `rounds_count`,
 * `input_difficulty`, `allow_late_join` —, toujours ensemble, pour que
 * `projection == value object` tienne sur toute ligne (10 § 6.2). Il pose aussi
 * `last_activity_at = $now` : toute écriture de réglages est une source
 * d'activité du salon (§ 16.1).
 *
 * Appelants, liste close : `CreateRoom`, {@see UpdateRoomSettings},
 * {@see ApplyRoomPreset}, `LaunchGame` (branche `settings_outdated`) et, au J2,
 * `LoadSavedConfig`. La validation qui fait autorité a eu lieu avant, sous le
 * verrou du salon : cet écrivain ne valide rien, il refuse seulement d'écrire
 * hors de ses préconditions, chacune par une `LogicException` — un appelant
 * fautif est une erreur de code, jamais un cas d'exécution :
 * - une transaction est ouverte ;
 * - l'instance sort d'un constructeur de la version courante
 *   (`sourceVersion === RoomSettings::VERSION`) : une ligne relue fidèlement
 *   sous une version antérieure passe d'abord par `normalize()` ;
 * - le salon est au lobby — à la création, le modèle n'est pas encore persisté
 *   et naît au lobby ; sinon, sa ligne a été verrouillée par l'appelant
 *   (`lockForUpdate`), que rien ici ne peut vérifier ;
 * - l'instance ne porte aucune autre modification : toute autre écriture de
 *   `room` passe par une mise à jour ciblée (§ 2.5, invariant 2), et
 *   `Model::save()` l'emporterait ici sous couvert de l'écrivain unique.
 *
 * **Gel** : hors du lobby, `room.settings` est immuable jusqu'au « Rejouer » de
 * l'hôte, podium compris (§ 12.7) ; en partie, le moteur ne lit que
 * `game.settings_snapshot`.
 */
final readonly class WriteRoomSettings
{
    /**
     * @throws LogicException Une précondition de l'écrivain unique est violée.
     */
    public function handle(Room $room, RoomSettings $settings, CarbonImmutable $now): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('WriteRoomSettings : une transaction doit être ouverte par l’appelant.');
        }

        if ($settings->sourceVersion !== RoomSettings::VERSION) {
            throw new LogicException(sprintf(
                'WriteRoomSettings : réglages de version %d, la version courante est %d ; normaliser d’abord.',
                $settings->sourceVersion,
                RoomSettings::VERSION,
            ));
        }

        if ($room->status !== RoomStatus::Lobby) {
            throw new LogicException(sprintf(
                'WriteRoomSettings : salon au statut [%s] ; les réglages ne s’écrivent qu’au lobby.',
                $room->status->value,
            ));
        }

        if ($room->exists && $room->isDirty()) {
            throw new LogicException(sprintf(
                'WriteRoomSettings : le salon porte des modifications étrangères aux réglages [%s].',
                implode(', ', array_keys($room->getDirty())),
            ));
        }

        $room->settings = $settings;
        $room->capacity = $settings->capacity;
        $room->frames_per_round = $settings->framesPerRound;
        $room->rounds_count = $settings->roundsCount;
        $room->input_difficulty = $settings->inputDifficulty;
        $room->allow_late_join = $settings->allowLateJoin;
        $room->last_activity_at = $now;
        $room->save();
    }
}
