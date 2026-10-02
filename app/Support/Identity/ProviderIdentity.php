<?php

namespace App\Support\Identity;

use App\Enums\OAuthProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Ce que le serveur retient d'un retour de fournisseur — spec 40 § 12.2.
 *
 * L'adresse n'est tenue pour VÉRIFIÉE que si le fournisseur le déclare
 * (Google `email_verified`, Discord `verified`) : c'est la seule condition
 * d'une liaison automatique. Le pseudo n'est qu'une SUGGESTION, jamais
 * appliquée sans validation. Sérialisable en session pour l'inscription en
 * attente (§ 12.3), sans aucun jeton du fournisseur.
 */
final readonly class ProviderIdentity
{
    public function __construct(
        public OAuthProvider $provider,
        public string $id,
        public ?string $email,
        public bool $emailVerified,
        public ?string $suggestedName,
        public ?string $avatarUrl,
    ) {}

    public static function fromSocialite(OAuthProvider $provider, SocialiteUser $user): self
    {
        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];
        $email = $user->getEmail();
        $email = is_string($email) && $email !== '' ? mb_strtolower(trim($email)) : null;

        $verified = match ($provider) {
            OAuthProvider::Google => ($raw['email_verified'] ?? $raw['verified_email'] ?? false) === true,
            OAuthProvider::Discord => ($raw['verified'] ?? false) === true,
        };

        $name = match ($provider) {
            OAuthProvider::Google => $raw['given_name'] ?? $user->getName(),
            OAuthProvider::Discord => $raw['global_name'] ?? $raw['username'] ?? $user->getName(),
        };

        $avatar = $user->getAvatar();

        return new self(
            provider: $provider,
            id: (string) $user->getId(),
            email: $email,
            emailVerified: $email !== null && $verified,
            suggestedName: is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 50) : null,
            avatarUrl: is_string($avatar) && $avatar !== '' ? $avatar : null,
        );
    }

    /**
     * @return array{provider: string, id: string, email: string|null, email_verified: bool, suggested_name: string|null, avatar_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'id' => $this->id,
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'suggested_name' => $this->suggestedName,
            'avatar_url' => $this->avatarUrl,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $provider = OAuthProvider::tryFrom((string) ($data['provider'] ?? ''));
        $id = $data['id'] ?? null;

        if ($provider === null || ! is_string($id) || $id === '') {
            return null;
        }

        $email = $data['email'] ?? null;
        $name = $data['suggested_name'] ?? null;
        $avatar = $data['avatar_url'] ?? null;

        return new self(
            provider: $provider,
            id: $id,
            email: is_string($email) ? $email : null,
            emailVerified: ($data['email_verified'] ?? false) === true,
            suggestedName: is_string($name) ? $name : null,
            avatarUrl: is_string($avatar) ? $avatar : null,
        );
    }
}
