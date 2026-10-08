<?php

namespace App\Actions\Room;

use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Enums\SettingPresetKey;
use App\Models\Player;
use App\Models\Room;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use App\Support\Room\RoomCapacityGuard;
use App\Support\Room\RoomSettingsPresenter;
use App\ValueObjects\Room\SettingsWriteOutcome;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'hôte applique un preset du site (spec 50 § 5.3), même séquence que
 * {@see UpdateRoomSettings} : verrou du salon, `$now` après le verrou, autorité
 * d'hôte relue, statut `lobby`, puis le preset, la garde de capacité et
 * l'écrivain unique.
 *
 * **Les seize champs** sont pré-remplis par
 * {@see SettingPresetCatalog::settingsFor()}, qui passe par `fromInput()` : les
 * thèmes reviennent à `[]` et la capacité à `roomSeats()`, plafond que la garde
 * de capacité ne refuse jamais. Les champs de l'onglet Simple que le preset
 * change sont visibles à l'écran et ne sont pas rapportés ; chaque réglage
 * propre à l'onglet Avancé, personnalisé et changé par le preset, est
 * rapporté `overwritten` ({@see RoomSettingsEditor::overwritten()}, lot
 * L50-10) : sans ce rapport, l'écrasement d'un réglage invisible serait
 * silencieux.
 *
 * Le serveur n'interdit pas d'appliquer un preset grisé : le lobby montre alors
 * le blocage du vivier et ses remèdes, et seule la garde de lancement fait
 * autorité (§ 5.3).
 *
 * Après la validation de la transaction, la diffusion anti-rebondie de l'état
 * (`BroadcastLobbyState`, § 8.3) part par le même dispatch que
 * {@see UpdateRoomSettings::dispatchLobbyBroadcast()}.
 */
final readonly class ApplyRoomPreset
{
    public function __construct(private WriteRoomSettings $writer) {}

    /**
     * @param  Player  $seat  Siège du demandeur, résolu par son `player_token`.
     *
     * @throws ValidationException
     */
    public function handle(Room $room, Player $seat, SettingPresetKey $preset): SettingsWriteOutcome
    {
        return DB::transaction(function () use ($room, $seat, $preset): SettingsWriteOutcome {
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $now = Date::now()->toImmutable();

            if ($locked->host_player_id === null || $locked->host_player_id !== $seat->id || $seat->room_id !== $locked->id) {
                return SettingsWriteOutcome::refused(RoomRefusal::NotHost);
            }

            if ($locked->status !== RoomStatus::Lobby) {
                return SettingsWriteOutcome::refused(RoomRefusal::NotInLobby);
            }

            $current = $locked->settings;
            $settings = SettingPresetCatalog::settingsFor($preset);

            RoomCapacityGuard::assertAllowed($locked, $current, $settings);

            $changes = RoomSettingsEditor::overwritten($current, $settings);

            $this->writer->handle($locked, $settings, $now);
            UpdateRoomSettings::dispatchLobbyBroadcast($locked, $now);

            return SettingsWriteOutcome::written(RoomSettingsPresenter::changes($changes));
        });
    }
}
