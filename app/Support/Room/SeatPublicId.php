<?php

namespace App\Support\Room;

/**
 * L'identité publique d'un siège, `player.public_id` — spec 50 § 6.3 ; 10
 * § 1.1 et § 7.1.
 *
 * **Seul générateur de production** de `public_id`, lu par la prise de siège
 * de `50` et par le siège solo de `60` ; la fabrique en est lectrice. Même
 * motif que {@see RoomCode} : un chiffrage normatif n'habite pas `database/`.
 *
 * Aléatoire, **jamais dérivé de l'id** : un auto-increment divulguerait le
 * volume de sièges créés sur l'instance et resterait corrélable d'une partie
 * à l'autre. C'est lui, et jamais `player.id`, qui voyage en charge utile,
 * nomme le canal privé du siège et adresse un geste d'hôte.
 *
 * Aucune boucle d'absence, à la différence du code de salon : `public_id`
 * n'est jamais recyclé, et 32¹² (environ 10¹⁸) rend la collision négligeable ;
 * l'index UNIQUE de la colonne reste le juge.
 */
final class SeatPublicId
{
    /**
     * Base32 de Crockford : ni `I`, ni `L`, ni `O`, ni `U` (32 signes). Deux
     * initiales faites de ces lettres ne peuvent donc jamais figurer dans un
     * `public_id`.
     */
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Longueur : `player.public_id char(12)` (10 § 7.1). */
    public const int LENGTH = 12;

    /** Un `public_id` neuf : {@see self::LENGTH} tirages CSPRNG dans l'alphabet. */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $id = '';

        for ($index = 0; $index < self::LENGTH; $index++) {
            $id .= self::ALPHABET[random_int(0, $last)];
        }

        return $id;
    }
}
