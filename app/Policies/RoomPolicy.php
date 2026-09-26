<?php

namespace App\Policies;

use App\Models\Player;
use App\Models\Room;
use App\Models\User;

/**
 * Gestes de salon (spec 50 § 17.1, contrat C6), découverte automatiquement
 * pour {@see Room}.
 *
 * **L'hôte est un siège, pas un compte.** Chaque méthode reçoit `?User $user`
 * parce que l'hôte est le plus souvent un invité, et le SIÈGE du demandeur,
 * résolu par son `player_token` (`seat.active`, ou
 * `PlayerTokenManager::seatIn()`, expulsé exclu). Les contrôleurs appellent
 * `Gate::authorize('<geste>', [$room, $seat])`.
 *
 * - **Aucune clause de rôle** : `$user->role` n'est jamais lu ici. Un admin
 *   n'a aucun geste sur un salon, et il n'existe pas de `Gate::before`
 *   (« inspecter une partie » est un écran de back-office en lecture, spec 20).
 * - **La policy ne suffit jamais** : chaque action relit l'autorité sous le
 *   verrou du salon, l'hôte ayant pu changer entre la requête et le verrou
 *   (spec 50 § 2.5, § 12.6 invariant 2).
 *
 * Les autres gestes de la table du § 17.1 (`launch`, `replay`,
 * `advanceRound`, `kick`, `transferHost`, `leave`) arrivent avec leurs lots ;
 * au lot L50-2, seul `updateSettings` a un appelant.
 */
class RoomPolicy
{
    /**
     * Régler le salon — écriture de l'onglet Simple ou application d'un preset
     * (`room.settings.update`, `room.settings.preset`) : vrai si et seulement
     * si le siège appartient au salon et en est l'hôte.
     */
    public function updateSettings(?User $user, Room $room, ?Player $seat): bool
    {
        return self::holdsHostSeat($room, $seat);
    }

    /**
     * `$seat` appartient au salon et `room.host_player_id = $seat->id`. La
     * référence d'hôte est souple (sans clé étrangère) : NULL ne désigne
     * personne.
     */
    private static function holdsHostSeat(Room $room, ?Player $seat): bool
    {
        return $seat !== null
            && $seat->room_id === $room->id
            && $room->host_player_id !== null
            && $room->host_player_id === $seat->id;
    }
}
