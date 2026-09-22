<?php

namespace Database\Factories;

use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un compte fournisseur lié (§ 5.4).
 *
 * `provider_email_verified` est la trace SANS laquelle une liaison automatique ne peut
 * plus être justifiée après coup : la liaison n'est permise que sur un e-mail déclaré
 * vérifié par le fournisseur. {@see self::unverified()} est donc le cas qui doit rester
 * facile à écrire dans un test.
 *
 * `suggested_nickname` est une suggestion À VALIDER, jamais une valeur appliquée —
 * d'où une colonne distincte de `users.name`.
 *
 * `provider_avatar_url` est lue par le SEUL job de téléchargement différé et n'est
 * jamais servie au client : pas de hotlink, l'URL est instable et ferait fuiter l'IP
 * du joueur vers un tiers en pleine partie. Elle est `#[Hidden]`, comme
 * `provider_user_id` et `provider_email`.
 *
 * Aucune adresse IP, aucun jeton d'accès ni de rafraîchissement : la v1 ne rappelle
 * jamais l'API du fournisseur après le callback.
 *
 * @extends Factory<LinkedAccount>
 */
class LinkedAccountFactory extends Factory
{
    /**
     * Compteur d'identifiants fournisseur : `linked_account_provider_uid_uq` est un
     * UNIQUE sur `(provider, provider_user_id)`.
     */
    private static int $providerUserSequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = ++self::$providerUserSequence;

        return [
            'user_id' => User::factory(),
            'provider' => OAuthProvider::Discord,
            // Un identifiant de fournisseur est une chaîne opaque, jamais un entier :
            // les « snowflakes » Discord dépassent déjà la plage des entiers signés
            // de certains clients.
            'provider_user_id' => (string) (100_000_000_000_000_000 + $sequence),
            'provider_email' => fake()->unique()->safeEmail(),
            'provider_email_verified' => true,
            'suggested_nickname' => fake()->userName(),
            'provider_avatar_url' => 'https://cdn.example.test/avatars/'.fake()->uuid().'.png',
        ];
    }

    public function discord(): static
    {
        return $this->state(['provider' => OAuthProvider::Discord]);
    }

    public function google(): static
    {
        return $this->state(['provider' => OAuthProvider::Google]);
    }

    public function verified(): static
    {
        return $this->state(['provider_email_verified' => true]);
    }

    /**
     * E-mail non vérifié par le fournisseur : la liaison automatique est INTERDITE.
     */
    public function unverified(): static
    {
        return $this->state(['provider_email_verified' => false]);
    }

    /**
     * Le cas Discord réel : aucun e-mail retourné. C'est lui qui a rendu `users.email`
     * nullable, et c'est lui qui doit rester testable.
     */
    public function withoutEmail(): static
    {
        return $this->state([
            'provider_email' => null,
            'provider_email_verified' => false,
        ]);
    }

    public function withoutAvatar(): static
    {
        return $this->state(['provider_avatar_url' => null]);
    }

    /**
     * L'identifiant exact du fournisseur — le chemin chaud du callback OAuth
     * (`WHERE provider = ? AND provider_user_id = ?`).
     */
    public function providerUser(string $providerUserId): static
    {
        return $this->state(['provider_user_id' => $providerUserId]);
    }
}
