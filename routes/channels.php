<?php

use App\Broadcasting\RoomPresenceChannel;
use App\Broadcasting\SeatPrivateChannel;
use App\Support\Realtime\ChannelNames;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canaux de diffusion — spec 60 § 10.4, contrat C7 § 2.1 et § 2.2
|--------------------------------------------------------------------------
|
| Deux canaux, et aucun en solo (10 § 7.10) :
|
| - `room.{roomKey}` (Pusher : `presence-room.{roomKey}`), diffusion au
|   salon, lobby et partie ; `roomKey` = HMAC d'`APP_KEY`, jamais le
|   `room_code` ({@see ChannelNames::roomKey()}) ;
| - `seat.{publicId}` (Pusher : `private-seat.{publicId}`), envoi ciblé à un
|   siège.
|
| Garde `player` seule (`config/auth.php`) : un invité s'autorise par son
| `player_token`, jamais par la session ni par un compte. Enregistré par
| `withBroadcasting()` (`bootstrap/app.php`), sous `web` — qui porte
| `EncryptCookies`, sans quoi le jeton se lirait absent — et
| `throttle:game-read`. JAMAIS par `install:broadcasting`, qui ajouterait un
| second `/broadcasting/auth` sans ce middleware (spec 60, lot L60-1).
|
*/

Broadcast::channel(ChannelNames::ROOM_PREFIX.'{roomKey}', RoomPresenceChannel::class, ['guards' => ['player']]);

Broadcast::channel(ChannelNames::SEAT_PREFIX.'{publicId}', SeatPrivateChannel::class, ['guards' => ['player']]);
