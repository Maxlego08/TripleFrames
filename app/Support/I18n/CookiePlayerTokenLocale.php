<?php

namespace App\Support\I18n;

use App\Enums\Locale;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\Request;

/**
 * Niveau 3 réel de la résolution de langue (spec 40 § 4.1, contrat C4 I4.8) :
 * la revendication `locale` du `player_token` courant.
 *
 * Elle lit **par {@see PlayerTokenManager::current()}**, jamais par le cookie
 * lui-même : le gestionnaire est le seul lecteur du `player_token`
 * (`PlayerTokenBoundaryTest`), et sa lecture, mémorisée dans l'attribut de la
 * requête, resservira à `ensure()` dans la même requête (I4.2). `current()` ne
 * frappe jamais et ne repose jamais le cookie : résoudre une langue ne pose
 * aucun identifiant (I4.1).
 *
 * Ce niveau couvre exactement un cas (A-38) : **cookie `locale` absent ou
 * expiré alors que le jeton est présent**. Un autre navigateur n'a pas le jeton
 * non plus. `SetLocale` s'exécute après `EncryptCookies` dans le groupe `web` :
 * le jeton y est déjà déchiffré. Hors de cette pile — une URL inconnue rendue
 * par la page d'erreur —, la valeur arrive chiffrée, ne se décode pas, et le
 * niveau rend `null` sans exception.
 *
 * Une revendication de langue que {@see Locale::tryFrom()} ne connaît plus est
 * déjà devenue `null` au décodage (spec 40 § 3.3) : aucune valeur hors de
 * l'enum n'atteint `App::setLocale()`.
 */
final readonly class CookiePlayerTokenLocale implements PlayerTokenLocale
{
    public function __construct(private PlayerTokenManager $tokens) {}

    public function fromRequest(Request $request): ?Locale
    {
        return $this->tokens->current($request)?->locale;
    }
}
