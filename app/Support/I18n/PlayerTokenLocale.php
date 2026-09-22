<?php

namespace App\Support\I18n;

use App\Enums\Locale;
use Illuminate\Http\Request;

/**
 * Niveau 3 de l'ordre de résolution de la spec 05 : la revendication `locale`
 * du `player_token` signé, qui restaure la langue d'un invité revenu sans
 * cookie en même temps que son siège.
 *
 * C'est un contrat et non une implémentation parce que la **forme** du
 * `player_token` — signature, revendications, re-signature au changement de
 * langue — appartient à `10-catalogue-et-modele-de-donnees.md` et à
 * `40-comptes-auth-sociale-et-avatars.md`. Le schéma ne porte aujourd'hui que
 * `player.player_token_hash` : aucun jeton n'est encore frappé.
 *
 * Point de branchement unique : lier ce contrat à une implémentation réelle
 * dans `AppServiceProvider::register()` suffit à activer le niveau 3, sans
 * toucher au middleware ni à son test.
 */
interface PlayerTokenLocale
{
    /**
     * Locale portée par le `player_token` de la requête, ou `null` si la
     * requête n'en porte aucun, si sa signature est invalide, ou si sa
     * revendication ne désigne pas une locale activée.
     */
    public function fromRequest(Request $request): ?Locale;
}
