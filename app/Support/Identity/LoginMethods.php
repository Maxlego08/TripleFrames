<?php

namespace App\Support\Identity;

use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\User;

/**
 * Les méthodes de connexion d'un compte — spec 40 § 12.5 et § 13.8 (L40-15,
 * D66 du 07/10).
 *
 * Une seule aide pour les deux gestes qui peuvent retirer la dernière porte
 * d'un compte : délier un fournisseur (`UnlinkProvider`) et supprimer une
 * passkey (`DeletePasskeyUnlessLastMethod`). Trois natures comptent : le mot
 * de passe, chaque fournisseur lié, chaque passkey. Le second facteur TOTP
 * n'en est pas une : il ne connecte personne seul.
 *
 * L'appelant lit sous le verrou du compte (`lockForUpdate`), pour que deux
 * suppressions concurrentes ne retirent pas chacune « l'avant-dernière ».
 */
final class LoginMethods
{
    /**
     * Le nombre de méthodes qui RESTERAIENT si l'on retirait le fournisseur
     * ou la passkey désignés.
     */
    public static function remainingWithout(
        User $user,
        ?OAuthProvider $provider = null,
        ?int $passkeyId = null,
    ): int {
        $providers = LinkedAccount::query()
            ->where('user_id', $user->id)
            ->when($provider !== null, static fn ($query) => $query->where('provider', '!=', $provider?->value))
            ->count();

        $passkeys = $user->passkeys()
            ->when($passkeyId !== null, static fn ($query) => $query->whereKeyNot($passkeyId))
            ->count();

        return ($user->password !== null ? 1 : 0) + $providers + $passkeys;
    }

    /** Vrai si retirer ce fournisseur ou cette passkey ne laisserait aucune méthode. */
    public static function wouldRemoveLast(
        User $user,
        ?OAuthProvider $provider = null,
        ?int $passkeyId = null,
    ): bool {
        return self::remainingWithout($user, $provider, $passkeyId) === 0;
    }
}
