<?php

namespace App\Actions\Room;

use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Models\Player;
use App\Models\Room;
use App\Settings\RoomSettingsEditor;
use App\Support\Draw\PoolQuery;
use App\Support\Room\RoomCapacityGuard;
use App\Support\Room\RoomSettingsPresenter;
use App\ValueObjects\Room\SettingsWriteOutcome;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'hôte règle son salon depuis l'onglet de réglages (spec 50 § 2.5, § 3).
 *
 * Une transaction, dans cet ordre :
 * 1. `Room::whereKey()->lockForUpdate()` — premier verrou de l'ordre global
 *    `room → player → game → round → round_player` (E10-51) ;
 * 2. `$now`, pris APRÈS le verrou ;
 * 3. autorité d'hôte relue sous le verrou (`not_host`, § 17.1) : la policy HTTP
 *    ne suffit jamais, l'hôte a pu changer entre la requête et le verrou ;
 * 4. statut `lobby`, sinon `not_in_lobby` : les réglages sont figés du
 *    lancement au « Rejouer », podium compris (§ 12.7) ;
 * 5. {@see RoomSettingsEditor} compose la charge postée avec l'état courant
 *    (règle D34 du 23/09) — c'est ici, sous le verrou, et jamais dans le
 *    FormRequest, qu'une entrée partielle se valide contre l'état courant ;
 * 6. `fromInput()` : bornes simples et croisées 1 et 2 ;
 * 7. garde de capacité ({@see RoomCapacityGuard}) ;
 * 8. {@see WriteRoomSettings}, écrivain unique.
 *
 * Un refus de règle est rendu en données ({@see SettingsWriteOutcome}) ; une
 * entrée invalide lève une `ValidationException`, erreurs indexées par champ
 * dans la langue de l'hôte, et rien n'est écrit. Le rapport de changements
 * part à l'auteur seul, jamais au salon (§ 2.6).
 *
 * La diffusion anti-rebondie de l'état (`BroadcastLobbyState`, § 8.3) se
 * dispatche après la validation de cette transaction : elle est posée par le
 * second temps du lot L50-2, qui suit le job de la spec 60 (L60-4).
 */
final readonly class UpdateRoomSettings
{
    public function __construct(
        private WriteRoomSettings $writer,
        private PoolQuery $pool,
    ) {}

    /**
     * @param  Player  $seat  Siège du demandeur, résolu par son `player_token`.
     * @param  array<string, mixed>  $posted  Corps de la requête, clés camelCase.
     *
     * @throws ValidationException
     */
    public function handle(Room $room, Player $seat, array $posted): SettingsWriteOutcome
    {
        return DB::transaction(function () use ($room, $seat, $posted): SettingsWriteOutcome {
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $now = Date::now()->toImmutable();

            if ($locked->host_player_id === null || $locked->host_player_id !== $seat->id || $seat->room_id !== $locked->id) {
                return SettingsWriteOutcome::refused(RoomRefusal::NotHost);
            }

            if ($locked->status !== RoomStatus::Lobby) {
                return SettingsWriteOutcome::refused(RoomRefusal::NotInLobby);
            }

            // Les thèmes publiés ne sont lus que si des clés de thème sont
            // postées : le chemin du J1, sélecteur masqué, n'en poste jamais.
            $current = $locked->settings;
            $publishedThemeIdsByKey = array_key_exists(RoomSettingsEditor::THEME_KEYS, $posted)
                ? $this->pool->publishedThemeIdsByKey()
                : [];
            $edited = RoomSettingsEditor::edit($current, $posted, $publishedThemeIdsByKey);
            $settings = RoomSettingsEditor::toSettings($edited['input']);

            RoomCapacityGuard::assertAllowed($locked, $current, $settings);

            $this->writer->handle($locked, $settings, $now);

            return SettingsWriteOutcome::written(RoomSettingsPresenter::changes($edited['changes']));
        });
    }
}
