<?php

namespace App\Support\Identity;

use App\Actions\Account\ResolveOAuthCallback;
use App\Models\User;

/**
 * Le verdict de {@see ResolveOAuthCallback} — spec 40
 * § 12.2. `refusal` est le suffixe d'une clé `account.oauth.errors.*`.
 */
final readonly class OAuthOutcome
{
    public const string LOGGED_IN = 'logged_in';

    public const string TWO_FACTOR = 'two_factor';

    public const string LINKED = 'linked';

    public const string CONFIRMED = 'confirmed';

    public const string PENDING = 'pending';

    public const string REFUSED = 'refused';

    private function __construct(
        public string $type,
        public ?User $user = null,
        public ?string $refusal = null,
    ) {}

    public static function loggedIn(User $user): self
    {
        return new self(self::LOGGED_IN, $user);
    }

    public static function twoFactor(User $user): self
    {
        return new self(self::TWO_FACTOR, $user);
    }

    public static function linked(User $user): self
    {
        return new self(self::LINKED, $user);
    }

    public static function confirmed(User $user): self
    {
        return new self(self::CONFIRMED, $user);
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function refused(string $refusal): self
    {
        return new self(self::REFUSED, refusal: $refusal);
    }

    /** La clé de traduction du refus. */
    public function refusalKey(): string
    {
        return 'account.oauth.errors.'.($this->refusal ?? 'failed');
    }
}
