<?php

namespace App\Avatars;

use App\Enums\AvatarKind;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * Résolution d'un avatar — l'objet rendu par l'accesseur serveur unique
 * {@see User::avatarRef()} (§ 5.3).
 *
 * Deux natures seulement en v1, et un repli qui n'est PAS un cas d'enum :
 * `avatar_kind = preset` et `avatar_preset` non nul → fichier statique de `public/` ;
 * sinon `avatar_kind = provider`, `avatar_provider_path` non nul et
 * `avatar_provider_hidden_at` nul → URL publique cacheable de la copie locale sur le
 * disque `avatars` ; sinon les initiales de `users.name`, `kind` valant alors `null`.
 *
 * **Aucune URL distante n'est jamais servie au client** : `linked_account.provider_avatar_url`
 * est la source d'un téléchargement différé, lue par le seul job, jamais rendue — un
 * hotlink fuirait l'IP du joueur vers un tiers en pleine partie.
 *
 * `altKey` est une CLÉ de traduction, jamais une chaîne : l'attribut `alt` est du texte
 * d'interface (règle 4). `initials` est toujours rempli, y compris quand une URL existe :
 * c'est le contenu de repli de l'élément d'image.
 *
 * Aucune dépendance à `ext-intl` (absente) : le repli des initiales passe par `mb_*`.
 */
final readonly class AvatarRef
{
    /** Répertoire statique du pack prédéfini, servi depuis `public/`. */
    public const string DEFAULT_PRESET_BASE = '/avatars';

    /** Extension des fichiers du pack prédéfini. */
    public const string DEFAULT_PRESET_EXTENSION = 'webp';

    /**
     * Base publique du disque `avatars` (driver `local`, visibilité publique, racine
     * `storage/app/public/avatar`, exposé par `storage:link`). Les avatars sont publics
     * et cacheables : ils ne passent JAMAIS par la route d'images de jeu, dont
     * `ServeFile` émet `Cache-Control: no-store`. Aucune frame sur le disque public.
     */
    public const string DEFAULT_PROVIDER_BASE = '/storage/avatar';

    /** Clés de traduction de l'attribut `alt`, une par branche de la chaîne. */
    public const string ALT_KEY_PRESET = 'avatar.alt.preset';

    public const string ALT_KEY_PROVIDER = 'avatar.alt.provider';

    public const string ALT_KEY_INITIALS = 'avatar.alt.initials';

    /** Initiale de repli d'un nom vide ou non alphabétique. */
    public const string FALLBACK_INITIAL = '?';

    /** Préfixe de configuration sous lequel chaque base est surchargeable. */
    private const string CONFIG_PREFIX = 'avatars.';

    /**
     * @param  AvatarKind|null  $kind  Nature effective ; `null` = repli initiales.
     * @param  string|null  $url  URL servable, `null` en repli initiales.
     * @param  string  $altKey  Clé de traduction de l'attribut `alt`.
     * @param  string  $initials  Initiales de `users.name`, toujours renseignées.
     */
    private function __construct(
        public ?AvatarKind $kind,
        public ?string $url,
        public string $altKey,
        public string $initials,
    ) {}

    /**
     * Avatar prédéfini : `avatar_preset` est une CLÉ stable, jamais un chemin de
     * fichier — remplacer le pack de 24 fichiers doit être une migration de valeurs.
     */
    public static function preset(string $preset, string $initials): self
    {
        $base = rtrim(Config::string(self::CONFIG_PREFIX.'preset_base', self::DEFAULT_PRESET_BASE), '/');
        $extension = Config::string(self::CONFIG_PREFIX.'preset_extension', self::DEFAULT_PRESET_EXTENSION);

        return new self(
            kind: AvatarKind::Preset,
            url: $base.'/'.$preset.'.'.$extension,
            altKey: self::ALT_KEY_PRESET,
            initials: $initials,
        );
    }

    /**
     * Copie locale de la photo Discord/Google : chemin RELATIF en base, nommé par ULID.
     */
    public static function provider(string $path, string $initials): self
    {
        $base = rtrim(Config::string(self::CONFIG_PREFIX.'provider_base', self::DEFAULT_PROVIDER_BASE), '/');

        return new self(
            kind: AvatarKind::Provider,
            url: $base.'/'.ltrim($path, '/'),
            altKey: self::ALT_KEY_PROVIDER,
            initials: $initials,
        );
    }

    /**
     * Repli terminal : aucune image, `kind` nul, les initiales seules.
     */
    public static function initials(string $initials): self
    {
        return new self(
            kind: null,
            url: null,
            altKey: self::ALT_KEY_INITIALS,
            initials: $initials,
        );
    }

    /**
     * Initiales d'un nom, sans `ext-intl` : première lettre du premier mot, première
     * lettre du dernier quand il y en a plusieurs.
     */
    public static function initialsFrom(?string $name): string
    {
        $words = preg_split('/[\s\-_]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return self::FALLBACK_INITIAL;
        }

        $initials = mb_substr($words[0], 0, 1);

        if (count($words) > 1) {
            $initials .= mb_substr($words[count($words) - 1], 0, 1);
        }

        return mb_strtoupper($initials);
    }

    /**
     * Représentation en DONNÉES, pour une prop Inertia : jamais une chaîne
     * pré-formatée côté serveur (règle 4).
     *
     * @return array{kind: string|null, url: string|null, altKey: string, initials: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind?->value,
            'url' => $this->url,
            'altKey' => $this->altKey,
            'initials' => $this->initials,
        ];
    }
}
