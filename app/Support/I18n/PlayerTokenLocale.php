<?php

namespace App\Support\I18n;

use App\Enums\Locale;
use Illuminate\Http\Request;

/**
 * Niveau 3 de l'ordre de résolution de la spec 05 : la revendication `locale`
 * du `player_token`, qui restaure la langue d'un invité revenu sans cookie
 * `locale` alors que son jeton est toujours là.
 *
 * C'est un contrat et non une implémentation parce que la **forme** du
 * `player_token` — charge utile, transport en cookie `HttpOnly` chiffré,
 * re-signature au changement de langue — appartient à
 * `40-comptes-auth-sociale-et-avatars.md` [J1] (§ 3 et § 4) ;
 * `10-catalogue-et-modele-de-donnees.md` n'en garde que le hash,
 * `player.player_token_hash` (E10-11, E10-33).
 *
 * Liée à {@see CookiePlayerTokenLocale} dans
 * `AppServiceProvider::registerLocalization()` (lot L40-2) : le middleware
 * `SetLocale` dépend de ce contrat, jamais d'une implémentation.
 */
interface PlayerTokenLocale
{
    /**
     * Locale portée par le `player_token` de la requête, ou `null` si la
     * requête n'en porte aucun, s'il est invalide (MAC faux, charge illisible,
     * version inconnue), ou si sa revendication ne désigne pas une locale
     * activée.
     */
    public function fromRequest(Request $request): ?Locale;
}
