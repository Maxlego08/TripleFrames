<?php

namespace App\Http\Controllers\Locale;

use App\Http\Controllers\Controller;
use App\Http\Requests\Locale\LocaleUpdateRequest;
use App\Models\User;
use App\Support\I18n\LocaleCookie;
use Illuminate\Http\RedirectResponse;

class LocaleController extends Controller
{
    /**
     * Persiste la langue choisie : `users.locale` si le joueur est connecté,
     * cookie dans tous les cas, puis `back()`.
     *
     * **Elle ne touche aucun état de jeu** : aucune manche ne redémarre,
     * aucun chrono ne se resynchronise, aucune tentative envoyée n'est
     * invalidée, aucun score n'est recalculé. Le front enchaîne sur un
     * `router.reload({ only: ['locale', 'translations'], preserveState: true })`
     * pour que le composant de page ne soit pas remonté — ni la souscription
     * Echo, ni l'état de manche, ni le chronomètre client ne sont touchés.
     *
     * Reste à brancher quand le `player_token` sera frappé (specs 10 et 40) :
     * re-signer le jeton **sans changer le siège qu'il porte**, et mettre à
     * jour `player.locale` sur tous les sièges actifs de ce jeton — une
     * diffusion Reverb n'ayant ni requête ni cookie, c'est la colonne qui dit
     * au serveur dans quelle langue composer un envoi ciblé.
     */
    public function update(LocaleUpdateRequest $request): RedirectResponse
    {
        $locale = $request->locale();
        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['locale' => $locale])->save();
        }

        LocaleCookie::queue($locale);

        return back();
    }
}
