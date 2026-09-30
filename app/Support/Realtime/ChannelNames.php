<?php

namespace App\Support\Realtime;

use App\Models\Player;
use App\Models\Room;
use Illuminate\Support\Facades\Config;

/**
 * Noms des canaux Reverb — spec 60 § 10.4, contrat C7 § 2.1.
 *
 * | Nom Echo | Nom Pusher | Nature |
 * |---|---|---|
 * | `room.{roomKey}` | `presence-room.{roomKey}` | diffusion au salon, lobby et partie |
 * | `seat.{publicId}` | `private-seat.{publicId}` | envoi ciblé à un siège |
 *
 * **`roomKey`** = `substr(hash_hmac('sha256', 'tf:room-channel:'.room.id,
 * APP_KEY), 0, 32)`. Jamais `room_code`, recyclé à l'archivage (10 § 6.2) :
 * un onglet resté abonné recevrait le salon suivant. Jamais `room.id` en
 * clair, aucune colonne (E10-31). Aucun canal en solo (10 § 7.10).
 */
final class ChannelNames
{
    /** Préfixe Echo du canal de présence d'un salon. */
    public const string ROOM_PREFIX = 'room.';

    /** Préfixe Echo du canal privé d'un siège. */
    public const string SEAT_PREFIX = 'seat.';

    /** Contexte de dérivation, propre aux clés de canal de salon. */
    private const string ROOM_KEY_CONTEXT = 'tf:room-channel:';

    /** Longueur de la clé de canal, en caractères hexadécimaux. */
    private const int ROOM_KEY_LENGTH = 32;

    public static function roomKey(Room $room): string
    {
        return substr(
            hash_hmac('sha256', self::ROOM_KEY_CONTEXT.$room->id, Config::string('app.key')),
            0,
            self::ROOM_KEY_LENGTH,
        );
    }

    /** `room.{roomKey}` : canal de présence du salon. */
    public static function room(Room $room): string
    {
        return self::ROOM_PREFIX.self::roomKey($room);
    }

    /** `seat.{publicId}` : canal privé du siège, par son `public_id`. */
    public static function seat(Player $seat): string
    {
        return self::SEAT_PREFIX.$seat->public_id;
    }
}
