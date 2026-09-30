<?php

namespace App\Enums;

/**
 * Refus d'une prise de siège (spec 50 § 7.3).
 *
 * Liste close, rendue EN DONNÉES par `TakeSeat` — un refus de règle n'est
 * jamais une exception, comme {@see RoomRefusal} — et traduite en réponse
 * par le contrôleur d'entrée :
 * - `Archived` : 303 vers la page du salon, SANS erreur, qui rend « salon
 *   expiré » (410, § 16.3) : aucun message ne double la page ;
 * - `Kicked` et `Full` : retour au formulaire d'entrée avec l'erreur
 *   `room` = `__($refusal->messageKey())`, dans la langue de la requête.
 *
 * Un pseudo déjà pris n'en est pas un : c'est une erreur de champ
 * (`validation.nickname.taken` sous `nickname`, contrat C5 I5.4).
 */
enum JoinRefusal: string
{
    /** Salon archivé : le lien mène à la page « salon expiré ». */
    case Archived = 'archived';

    /**
     * Le jeton tient un siège EXPULSÉ de ce salon (D15 du 23/09) : refusé
     * avant tout comptage, jamais par une 1062, jusqu'à l'archivage.
     */
    case Kicked = 'kicked';

    /** Effectif présent ≥ capacité, compté sous le verrou du salon. */
    case Full = 'full';

    /**
     * Clé du message rendu au seul demandeur, `room.join.<valeur>` ; `null`
     * pour `Archived`, que la page « salon expiré » dit seule.
     */
    public function messageKey(): ?string
    {
        return $this === self::Archived ? null : 'room.join.'.$this->value;
    }
}
