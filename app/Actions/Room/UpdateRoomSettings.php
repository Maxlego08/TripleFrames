<?php

namespace App\Actions\Room;

use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Jobs\Game\BroadcastLobbyState;
use App\Models\Player;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsEditor;
use App\Support\Draw\PoolQuery;
use App\Support\Room\RoomCapacityGuard;
use App\Support\Room\RoomSettingsPresenter;
use App\ValueObjects\Room\SettingsWriteOutcome;
use Carbon\CarbonImmutable;
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
 * 8. {@see WriteRoomSettings}, écrivain unique ;
 * 9. après la validation de la transaction, dispatch de la diffusion
 *    anti-rebondie {@see BroadcastLobbyState} (§ 8.3), par
 *    {@see self::dispatchLobbyBroadcast()} — verrou d'unicité compris.
 *
 * Un refus de règle est rendu en données ({@see SettingsWriteOutcome}) ; une
 * entrée invalide lève une `ValidationException`, erreurs indexées par champ
 * dans la langue de l'hôte, et rien n'est écrit ni dispatché. Le rapport de
 * changements part à l'auteur seul, jamais au salon (§ 2.6) : le salon reçoit
 * l'ÉTAT, relu par le job au moment d'émettre.
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
            self::dispatchLobbyBroadcast($locked, $now);

            return SettingsWriteOutcome::written(RoomSettingsPresenter::changes($edited['changes']));
        });
    }

    /**
     * La diffusion anti-rebondie de l'état du lobby (spec 50 § 8.3), commune
     * aux deux écritures de réglages de l'hôte ({@see ApplyRoomPreset}) :
     *
     * - **le dispatch ENTIER après la validation de la transaction la plus
     *   externe** (`DB::afterCommit()`), et non `->afterCommit()` sur le job :
     *   celui-ci ne diffère que la mise en file, alors que le verrou
     *   d'unicité est pris tout de suite, à la destruction du
     *   `PendingDispatch`. Pris dans la transaction, il ferait sauter le
     *   dispatch d'une écriture B pendant qu'un job A attend ; si le worker
     *   traite A avant le commit de B, A relit l'état validé SANS B, et plus
     *   rien ne reste en file pour B (écart E86-6). Après la validation, la
     *   prise du verrou suit toujours le commit : ou le verrou est tenu par un
     *   job qui n'a pas encore relu la salle (il lira B), ou il est libre et
     *   B entre en file. Une écriture annulée ne prend jamais le verrou ;
     *   hors transaction, le dispatch est immédiat ;
     * - **file `game`, unique par salon jusqu'à son traitement** : une écriture
     *   qui survient pendant la fenêtre ne dispatche rien, et le job relit
     *   l'état au moment d'émettre ;
     * - **délai en INSTANT**, `$now` + `lobbyBroadcastDebounceMs()` arrondi à
     *   la seconde supérieure — jamais `->delay(<entier>)`, que Laravel lit en
     *   secondes (300 deviendrait cinq minutes). La file tronque tout instant
     *   à la seconde (`availableAt()`) : l'arrondi garantit une fenêtre
     *   effective comprise entre `lobbyBroadcastDebounceMs()` et
     *   `lobbyBroadcastDebounceMs()` + 1 s, jamais plus courte.
     *
     * À appeler DANS la transaction, après l'écrivain unique : `$now` est
     * celui pris après le verrou, et rien ne part avant la validation.
     */
    public static function dispatchLobbyBroadcast(Room $room, CarbonImmutable $now): void
    {
        $roomId = $room->id;
        $availableAt = $now->addMilliseconds(PlatformLimits::lobbyBroadcastDebounceMs())->ceilSecond();

        DB::afterCommit(static function () use ($roomId, $availableAt): void {
            BroadcastLobbyState::dispatch($roomId)->delay($availableAt);
        });
    }
}
