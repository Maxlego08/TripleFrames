<?php

namespace App\Http\Controllers\Locale;

use App\Http\Controllers\Controller;
use App\Http\Requests\Locale\LocaleUpdateRequest;
use App\Models\Player;
use App\Models\User;
use App\Support\I18n\LocaleCookie;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\RedirectResponse;

class LocaleController extends Controller
{
    /**
     * Persiste la langue choisie : `users.locale` si le joueur est connecté,
     * cookie `locale` dans tous les cas, puis `back()`.
     *
     * **Si la requête porte un `player_token`** (spec 40 § 4.2, I4.8) :
     *
     * 1. il est re-signé avec la nouvelle revendication de langue, **sous le
     *    même `tid`** — donc sans changer le siège que retrouve son hash (I4.4) ;
     *    le cookie repart de 30 jours pleins (§ 3.6) ;
     * 2. chaque siège **tenu** par ce jeton (`holdingSeat()`) passe à la
     *    nouvelle `player.locale` — une diffusion Reverb n'ayant ni requête ni
     *    cookie, c'est la colonne qui dit au serveur dans quelle langue composer
     *    un envoi ciblé. Ne sont pas touchés : un siège parti (`left`), dont la
     *    reprise réaligne la colonne (§ 2.2), un siège expulsé (`left` lui
     *    aussi, jamais repris), un siège archivé, qui n'a plus de hash.
     *    L'écriture passe **par le constructeur de requêtes Eloquent**,
     *    jamais `DB::table()` : `updated_at` y garde ses millisecondes
     *    (10 § 1.2).
     *
     * **Sans jeton, aucune frappe** (I4.1) : changer de langue n'est pas un
     * geste de jeu, aucun identifiant n'est posé. Le geste est idempotent.
     *
     * **Elle ne touche aucun état de jeu** : aucune manche ne redémarre,
     * aucun chrono ne se resynchronise, aucune tentative envoyée n'est
     * invalidée, aucun score n'est recalculé, et **aucun QCM n'est recomposé** —
     * les quatre chaînes déjà composées sont rejouées dans leur langue de
     * composition (`05`, contrat C11). Le front enchaîne sur un
     * `router.reload({ only: ['locale', 'translations'], preserveState: true })`
     * pour que le composant de page ne soit pas remonté — ni la souscription
     * Echo, ni l'état de manche, ni le chronomètre client ne sont touchés.
     */
    public function update(LocaleUpdateRequest $request, PlayerTokenManager $tokens): RedirectResponse
    {
        $locale = $request->locale();
        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['locale' => $locale])->save();
        }

        $token = $tokens->current($request);

        if ($token instanceof PlayerToken) {
            $tokens->resign($request, $token->withLocale($locale));

            Player::query()
                ->heldByToken($token)
                ->holdingSeat()
                ->update(['locale' => $locale->value]);
        }

        LocaleCookie::queue($locale);

        return back();
    }
}
