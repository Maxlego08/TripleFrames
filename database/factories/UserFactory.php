<?php

namespace Database\Factories;

use App\Avatars\AvatarPresetCatalog;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;
use RuntimeException;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            // Un compte de fabrique a accepté la version COURANTE des CGU, comme
            // tout compte créé par l'application (spec 40 § 13.1) : sans quoi
            // `terms.current` renverrait chaque page de compte vers
            // l'interstitiel. Projections seules — voir {@see self::consented()}.
            'terms_accepted_at' => now(),
            'terms_version' => Config::string('legal.terms_version'),
            'age_confirmed_at' => now(),
        ];
    }

    /**
     * Aucun consentement : le cas du premier administrateur, créé en console
     * (spec 40 § 13.1). `terms.current` le renvoie vers l'interstitiel, qui
     * lui demande aussi son âge.
     */
    public function withoutConsent(): static
    {
        return $this->state([
            'terms_accepted_at' => null,
            'terms_version' => null,
            'age_confirmed_at' => null,
        ]);
    }

    /**
     * CGU acceptées dans une version périmée : la ré-acceptation l'attend.
     */
    public function outdatedTerms(string $version = 'ancienne-1'): static
    {
        return $this->consented($version);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     *
     * Second facteur CONFIRMÉ : c'est `two_factor_confirmed_at`, et lui seul, que
     * lit la garde `admin.2fa` (spec 20 § 2.4). Posé d'office sur tout rôle
     * ≥ `curator` par {@see self::role()}.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => self::confirmedTwoFactor($attributes));
    }

    /**
     * Aucun second facteur : ni secret, ni codes de secours, ni confirmation.
     *
     * C'est l'état qui ferme la porte `/admin` à un compte privilégié (spec 20
     * § 2.4). **À enchaîner APRÈS le rôle** — `curator()->withoutTwoFactor()` :
     * les états s'appliquent dans l'ordre, et {@see self::role()} pose le
     * second facteur d'un rôle privilégié. Un secret posé sans confirmation
     * s'écrit `withoutTwoFactor()->state(['two_factor_secret' => …])`.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    /**
     * Les trois colonnes Fortify d'un second facteur confirmé ; une valeur déjà
     * posée par un état antérieur ou passée à `create()` est conservée.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function confirmedTwoFactor(array $attributes): array
    {
        return [
            'two_factor_secret' => $attributes['two_factor_secret'] ?? encrypt('secret'),
            'two_factor_recovery_codes' => $attributes['two_factor_recovery_codes'] ?? encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => $attributes['two_factor_confirmed_at'] ?? now(),
        ];
    }

    /**
     * Le rôle — colonne d'AUTORITÉ, hors `#[Fillable]` : une élévation de privilège par
     * requête est précisément ce que la liste d'assignation en masse empêche. Le
     * pipeline de `Factory` écrit sous `Model::unguarded()`, ce qui est le seul endroit
     * où poser la colonne sans jamais l'exposer à un formulaire.
     *
     * Un rôle ≥ `curator` reçoit un **nom réel factice** (D12 du 23/09) : la garde
     * `User::saving` refuse un compte privilégié sans nom réel, et c'est lui — jamais
     * `name` — que figent `reviewer_name` et `actor_name`. Un nom réel déjà posé par
     * un état antérieur est conservé ; un attribut passé à `create()` l'emporte.
     *
     * Il reçoit aussi un **second facteur confirmé** ({@see self::withTwoFactor()}) :
     * la garde `admin.2fa` ferme la porte `/admin` à tout compte privilégié qui n'en a
     * pas (spec 20 § 2.4), et un curateur de fabrique doit, comme en production,
     * pouvoir entrer. Le compte sans second facteur s'écrit
     * `curator()->withoutTwoFactor()`, dans cet ordre.
     */
    public function role(UserRole $role): static
    {
        return $this->state(function (array $attributes) use ($role): array {
            if (! $role->atLeast(UserRole::Curator)) {
                return ['role' => $role];
            }

            $realName = $attributes['real_name'] ?? null;

            return [
                'role' => $role,
                'real_name' => is_string($realName) && trim($realName) !== '' ? $realName : fake()->name(),
                ...self::confirmedTwoFactor($attributes),
            ];
        });
    }

    /**
     * Le compte de démonstration `player` — celui qui ne peut ni curer ni administrer.
     */
    public function player(): static
    {
        return $this->role(UserRole::Player);
    }

    /**
     * Le compte de démonstration `curator` : c'est lui qui porte
     * `movie.content_verified_by_id` et `frame_review.reviewer_id` / `reviewer_name` /
     * `reviewer_role` du catalogue de démonstration (§ 13.3, exigence 5).
     */
    public function curator(): static
    {
        return $this->role(UserRole::Curator);
    }

    /**
     * Le compte de démonstration `admin` : c'est lui qui porte les lignes `admin_action`
     * permanentes. Sans les trois rôles, la propriété ABSOLUE de `saved_config` face à
     * un admin, l'exemption de purge des comptes privilégiés et la file « rôle
     * privilégié sans 2FA » ne sont testables par aucun test.
     */
    public function admin(): static
    {
        return $this->role(UserRole::Admin);
    }

    /**
     * La langue d'interface — NOT NULL avec défaut, jamais résolue à la lecture : les
     * mails Fortify partent en file, donc `preferredLocale()` est lu hors de toute
     * requête HTTP.
     */
    public function locale(Locale $locale): static
    {
        return $this->state(['locale' => $locale]);
    }

    /**
     * Compte créé par OAuth : AUCUN mot de passe, e-mail vérifié par le fournisseur.
     *
     * `EloquentUserProvider` et `AbstractHasher` renvoient déjà `false` sur un haché
     * nul : aucune faille n'est ouverte. La liaison automatique n'est permise que sur un
     * e-mail vérifié — d'où `email_verified_at` posé ici, et jamais dans
     * {@see self::withoutEmail()}.
     */
    public function oauthOnly(): static
    {
        return $this->state([
            'password' => null,
            'email_verified_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Compte Discord sans adresse : `email` ET `password` nuls.
     *
     * C'est ce cas qui a rendu les deux colonnes nullables, et c'est lui que
     * `User::hasVerifiedEmail()` doit rendre `true` — sinon toute route sous le
     * middleware `verified` renvoie le compte en boucle sur `/email/verify`, qui lui
     * propose d'envoyer un message à une adresse nulle.
     */
    public function withoutEmail(): static
    {
        return $this->state([
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
        ]);
    }

    /**
     * Avatar prédéfini explicitement choisi — une CLÉ stable, jamais un chemin de
     * fichier : remplacer le pack doit être une migration de valeurs, pas une casse de
     * données. La clé est tirée dans {@see AvatarPresetCatalog::keys()}, seul registre
     * des clés (spec 40 § 6.3), dont le nombre est une limite de plate-forme.
     */
    public function withPresetAvatar(?string $preset = null): static
    {
        return $this->state([
            'avatar_kind' => AvatarKind::Preset,
            'avatar_preset' => $preset ?? fake()->randomElement(AvatarPresetCatalog::keys()),
        ]);
    }

    /**
     * Copie LOCALE de la photo du fournisseur, sur le disque `avatars` : aucune URL
     * distante n'est jamais servie au client.
     */
    public function withProviderAvatar(): static
    {
        return $this->state([
            'avatar_kind' => AvatarKind::Provider,
            'avatar_provider_path' => Str::ulid()->toBase32().'.webp',
            'avatar_provider_hidden_at' => null,
        ]);
    }

    /**
     * Photo masquée après deux signalements distincts : le masquage SURVIT à la
     * suppression du fichier, bloque le re-téléchargement et n'est levable que par un
     * admin. Ce n'est pas un drapeau d'affichage, et l'accesseur doit redescendre sur
     * les initiales.
     */
    public function providerAvatarHidden(): static
    {
        return $this->withProviderAvatar()->state([
            'avatar_provider_hidden_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Image téléversée, choisie et VISIBLE (spec 40 § 11, D49 du 01/10) : un
     * vrai WebP 256 × 256 écrit sur le disque `avatars`, qui doit être un
     * `Storage::fake(UploadedAvatars::DISK)` — sans lui, la fixture partirait
     * dans la racine réelle du disque et y resterait après le
     * `RefreshDatabase`.
     */
    public function withUploadedAvatar(): static
    {
        return $this->state(fn (array $attributes): array => [
            'avatar_kind' => AvatarKind::Upload,
            'avatar_preset' => $attributes['avatar_preset'] ?? AvatarPresetCatalog::keys()[0],
            'avatar_upload_path' => UploadedAvatars::newPath(),
            'avatar_upload_hidden_at' => null,
            'avatar_upload_reports_from' => CarbonImmutable::now()->subDay(),
        ])->afterCreating(function (User $user): void {
            if ($user->avatar_upload_path !== null) {
                self::writeAvatar($user->avatar_upload_path);
            }
        });
    }

    /**
     * Image téléversée masquée (seuil ou retrait) : le fichier existe toujours,
     * l'accesseur redescend au prédéfini et le téléversement est bloqué.
     */
    public function uploadedAvatarHidden(): static
    {
        return $this->withUploadedAvatar()->state([
            'avatar_upload_hidden_at' => CarbonImmutable::now(),
        ]);
    }

    /** Un WebP 256 × 256 uni, sur le seul disque `avatars` simulé. */
    private static function writeAvatar(string $path): void
    {
        $disk = UploadedAvatars::disk();
        $root = str_replace('\\', '/', $disk->path(''));
        $fakeRoot = str_replace('\\', '/', storage_path('framework/testing'));

        if (app()->runningUnitTests() && ! str_starts_with($root, $fakeRoot)) {
            throw new RuntimeException('Storage::fake(UploadedAvatars::DISK) est obligatoire avant UserFactory::withUploadedAvatar().');
        }

        $image = new Imagick;
        $image->newImage(256, 256, new ImagickPixel('rgb(40, 90, 160)'));
        $image->setImageFormat('webp');
        $disk->put($path, $image->getImageBlob());
        $image->clear();
    }

    /**
     * Les trois PROJECTIONS de consentement, pour la garde d'affichage à la connexion.
     *
     * Elles ne remplacent jamais les lignes `user_consent`, qui portent l'historique et
     * survivent à l'anonymisation : un seeder qui ne poserait que ces colonnes
     * prouverait la mauvaise version dès le premier changement de CGU.
     */
    public function consented(?string $termsVersion = null): static
    {
        $now = CarbonImmutable::now();

        return $this->state([
            'terms_accepted_at' => $now,
            'terms_version' => $termsVersion ?? Config::string('legal.terms_version'),
            'age_confirmed_at' => $now,
        ]);
    }

    /**
     * Compte dormant : la fenêtre est de 24 mois, VOLONTAIREMENT distincte des 12 mois
     * d'historique — d'où une colonne `last_login_at` qui n'est pas `updated_at`.
     */
    public function dormant(int $monthsAgo = 25): static
    {
        return $this->state([
            'last_login_at' => CarbonImmutable::now()->subMonths($monthsAgo),
        ]);
    }

    /**
     * Pierre tombale du § 5.5 : la LIGNE survit pour l'intégrité référentielle, et rien
     * d'identifiant ne survit avec elle.
     *
     * Conserver l'`id` rend les clés d'auteur structurellement inorphelinables sans
     * jamais dépendre d'un `nullOnDelete` ; `plan` et les consentements sont conservés ;
     * `role` retombe à `player`, `real_name` est vidé et `locale` revient au repli
     * d'instance.
     *
     * Cette fabrique ne pose que l'ÉTAT FINAL de `users` : la suppression en lignes
     * (`linked_account`, `saved_config`, `data_export`, `passkeys`, `sessions`) et
     * l'effacement des identifiants d'invité sur `player` / `game_player` appartiennent
     * à l'action d'anonymisation, jamais à une fixture.
     */
    public function anonymized(): static
    {
        return $this->state([
            'name' => 'deleted-user-'.Str::ulid()->toBase32(),
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'role' => UserRole::Player,
            // Vidé : le nom réel ne survit pas au compte. Les instantanés qu'il a
            // produits (`reviewer_name`, `actor_name`) ne sont jamais touchés.
            'real_name' => null,
            'locale' => Locale::English,
            'avatar_kind' => null,
            'avatar_preset' => null,
            'avatar_provider_path' => null,
            'avatar_provider_hidden_at' => null,
            'anonymized_at' => CarbonImmutable::now(),
        ]);
    }
}
