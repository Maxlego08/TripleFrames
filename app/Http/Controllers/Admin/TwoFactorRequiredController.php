<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'écran d'enrôlement du second facteur — `admin.two_factor.required`
 * (spec 20 § 2.4).
 *
 * C'est là que `admin.2fa` renvoie un compte privilégié dont le second facteur
 * n'est pas confirmé. La route vit dans le groupe `/admin` — `auth`,
 * `verified`, `role:curator` et `admin.locale` la gardent comme toutes les
 * autres, et un joueur y reçoit donc 403 — mais elle est **retirée
 * d'`admin.2fa`** par `withoutMiddleware`, sans quoi la porte se renverrait à
 * elle-même.
 *
 * Elle n'écrit rien et ne décide rien : l'enrôlement se fait sur la page de
 * sécurité du compte, par Fortify, avec confirmation. La page explique pourquoi
 * la porte est fermée et y mène par un lien Wayfinder.
 *
 * Un compte déjà confirmé qui y arrive reçoit la page, dans son état
 * « porte ouverte », et non une redirection : l'écran est l'une des deux
 * routes du groupe sans `can:` (§ 2.3), et la matrice attend 200 pour tout
 * compte privilégié passé la porte (§ 2.2, ligne 9).
 */
class TwoFactorRequiredController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('admin/two-factor-required', [
            'two_factor_confirmed' => $user instanceof User && $user->two_factor_confirmed_at !== null,
        ]);
    }
}
