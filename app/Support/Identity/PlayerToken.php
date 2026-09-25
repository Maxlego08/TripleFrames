<?php

namespace App\Support\Identity;

use App\Avatars\AvatarPresetCatalog;
use App\Enums\Locale;
use InvalidArgumentException;

/**
 * Le `player_token` — contrat C4 (spec 40 § 3), charge utile du cookie du même nom.
 *
 * Quatre revendications, et rien d'autre (§ 3.3) : `v` (version), `tid`
 * (identifiant opaque), `locale` (langue de la dernière prise de siège ou du
 * dernier changement de langue) et `avatar` (dernier prédéfini choisi). **Le
 * pseudo n'y est jamais** : le jeton vit 30 jours, les identifiants d'invité
 * disparaissent 24 h après la dernière activité du salon (§ 2.3). Ni siège, ni
 * salon, ni consentement, ni compte.
 *
 * **Le `tid` est l'identité du siège** : `player.player_token_hash` en est le
 * SHA-256 ({@see self::hash()}), jamais celui de la valeur chiffrée du cookie,
 * qui change à chaque re-signature (I4.3). Tiré au CSPRNG sur 256 bits, jamais
 * un ULID ni un horodatage : un identifiant trié dans le temps rendrait deux
 * jetons frappés à la même seconde corrélables (§ 3.3). **Aucun chemin ne le
 * change** : {@see self::withLocale()} et {@see self::withAvatar()} le
 * conservent (I4.4). Il ne quitte jamais le serveur (§ 3.12) : privé, masqué
 * des traces de pile et de {@see self::__debugInfo()}.
 *
 * **Au décodage** ({@see self::fromClaims()}), une revendication de mauvais
 * type, un `v` inconnu ou un `tid` hors motif rendent le jeton ABSENT ; une
 * `locale` ou un `avatar` de bon type mais inconnus deviennent `null` et le
 * jeton reste valide — retirer une langue ou changer de pack d'avatars ne
 * détruit aucun siège (§ 3.3).
 */
final readonly class PlayerToken
{
    /** Version de la charge utile. Un changement de charge l'incrémente, sans toucher aucune colonne (§ 3.7). */
    public const int VERSION = 1;

    /** Octets d'aléa du `tid` : `bin2hex(random_bytes(32))`, 64 hexadécimaux minuscules. */
    public const int TID_BYTES = 32;

    /** Motif du `tid`, à l'octet près. */
    public const string TID_PATTERN = '/^[0-9a-f]{64}$/';

    private function __construct(
        #[\SensitiveParameter] private string $tid,
        public ?Locale $locale,
        public ?string $avatar,
    ) {}

    /**
     * Frappe un jeton neuf. Seul {@see PlayerTokenManager::ensure()} l'appelle
     * dans une requête (I4.1).
     *
     * @throws InvalidArgumentException avatar hors {@see AvatarPresetCatalog}.
     */
    public static function mint(Locale $locale, ?string $avatar = null): self
    {
        return new self(bin2hex(random_bytes(self::TID_BYTES)), $locale, self::assertAvatar($avatar));
    }

    /**
     * Relit une charge décodée. `null` — jeton absent — si `v` n'est pas
     * {@see self::VERSION}, si `tid` sort de {@see self::TID_PATTERN} ou si une
     * revendication est d'un mauvais type. Une revendication facultative absente
     * vaut `null`.
     *
     * @param  array<array-key, mixed>  $claims
     */
    public static function fromClaims(array $claims): ?self
    {
        $version = $claims['v'] ?? null;
        $tid = $claims['tid'] ?? null;
        $locale = $claims['locale'] ?? null;
        $avatar = $claims['avatar'] ?? null;

        if ($version !== self::VERSION || ! is_string($tid) || preg_match(self::TID_PATTERN, $tid) !== 1) {
            return null;
        }

        if (($locale !== null && ! is_string($locale)) || ($avatar !== null && ! is_string($avatar))) {
            return null;
        }

        return new self(
            $tid,
            $locale === null ? null : Locale::tryFrom($locale),
            $avatar !== null && AvatarPresetCatalog::has($avatar) ? $avatar : null,
        );
    }

    /**
     * La charge en clair, avant chiffrement par `EncryptCookies`.
     *
     * @return array{v: int, tid: string, locale: string|null, avatar: string|null}
     */
    public function toClaims(): array
    {
        return [
            'v' => self::VERSION,
            'tid' => $this->tid,
            'locale' => $this->locale?->value,
            'avatar' => $this->avatar,
        ];
    }

    /** Même `tid`, autre revendication de langue (§ 4.2, I4.5). */
    public function withLocale(Locale $locale): self
    {
        return new self($this->tid, $locale, $this->avatar);
    }

    /**
     * Même `tid`, autre revendication d'avatar (I4.5). `null` efface la
     * revendication.
     *
     * @throws InvalidArgumentException avatar hors {@see AvatarPresetCatalog}.
     */
    public function withAvatar(?string $avatar): self
    {
        return new self($this->tid, $this->locale, self::assertAvatar($avatar));
    }

    /** `hash('sha256', tid)`, sur la chaîne hexadécimale ASCII : la valeur de `player.player_token_hash` (I4.3). */
    public function hash(): string
    {
        return hash('sha256', $this->tid);
    }

    /** Vrai si les deux jetons portent le même `tid`, comparé en temps constant. */
    public function sameIdentityAs(self $other): bool
    {
        return hash_equals($this->tid, $other->tid);
    }

    /**
     * Ce qu'un `dump()` ou une trace montre : jamais le `tid` (§ 3.12).
     *
     * @return array{tid: string, locale: string|null, avatar: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'tid' => '[redacted]',
            'locale' => $this->locale?->value,
            'avatar' => $this->avatar,
        ];
    }

    private static function assertAvatar(?string $avatar): ?string
    {
        if ($avatar !== null && ! AvatarPresetCatalog::has($avatar)) {
            throw new InvalidArgumentException(sprintf('Avatar prédéfini inconnu : [%s].', $avatar));
        }

        return $avatar;
    }
}
