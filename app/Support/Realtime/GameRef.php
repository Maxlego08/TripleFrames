<?php

namespace App\Support\Realtime;

use App\Models\Game;
use Illuminate\Support\Facades\Config;

/**
 * Référence publique d'une partie, `gameRef` — spec 60 § 10.4, contrat C7
 * § 2.1.
 *
 * `substr(hash_hmac('sha256', 'tf:game-ref:'.game.id, APP_KEY), 0, 16)` :
 * **sans colonne**, jamais `game.id` en clair (§ 11.7), stable pour une partie
 * et différente d'une partie à l'autre d'un même salon (« Rejouer »), si bien
 * que le client ignore un événement d'une partie précédente. Le contexte
 * `tf:game-ref:` sépare cette dérivation de celle des clés de canal
 * ({@see ChannelNames::roomKey()}) : un même entier ne donne jamais deux
 * valeurs liées.
 */
final class GameRef
{
    /** Contexte de dérivation, propre aux références de partie. */
    private const string CONTEXT = 'tf:game-ref:';

    /** Longueur de la référence, en caractères hexadécimaux. */
    private const int LENGTH = 16;

    public static function for(Game $game): string
    {
        return substr(
            hash_hmac('sha256', self::CONTEXT.$game->id, Config::string('app.key')),
            0,
            self::LENGTH,
        );
    }
}
