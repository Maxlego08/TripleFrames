<?php

namespace App\Actions\Account;

use App\Enums\ConsentKind;
use App\Jobs\Account\CopyProviderAvatar;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Models\UserConsent;
use App\Support\Identity\ProviderIdentity;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Rattache un compte fournisseur à un compte — spec 40 § 12.2 et § 12.6,
 * D51 du 01/10.
 *
 * Écrit, dans la transaction de l'appelant (ou la sienne) : la ligne
 * `linked_account`, le consentement daté `provider_<p>` à la version courante
 * des CGU, et — à la PREMIÈRE liaison seulement, sans copie ni masquage
 * existants — l'URL de la photo, que le job {@see CopyProviderAvatar} lit
 * après la validation puis efface. Les refus (lié ailleurs, fournisseur déjà
 * lié) sont décidés par l'appelant, sous verrou.
 */
final class LinkProvider
{
    public function handle(User $user, ProviderIdentity $identity): LinkedAccount
    {
        return DB::transaction(function () use ($user, $identity): LinkedAccount {
            $now = Date::now();
            $copyPhoto = $user->avatar_provider_path === null
                && $user->avatar_provider_hidden_at === null
                && $identity->avatarUrl !== null;

            $linked = new LinkedAccount;
            $linked->forceFill([
                'user_id' => $user->id,
                'provider' => $identity->provider,
                'provider_user_id' => $identity->id,
                'provider_email' => $identity->email,
                'provider_email_verified' => $identity->emailVerified,
                'suggested_nickname' => $identity->suggestedName,
                'provider_avatar_url' => $copyPhoto ? $identity->avatarUrl : null,
            ])->save();

            $kind = ConsentKind::forProvider($identity->provider);
            $version = Config::string('legal.terms_version');

            // Une ligne par (compte, nature, version) : une seconde liaison à la
            // même version garde la première preuve, la plus ancienne.
            $consented = UserConsent::query()
                ->where('user_id', $user->id)
                ->where('kind', $kind->value)
                ->where('version', $version)
                ->exists();

            if (! $consented) {
                $consent = new UserConsent;
                $consent->forceFill([
                    'user_id' => $user->id,
                    'kind' => $kind,
                    'version' => $version,
                    'accepted_at' => $now,
                ])->save();
            }

            if ($copyPhoto) {
                CopyProviderAvatar::dispatch($linked->id)->afterCommit();
            }

            return $linked;
        });
    }
}
