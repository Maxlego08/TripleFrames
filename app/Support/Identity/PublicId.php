<?php

namespace App\Support\Identity;

use App\Support\Room\SeatPublicId;

/**
 * Le générateur partagé des identités publiques en base32 de Crockford —
 * spec 10 § 1.1 : `player.public_id` (par {@see SeatPublicId}) et, depuis
 * D63 du 07/10, `frame.public_id`.
 *
 * Aléatoire, **jamais dérivé de l'id** : un auto-increment divulguerait un
 * volume et resterait corrélable. Aucune boucle d'absence : 32¹² (environ
 * 10¹⁸) rend la collision négligeable, l'index UNIQUE de chaque colonne reste
 * le juge.
 */
final class PublicId
{
    /**
     * Base32 de Crockford : ni `I`, ni `L`, ni `O`, ni `U` (32 signes).
     */
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Longueur : `char(12)` pour chaque colonne `public_id` (10 § 1.1). */
    public const int LENGTH = 12;

    /** Une identité neuve : {@see self::LENGTH} tirages CSPRNG dans l'alphabet. */
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
