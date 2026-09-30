<?php

namespace App\Events\Game;

/**
 * `settings.changed` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `BroadcastLobbyState` après écriture de réglages (50) ;
 * refus `pool_insufficient` (50). Seule émission coalescée de la liste
 * close, relue au moment d'émettre.
 *
 * Charge, hors enveloppe : `RoomSettingsState` (contrat C0 § 3.4) : `{
 * settings: RoomSettingsView, warnings: RoomSettingsWarningCode[], pool:
 * PoolReport }` — `themeKeys` et jamais `themeIds`, aucune chaîne
 * traduite.
 */
final class SettingsChanged extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'settings' => 'object',
        'warnings' => 'list',
        'pool' => 'object',
    ];

    public function broadcastAs(): string
    {
        return 'settings.changed';
    }
}
