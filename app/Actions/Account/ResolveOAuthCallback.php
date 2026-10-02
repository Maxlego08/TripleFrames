<?php

namespace App\Actions\Account;

use App\Enums\OAuthIntent;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Support\Identity\OAuthOutcome;
use App\Support\Identity\ProviderIdentity;
use Illuminate\Support\Facades\DB;

/**
 * La table de décision du retour d'un fournisseur — spec 40 § 12.2, D51 du
 * 01/10. Une seule lecture de la règle ; le contrôleur traduit le verdict en
 * session et en redirection, sans rien décider.
 *
 * Invariants tenus ici :
 * - un compte à 2FA confirmée n'est jamais CONNECTÉ sans défi (verdict
 *   `TwoFactor`) ni LIÉ automatiquement (refus `two_factor_link`) ;
 * - la liaison automatique exige une adresse déclarée vérifiée par le
 *   fournisseur ET vérifiée sur le compte ; un compte sans adresse n'en est
 *   jamais la cible ;
 * - un compte fournisseur déjà lié ailleurs n'est jamais transféré ni
 *   fusionné ;
 * - aucun compte n'est créé ici : une inscription reste EN ATTENTE jusqu'à
 *   l'écran de finalisation.
 */
final readonly class ResolveOAuthCallback
{
    public function __construct(private LinkProvider $link) {}

    public function handle(ProviderIdentity $identity, OAuthIntent $intent, ?User $current): OAuthOutcome
    {
        return DB::transaction(function () use ($identity, $intent, $current): OAuthOutcome {
            $linked = LinkedAccount::query()
                ->where('provider', $identity->provider->value)
                ->where('provider_user_id', $identity->id)
                ->lockForUpdate()
                ->first();
            $owner = $linked === null ? null : User::query()->find($linked->user_id);

            return match ($intent) {
                OAuthIntent::Login => $this->login($identity, $owner),
                OAuthIntent::Link => $this->linkTo($identity, $owner, $current),
                OAuthIntent::Confirm => $owner !== null && $current !== null && $owner->is($current)
                    ? OAuthOutcome::confirmed($current)
                    : OAuthOutcome::refused('confirm_mismatch'),
            };
        });
    }

    private function login(ProviderIdentity $identity, ?User $owner): OAuthOutcome
    {
        if ($owner !== null) {
            if ($owner->anonymized_at !== null) {
                return OAuthOutcome::refused('failed');
            }

            return $owner->two_factor_confirmed_at !== null
                ? OAuthOutcome::twoFactor($owner)
                : OAuthOutcome::loggedIn($owner);
        }

        $sameEmail = $identity->email === null
            ? null
            : User::query()->where('email', $identity->email)->lockForUpdate()->first();

        if ($sameEmail === null) {
            return OAuthOutcome::pending();
        }

        $autoLinkable = $identity->emailVerified
            && $sameEmail->email_verified_at !== null
            && $sameEmail->anonymized_at === null;

        if (! $autoLinkable) {
            return OAuthOutcome::refused('email_taken');
        }

        if ($sameEmail->two_factor_confirmed_at !== null) {
            return OAuthOutcome::refused('two_factor_link');
        }

        if ($this->hasProvider($sameEmail, $identity)) {
            return OAuthOutcome::refused('provider_taken');
        }

        $this->link->handle($sameEmail, $identity);

        return OAuthOutcome::loggedIn($sameEmail);
    }

    private function linkTo(ProviderIdentity $identity, ?User $owner, ?User $current): OAuthOutcome
    {
        if ($current === null) {
            return OAuthOutcome::refused('failed');
        }

        if ($owner !== null) {
            return $owner->is($current)
                ? OAuthOutcome::linked($current)
                : OAuthOutcome::refused('linked_elsewhere');
        }

        $locked = User::query()->lockForUpdate()->findOrFail($current->id);

        if ($this->hasProvider($locked, $identity)) {
            return OAuthOutcome::refused('provider_taken');
        }

        $this->link->handle($locked, $identity);

        return OAuthOutcome::linked($locked);
    }

    /** Le compte a-t-il déjà un AUTRE identifiant chez ce fournisseur ? */
    private function hasProvider(User $user, ProviderIdentity $identity): bool
    {
        return LinkedAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $identity->provider->value)
            ->exists();
    }
}
