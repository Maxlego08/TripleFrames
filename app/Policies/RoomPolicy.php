<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Support\Game\SeatPresence;

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
 * `launch` a pour appelant `room.launch` (L50-7a) et `replay`, `room.replay`
 * (L50-7b) ; `advanceRound` est posée avec le lancement pour le geste
 * « manche suivante » de `60` (contrat C7 § 2.4), dont l'action l'évalue sous
 * le verrou du salon. `kick`, `transferHost` et `leave` ont pour appelants
 * `room.players.kick`, `room.host.transfer` et `room.leave` (L50-6).
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
     * Lancer la partie (`room.launch`, § 12) : même clause que
     * {@see self::updateSettings()}. Le lancement relit l'autorité sous le
     * verrou du salon, après la réparation d'un hôte sans cible (L3).
     */
    public function launch(?User $user, Room $room, ?Player $seat): bool
    {
        return self::holdsHostSeat($room, $seat);
    }

    /**
     * « Rejouer » sur le podium (`room.replay`, § 13) : même clause que
     * {@see self::updateSettings()}. L'action relit l'autorité sous le verrou
     * du salon, après la réparation d'un hôte sans cible (R3), puis le gel de
     * la dernière partie et le drapeau de drainage.
     */
    public function replay(?User $user, Room $room, ?Player $seat): bool
    {
        return self::holdsHostSeat($room, $seat);
    }

    /**
     * « Manche suivante » pendant la révélation (spec 50 § 11.1, contrat C7
     * § 2.4) : même clause. Il raccourcit `R`, jamais `D` — effet propre à
     * `60`, qui l'évalue sous le verrou du salon.
     */
    public function advanceRound(?User $user, Room $room, ?Player $seat): bool
    {
        return self::holdsHostSeat($room, $seat);
    }

    /**
     * Mettre la partie en pause, ou retirer une pause demandée
     * (`room.game.pause`, `room.game.pause.cancel`, D64 du 07/10, spec 60
     * § 14.1 bis) : même clause que {@see self::updateSettings()}. Les actions
     * la relisent sous le verrou du salon.
     */
    public function pauseGame(?User $user, Room $room, ?Player $seat): bool
    {
        return self::holdsHostSeat($room, $seat);
    }

    /**
     * Reprendre une partie en pause (`room.game.resume`, D64 du 07/10, spec
     * 60 § 14.2) : l'hôte ; si l'hôte n'est pas un **siège présent** de la
     * partie (§ 1.2), tout siège présent de la partie. Relue sous le verrou
     * du salon par l'action. Sans partie, la clause d'hôte seule — le
     * contrôleur ne la consulte pas : il répond `not_running` à tout siège
     * du salon.
     */
    public function resumeGame(?User $user, Room $room, ?Player $seat, ?Game $game = null): bool
    {
        if (self::holdsHostSeat($room, $seat)) {
            return true;
        }

        return $seat !== null
            && $game !== null
            && $seat->room_id === $room->id
            && $game->room_id === $room->id
            && SeatPresence::isPresent($game, $seat->id)
            && ! SeatPresence::isPresent($game, $room->host_player_id);
    }

    /**
     * Retirer un siège du salon (`room.players.kick`, § 11.3) : même clause
     * que {@see self::updateSettings()}. L'expulsion relit l'autorité sous le
     * verrou du salon, et refuse que l'hôte se vise lui-même.
     */
    public function kick(?User $user, Room $room, ?Player $seat): bool
    {
        return self::holdsHostSeat($room, $seat);
    }

    /**
     * Confier le rôle d'hôte à un autre siège (`room.host.transfer`, § 11.4) :
     * même clause. Le transfert relit l'autorité sous le verrou du salon.
     */
    public function transferHost(?User $user, Room $room, ?Player $seat): bool
    {
        return self::holdsHostSeat($room, $seat);
    }

    /**
     * Quitter le salon (`room.leave`, § 11.4) : vrai si et seulement si le
     * siège appartient au salon. Chaque joueur peut quitter, hôte compris ;
     * ce n'est pas un geste d'hôte.
     */
    public function leave(?User $user, Room $room, ?Player $seat): bool
    {
        return $seat !== null && $seat->room_id === $room->id;
    }

    /**
     * Changer l'avatar de son siège (`room.avatar.update`, D55 du 02/10) :
     * même clause que {@see self::leave()} — le siège appartient au salon.
     * Geste de tout joueur sur son propre siège, jamais un geste d'hôte ; le
     * statut du salon (lobby seulement) est relu sous verrou par l'action.
     */
    public function changeAvatar(?User $user, Room $room, ?Player $seat): bool
    {
        return $seat !== null && $seat->room_id === $room->id;
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
