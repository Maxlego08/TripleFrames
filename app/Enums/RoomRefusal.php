<?php

namespace App\Enums;

/**
 * Refus de règle d'un geste de salon (spec 50 § 12.1 et § 12.5, contrat C6).
 *
 * Liste close : écriture des réglages et application d'un preset (`not_host`,
 * `not_in_lobby`), lancement et « Rejouer ». Un refus de règle n'est jamais
 * une exception : l'action le rend en données, et le contrôleur le traduit en
 * réponse — `not_host` en 403, `not_in_lobby` en 303 vers la page du salon
 * sans erreur, les autres par `back()->withErrors(['room' => …])` dans la
 * langue de la requête. Un échec technique n'en est pas un : il n'étend
 * jamais cette liste (§ 12.5).
 *
 * Miroir client : `RoomRefusalCode`, dans `resources/js/types/room-settings.ts`.
 */
enum RoomRefusal: string
{
    /** Le demandeur n'est pas l'hôte courant, relu sous le verrou du salon (403). */
    case NotHost = 'not_host';

    case RoomArchived = 'room_archived';

    /** Salon en partie, podium compris : les réglages sont figés jusqu'au « Rejouer » (§ 12.7). */
    case NotInLobby = 'not_in_lobby';

    case SettingsOutdated = 'settings_outdated';

    case NotEnoughPlayers = 'not_enough_players';

    /** Drapeau de drainage posé : message unique `common.maintenance.launch_blocked` (R-09). */
    case Draining = 'draining';

    case PoolInsufficient = 'pool_insufficient';

    case GameNotEnded = 'game_not_ended';

    /**
     * Clé du message rendu à l'auteur du geste, jamais diffusée au salon.
     *
     * `room.refusal.draining` n'existe pas : le drainage emploie la clé unique
     * de la maintenance (contrat C6, R-09).
     */
    public function messageKey(): string
    {
        return $this === self::Draining
            ? 'common.maintenance.launch_blocked'
            : 'room.refusal.'.$this->value;
    }
}
