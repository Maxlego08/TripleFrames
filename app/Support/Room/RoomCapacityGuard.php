<?php

namespace App\Support\Room;

use App\Models\Player;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use Illuminate\Validation\ValidationException;

/**
 * Garde de capacité, HORS du value object (spec 50 § 10, contrat C0 § 4
 * invariant 3) : la capacité n'est jamais ABAISSÉE sous l'effectif présent.
 *
 * Le value object ne voit pas l'effectif ; la garde est donc évaluée sous le
 * verrou du salon, par `UpdateRoomSettings` et `ApplyRoomPreset`, entre
 * `fromInput()` et l'écrivain unique. Elle ne refuse qu'une écriture qui réunit
 * trois conditions :
 *
 * `new.capacity < current.capacity ∧ new.capacity < COUNT(player holdingSeat) ∧ new.capacity < roomSeats()`
 *
 * - « Baisse » : l'effectif peut légitimement dépasser la capacité (retour d'un
 *   siège parti, § 7.3), et l'éditeur réécrit toujours la capacité courante.
 *   Une garde qui comparerait la seule valeur résultante à l'effectif
 *   refuserait alors TOUTE écriture — un changement de `N`, de `M`, de `D`, un
 *   remède de vivier — sous un champ que l'hôte n'a pas touché.
 * - Plafond : la garde ne refuse jamais `roomSeats()`, plus haute valeur que le
 *   value object accepte ; la refuser ne laisserait aucune capacité valide.
 *
 * Aucun joueur n'est jamais expulsé par un changement de capacité. L'effectif
 * présent est `Player::holdingSeat()` (`connection_state <> 'left'`), jamais
 * l'historique des sièges ; aucun compteur dénormalisé.
 */
final readonly class RoomCapacityGuard
{
    /**
     * @param  Room  $room  Salon VERROUILLÉ par l'appelant.
     *
     * @throws ValidationException `validation.room_settings.capacity_below_headcount` sous `capacity`.
     */
    public static function assertAllowed(Room $room, RoomSettings $current, RoomSettings $next): void
    {
        if ($next->capacity >= $current->capacity || $next->capacity >= PlatformLimits::roomSeats()) {
            return;
        }

        $headcount = Player::query()
            ->where('room_id', $room->id)
            ->holdingSeat()
            ->count();

        if ($next->capacity >= $headcount) {
            return;
        }

        $message = __('validation.room_settings.capacity_below_headcount', ['count' => $headcount]);

        throw ValidationException::withMessages([
            'capacity' => [is_string($message) ? $message : 'validation.room_settings.capacity_below_headcount'],
        ]);
    }
}
