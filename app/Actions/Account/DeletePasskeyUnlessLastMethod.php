<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Support\Identity\LoginMethods;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Passkey;

/**
 * La suppression d'une passkey — spec 40 § 13.8 (L40-15, D66 du 07/10).
 *
 * Remplace l'action du paquet dans le conteneur (`FortifyServiceProvider`) :
 * la route `passkey.destroy` et son contrôleur restent ceux de Fortify, qui
 * vérifient déjà la propriété de la passkey. Ici, sous le verrou du compte,
 * la suppression est refusée (`account.passkeys.errors.last_method`) si elle
 * retirerait la DERNIÈRE méthode de connexion — mot de passe, fournisseur
 * lié, autre passkey —, par la même aide que la déliaison
 * ({@see LoginMethods}). Un refus traduit, jamais un 403.
 */
final class DeletePasskeyUnlessLastMethod extends DeletePasskey
{
    /**
     * @throws ValidationException Dernière méthode de connexion.
     */
    public function __invoke(Authenticatable $user, Passkey $passkey): void
    {
        DB::transaction(function () use ($user, $passkey): void {
            $locked = User::query()->whereKey($user->getAuthIdentifier())->lockForUpdate()->firstOrFail();

            if (LoginMethods::wouldRemoveLast($locked, passkeyId: (int) $passkey->getKey())) {
                $message = __('account.passkeys.errors.last_method');

                throw ValidationException::withMessages([
                    'passkey' => [is_string($message) ? $message : 'account.passkeys.errors.last_method'],
                ]);
            }

            $passkey->delete();
        });

        PasskeyDeleted::dispatch($user, $passkey);
    }
}
