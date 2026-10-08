<?php

namespace App\Actions\Account;

use App\Enums\ConsentKind;
use App\Models\User;
use App\Models\UserConsent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Le SEUL écrivain des consentements d'un compte — spec 40 § 13.1 (L40-10,
 * D66 du 07/10), 10 § 5.4.
 *
 * Une ligne `user_consent` par nature, à la version courante des CGU
 * (`config('legal.terms_version')`), en ajout seul : l'unicité
 * `(user_id, kind, version)` est respectée en relisant la ligne avant
 * d'écrire, sous le verrou de la ligne `users` du compte, et une ligne déjà présente garde sa date, la plus ancienne
 * preuve. Puis les PROJECTIONS de `users` — `terms_accepted_at` et
 * `terms_version` pour `terms`, `age_confirmed_at` pour `age` —, posées sur
 * la date de la ligne retenue. Les natures `provider_<p>` n'ont aucune
 * projection.
 *
 * Écrit dans la transaction de l'appelant (création Fortify, création OAuth,
 * liaison d'un fournisseur, ré-acceptation), ou dans la sienne. Aucun rôle,
 * aucune autre colonne : un consentement n'ouvre jamais un droit.
 */
final class RecordConsents
{
    /**
     * @param  list<ConsentKind>  $kinds
     */
    public function handle(User $user, array $kinds): void
    {
        DB::transaction(function () use ($user, $kinds): void {
            // Verrou du compte AVANT toute lecture : deux acceptations
            // simultanées (deux onglets, double envoi) se sérialisent ici, et
            // l'instantané InnoDB de la seconde est pris après la validation
            // de la première — sa relecture de `record()` voit donc la ligne
            // et garde sa date, au lieu de buter sur l'unicité (500).
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $version = Config::string('legal.terms_version');
            $now = CarbonImmutable::now();

            foreach ($kinds as $kind) {
                $acceptedAt = $this->record($user, $kind, $version, $now);

                match ($kind) {
                    ConsentKind::Terms => $user->forceFill([
                        'terms_accepted_at' => $acceptedAt,
                        'terms_version' => $version,
                    ]),
                    ConsentKind::Age => $user->forceFill(['age_confirmed_at' => $acceptedAt]),
                    ConsentKind::ProviderDiscord, ConsentKind::ProviderGoogle => null,
                };
            }

            if ($user->isDirty()) {
                $user->save();
            }
        });
    }

    /**
     * La ligne de la nature à la version, écrite si elle manque ; rend la date
     * de la ligne retenue.
     */
    private function record(User $user, ConsentKind $kind, string $version, CarbonImmutable $now): CarbonImmutable
    {
        $existing = UserConsent::query()
            ->where('user_id', $user->id)
            ->where('kind', $kind->value)
            ->where('version', $version)
            ->first();

        if ($existing !== null) {
            return $existing->accepted_at;
        }

        $consent = new UserConsent;
        $consent->forceFill([
            'user_id' => $user->id,
            'kind' => $kind,
            'version' => $version,
            'accepted_at' => $now,
        ])->save();

        return $now;
    }
}
