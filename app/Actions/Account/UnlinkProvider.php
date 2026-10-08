<?php

namespace App\Actions\Account;

use App\Avatars\AccountImage;
use App\Avatars\UploadedAvatars;
use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Support\Identity\LoginMethods;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * Délie un fournisseur — spec 40 § 12.5, D51 du 01/10.
 *
 * La route porte `password.confirm` (confirmation fraîche). Ici, sous le
 * verrou du compte : un CODE de second facteur si la 2FA est confirmée
 * (TOTP ou code de récupération, ce dernier consommé) ; refus si la
 * déliaison retire la DERNIÈRE méthode de connexion — mot de passe, autre
 * fournisseur, passkey. La ligne `linked_account` est SUPPRIMÉE (identifiant
 * et adresse du fournisseur disparaissent) ; les consentements restent.
 * Délier le fournisseur d'origine de la photo supprime le fichier, après le
 * commit, et vide sa provenance ; un masquage survit.
 */
final class UnlinkProvider
{
    /**
     * @throws ValidationException Code absent ou faux, dernière méthode, ou
     *                             fournisseur non lié.
     */
    public function handle(User $user, OAuthProvider $provider, ?string $code): void
    {
        $orphan = DB::transaction(function () use ($user, $provider, $code): ?string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $linked = LinkedAccount::query()
                ->where('user_id', $locked->id)
                ->where('provider', $provider->value)
                ->first();

            if ($linked === null) {
                throw self::refusal('provider', 'account.linked.errors.not_linked');
            }

            if ($locked->two_factor_confirmed_at !== null && ! self::validSecondFactor($locked, $code)) {
                throw self::refusal('code', 'account.linked.errors.code');
            }

            // Même aide que la suppression d'une passkey (spec 40 § 13.8).
            if (LoginMethods::wouldRemoveLast($locked, provider: $provider)) {
                throw self::refusal('provider', 'account.oauth.errors.last_method');
            }

            $linked->delete();

            if ($locked->avatar_provider_source !== $provider) {
                return null;
            }

            $path = $locked->avatar_provider_path;

            $locked->forceFill([
                'avatar_provider_path' => null,
                'avatar_provider_source' => null,
                'avatar_kind' => DeleteAvatar::fallbackKind($locked, AccountImage::Provider),
            ])->save();

            return $path;
        });

        UploadedAvatars::deleteQuietly($orphan);

        $user->refresh();
    }

    /**
     * Un code TOTP valide, ou un code de récupération — alors consommé, comme
     * au défi de connexion de Fortify.
     */
    private static function validSecondFactor(User $user, ?string $code): bool
    {
        $code = trim((string) $code);

        if ($code === '' || $user->two_factor_secret === null) {
            return false;
        }

        try {
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
            $valid = is_string($secret) && app(TwoFactorAuthenticationProvider::class)->verify($secret, $code);
        } catch (Throwable) {
            // Un secret illisible ne confirme rien, et ne casse jamais la page.
            $valid = false;
        }

        if ($valid) {
            return true;
        }

        foreach ($user->recoveryCodes() as $recovery) {
            if (hash_equals($recovery, $code)) {
                $user->replaceRecoveryCode($code);

                return true;
            }
        }

        return false;
    }

    private static function refusal(string $field, string $key): ValidationException
    {
        $message = __($key);

        return ValidationException::withMessages([$field => [is_string($message) ? $message : $key]]);
    }
}
