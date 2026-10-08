<?php

namespace App\Actions\Account;

use App\Enums\ConsentKind;
use App\Enums\Locale;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Support\Identity\ProviderIdentity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crée un compte depuis un retour de fournisseur, après l'écran « Finaliser
 * l'inscription » — spec 40 § 12.3, D51 du 01/10.
 *
 * Une transaction : relecture de l'identifiant (déjà lié → son titulaire est
 * rendu, sans rien créer) et de l'adresse (prise entre-temps → refus) ; le
 * compte, sans mot de passe, `email_verified_at` posé si le fournisseur
 * déclare l'adresse vérifiée ; les projections des CGU et de l'âge et leurs
 * deux lignes `user_consent`, par l'écrivain unique {@see RecordConsents}
 * (spec 40 § 13.1) ; puis la liaison ({@see LinkProvider}), qui
 * écrit le consentement du fournisseur et programme la photo. AUCUN rôle :
 * `role` garde son défaut `player`. Après validation, `Registered` part si
 * une adresse reste à vérifier.
 */
final readonly class CreateOAuthAccount
{
    public function __construct(private LinkProvider $link, private RecordConsents $consents) {}

    /**
     * @throws ValidationException L'adresse appartient déjà à un compte.
     */
    public function handle(ProviderIdentity $identity, string $name, Locale $locale): User
    {
        $created = false;

        $user = DB::transaction(function () use ($identity, $name, $locale, &$created): User {
            $existing = LinkedAccount::query()
                ->where('provider', $identity->provider->value)
                ->where('provider_user_id', $identity->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return User::query()->findOrFail($existing->user_id);
            }

            if ($identity->email !== null && User::query()->where('email', $identity->email)->exists()) {
                $message = __('account.oauth.errors.email_taken');

                throw ValidationException::withMessages([
                    'name' => [is_string($message) ? $message : 'account.oauth.errors.email_taken'],
                ]);
            }

            $now = Date::now();

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $identity->email,
                'email_verified_at' => $identity->emailVerified ? $now : null,
                'password' => null,
                'locale' => $locale,
            ])->save();

            $this->consents->handle($user, [ConsentKind::Terms, ConsentKind::Age]);

            $this->link->handle($user, $identity);
            $created = true;

            return $user;
        });

        if ($created && $user->email !== null && $user->email_verified_at === null) {
            event(new Registered($user));
        }

        return $user;
    }
}
