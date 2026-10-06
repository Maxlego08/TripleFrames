<?php

namespace App\Enums;

/**
 * L'intention d'un aller-retour chez un fournisseur (spec 40 § 12.2), posée en
 * session par `oauth.redirect` et relue par `oauth.callback` : se connecter
 * (ou s'inscrire), lier un fournisseur au compte connecté, ou confirmer
 * l'identité d'un compte sans mot de passe.
 */
enum OAuthIntent: string
{
    case Login = 'login';

    case Link = 'link';

    case Confirm = 'confirm';

    /** Les deux intentions qui exigent un compte connecté. */
    public function requiresAccount(): bool
    {
        return $this !== self::Login;
    }
}
