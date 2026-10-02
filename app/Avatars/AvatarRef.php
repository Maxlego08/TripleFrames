<?php

namespace App\Avatars;

use App\Enums\AvatarKind;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * Résolution d'un avatar — l'objet rendu par l'accesseur serveur unique
 * {@see User::avatarRef()} (§ 5.3).
 *
 * Trois natures depuis D49 du 01/10 — l'image téléversée d'un compte
 * ({@see self::upload()}, spec 40 § 11) s'ajoute aux deux ci-dessous —, et un
 * repli qui n'est PAS un cas d'enum :
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
     * Clés de traduction de l'attribut `alt`, une par branche de la chaîne.
     *
     * Domaine `common` (spec 40 § 6.5) : il n'existe aucun domaine `avatar`
     * (05, C15 § 2.2), et `common` est embarqué par toute page, écran de jeu
     * compris, alors que `account` ne l'est pas.
     */
    public const string ALT_KEY_PRESET = 'common.avatar.alt.preset';

    public const string ALT_KEY_PROVIDER = 'common.avatar.alt.provider';

    public const string ALT_KEY_INITIALS = 'common.avatar.alt.initials';

    /** Image téléversée par un compte (D49 du 01/10, spec 40 § 11.1). */
    public const string ALT_KEY_UPLOAD = 'common.avatar.alt.upload';

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
        return new self(
            kind: AvatarKind::Preset,
            url: self::presetUrl($preset),
            altKey: self::ALT_KEY_PRESET,
            initials: $initials,
        );
    }

    /**
     * URL publique et cacheable du fichier statique d'un prédéfini, depuis sa
     * CLÉ (spec 40 § 6.5) : seule construction de cette URL, partagée par
     * {@see self::preset()} et {@see AvatarPresetCatalog::options()}.
     */
    public static function presetUrl(string $key): string
    {
        $base = rtrim(Config::string(self::CONFIG_PREFIX.'preset_base', self::DEFAULT_PRESET_BASE), '/');
        $extension = Config::string(self::CONFIG_PREFIX.'preset_extension', self::DEFAULT_PRESET_EXTENSION);

        return $base.'/'.$key.'.'.$extension;
    }

    /**
     * Copie locale de la photo Discord/Google : chemin RELATIF sur le disque
     * `avatars`, servi par la route `avatar.show` comme l'image téléversée
     * (spec 40 § 12.6, D51 du 01/10) — jamais par `storage:link`.
     */
    public static function provider(string $path, string $initials): self
    {
        return new self(
            kind: AvatarKind::Provider,
            url: UploadedAvatars::url($path),
            altKey: self::ALT_KEY_PROVIDER,
            initials: $initials,
        );
    }

    /**
     * Image téléversée d'un compte : chemin RELATIF sur le disque `avatars`,
     * servi par la route `avatar.show` — jamais par `storage:link` (spec 40
     * § 11.3). L'appelant a vérifié qu'elle est visible.
     */
    public static function upload(string $path, string $initials): self
    {
        return new self(
            kind: AvatarKind::Upload,
            url: UploadedAvatars::url($path),
            altKey: self::ALT_KEY_UPLOAD,
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
