<?php

namespace Database\Factories;

use App\Enums\ConsentKind;
use App\Models\User;
use App\Models\UserConsent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un consentement daté, en AJOUT SEUL (§ 5.4).
 *
 * `users.terms_accepted_at`, `users.terms_version` et `users.age_confirmed_at` ne sont
 * que des PROJECTIONS de la dernière ligne, pour la garde d'affichage à la connexion :
 * l'historique est ici, et c'est lui qui survit à l'anonymisation. Une fixture qui ne
 * poserait que les projections prouverait la mauvaise version au premier changement de
 * CGU.
 *
 * La table n'a pas d'`updated_at` — `const UPDATED_AT = null` sur le modèle. Rien à
 * faire ici, sinon ne jamais écrire cette colonne.
 *
 * @extends Factory<UserConsent>
 */
class UserConsentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => ConsentKind::Terms,
            'version' => '1.0',
            'accepted_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * Acceptation des CGU.
     */
    public function terms(): static
    {
        return $this->state(['kind' => ConsentKind::Terms]);
    }

    /**
     * Déclaration d'âge minimum — un fait DISTINCT de l'acceptation des CGU, et c'est
     * pourquoi `kind` existe plutôt que deux colonnes.
     */
    public function age(): static
    {
        return $this->state(['kind' => ConsentKind::Age]);
    }

    /**
     * La version acceptée — `user_consent_user_kind_version_uq` interdit deux fois la
     * même paire (nature, version) pour un compte.
     */
    public function version(string $version): static
    {
        return $this->state(['version' => $version]);
    }

    public function acceptedAt(DateTimeInterface $acceptedAt): static
    {
        return $this->state(['accepted_at' => $acceptedAt]);
    }
}
