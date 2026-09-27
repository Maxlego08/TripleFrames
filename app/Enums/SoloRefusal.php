<?php

namespace App\Enums;

/**
 * Refus de règle du démarrage solo (spec 60 § 16.2 et § 16.3, contrat C7
 * § 4.12) — liste close.
 *
 * Un refus de règle n'est jamais une exception rendue au client : l'action le
 * rend en données, et le contrôleur le traduit sous le champ `preset`, dans
 * la langue de la requête. Tout refus annule la transaction entière,
 * interruption de la partie solo en cours comprise. Un échec technique n'en
 * est pas un : il n'étend jamais cette liste (`room.errors.launch_failed`,
 * § 16.2).
 */
enum SoloRefusal: string
{
    /**
     * Drapeau de drainage posé (contrat C17 § 4.4, C18-bis) : message unique
     * `common.maintenance.launch_blocked`, aucune partie créée ni interrompue.
     */
    case Draining = 'draining';

    /**
     * Aucun `N` jouable pour le preset sur le vivier catalogue (D19 du
     * 23/09) : le rapport de vivier accompagne le refus, en données.
     */
    case PoolTooSmall = 'pool_too_small';

    /** Clé du message rendu à l'auteur du geste (`common` ou `game`). */
    public function messageKey(): string
    {
        return match ($this) {
            self::Draining => 'common.maintenance.launch_blocked',
            self::PoolTooSmall => 'game.errors.pool_too_small',
        };
    }
}
