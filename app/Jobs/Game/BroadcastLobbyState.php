<?php

namespace App\Jobs\Game;

use App\Actions\Room\UpdateRoomSettings;
use App\Enums\RoomStatus;
use App\Events\Game\SettingsChanged;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Support\Room\RoomSettingsPresenter;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

/**
 * La diffusion anti-rebondie de l'état de lobby — spec 60 § 11.2, contrat C7
 * § 2.5 (nom et forme figés, consommé par 50) ; spec 50 § 8.3.
 *
 * **Un curseur ne produit pas une rafale d'événements** : l'écriture de
 * réglages est immédiate, c'est la DIFFUSION qui est coalescée par salon.
 * 50 dispatche ce job après chaque écriture de réglages et après un refus
 * `pool_insufficient`, le dispatch ENTIER après la validation de la
 * transaction, avec un délai exprimé **en instant arrondi à la seconde
 * supérieure** :
 *
 * ```php
 * $availableAt = $now->addMilliseconds(PlatformLimits::lobbyBroadcastDebounceMs())->ceilSecond();
 *
 * DB::afterCommit(static function () use ($roomId, $availableAt): void {
 *     BroadcastLobbyState::dispatch($roomId)->delay($availableAt);
 * });
 * ```
 *
 * — jamais `->delay(<entier>)`, que Laravel lit en SECONDES (écart (n) du
 * § 22 bis ; {@see PlatformLimits::lobbyBroadcastDebounceMs()}). Ce dispatch
 * est écrit une fois, côté 50 : {@see UpdateRoomSettings::dispatchLobbyBroadcast()}.
 * `DB::afterCommit()` et non `->afterCommit()` (écart E86-6) : ce dernier ne
 * diffère que la mise en file, le verrou d'unicité étant pris dès la
 * destruction du `PendingDispatch`, donc dans la transaction — une écriture
 * sautée faute de verrou, validée après que ce job a relu la salle, ne serait
 * jamais diffusée.
 *
 * - **Unique par salon jusqu'à son traitement**
 *   (`ShouldBeUniqueUntilProcessing`, `uniqueId()` = identifiant du salon) :
 *   toute écriture validée pendant la fenêtre ne dispatche rien, le verrou
 *   d'unicité étant tenu par un job qui n'a pas encore relu la salle ; une
 *   écriture validée après le début du traitement en dispatche un nouveau.
 *   Cohérence finale, sans numéro de révision stocké. Une transaction annulée
 *   ne prend jamais le verrou.
 * - **Relit l'état au moment d'émettre** (`RoomSettingsPresenter::state()`) :
 *   le DERNIER état part toujours. C'est la seule émission coalescée de la
 *   liste close ; la coalescence est au mieux, jamais une règle de jeu.
 * - **Ne fait rien** si le salon n'existe plus ou n'est plus au lobby : les
 *   réglages sont figés du lancement au « Rejouer ».
 * - **File `game`**, jamais `default` : derrière un traitement Imagick, la
 *   fenêtre deviendrait plusieurs secondes (50 § 8.3). Un seul essai, jamais
 *   de `release()` (contrat C7 § 2.5) : une diffusion perdue est rattrapée
 *   par la resynchronisation du client.
 *
 * Aucun identifiant interne sur le fil : `SettingsChanged` porte
 * `RoomSettingsState` en données (`themeKeys`, jamais `themeIds`), conformé
 * champ par champ à sa construction.
 */
final class BroadcastLobbyState implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Un seul essai : aucune diffusion ne se rejoue (`--tries=1`). */
    public int $tries = 1;

    public function __construct(public int $roomId)
    {
        $this->onQueue('game');
    }

    /** Unique par salon jusqu'à son traitement. */
    public function uniqueId(): int
    {
        return $this->roomId;
    }

    public function handle(): void
    {
        $room = Room::query()->find($this->roomId);

        if ($room === null || $room->status !== RoomStatus::Lobby) {
            return;
        }

        SettingsChanged::dispatch($room, null, RoomSettingsPresenter::state($room, Date::now()->toImmutable()));
    }
}
