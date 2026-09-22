<?php

namespace App\Models;

use App\Avatars\AvatarRef;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Enums\Plan;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Le compte — `users`, table du framework conservée sous son nom, altérée par deux
 * migrations (§ 5.1). `email` et `password` sont NULLABLES depuis l'ALTER : un compte
 * créé par Discord peut n'avoir ni l'un ni l'autre.
 *
 * **Aucune FK ne part d'ici vers un fait de partie** : le lien joueur ↔ compte passe
 * exclusivement par `player.user_id` (nullable), de sorte que l'anonymisation soit
 * structurellement incapable de casser le podium des autres joueurs.
 *
 * `HandleInertiaRequests::share()` sérialise ce modèle ENTIER sur toutes les pages,
 * écran de jeu compris, et le SSR est actif : `#[Hidden]` est ici une règle de
 * SÉCURITÉ, et toute colonne ajoutée à `users` fuite par défaut.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property UserRole $role
 * @property Locale $locale
 * @property Plan $plan
 * @property AvatarKind|null $avatar_kind
 * @property string|null $avatar_preset
 * @property string|null $avatar_provider_path
 * @property CarbonImmutable|null $avatar_provider_hidden_at
 * @property CarbonImmutable|null $terms_accepted_at
 * @property string|null $terms_version
 * @property CarbonImmutable|null $age_confirmed_at
 * @property CarbonImmutable|null $anonymized_at
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string|null $avatar
 * @property-read Collection<int, LinkedAccount> $linkedAccounts
 * @property-read Collection<int, UserConsent> $consents
 * @property-read Collection<int, DataExport> $dataExports
 * @property-read Collection<int, SavedConfig> $savedConfigs
 * @property-read Collection<int, Player> $players
 * @property-read Collection<int, FrameReview> $frameReviews
 * @property-read Collection<int, AdminAction> $adminActions
 * @property-read Collection<int, Report> $reportsAgainst
 * @property-read Collection<int, Frame> $uploadedFrames
 * @property-read Collection<int, Movie> $curatedMovies
 * @property-read Collection<int, Movie> $contentVerifiedMovies
 * @property-read Collection<int, MovieTitle> $editedMovieTitles
 * @property-read Collection<int, Alias> $createdAliases
 * @property-read Collection<int, MovieGroup> $createdMovieGroups
 * @property-read Collection<int, MovieTheme> $assignedMovieThemes
 * @property-read Collection<int, ImportRun> $importRuns
 * @property-read Collection<int, TakedownRequest> $decidedTakedownRequests
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
    'plan',
    'avatar_provider_path',
    'avatar_provider_hidden_at',
    'terms_accepted_at',
    'terms_version',
    'age_confirmed_at',
    'anonymized_at',
    'last_login_at',
])]
#[Appends(['avatar'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Miroir EXACT des trois défauts SQL de `users` : `role`, `locale` et `plan`
     * sont NOT NULL avec défaut, mais un défaut SQL ne remplit que la LIGNE, jamais
     * l'instance qui vient de l'écrire.
     *
     * Sans ces valeurs, le `User::create(['name', 'email', 'password'])` de
     * `CreateNewUser` rend une instance dont `locale` est nulle, et Fortify envoie
     * sa notification de vérification AVANT tout rechargement :
     * {@see self::preferredLocale()} déréférence alors `null` et le message n'est
     * jamais envoyé. Les deux autres colonnes portent la même
     * fragilité — `role` est lue par les policies, et `HandleInertiaRequests::share()`
     * sérialise l'instance entière dès la page suivante.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'role' => UserRole::Player->value,
        'locale' => Locale::English->value,
        'plan' => Plan::Free->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'locale' => Locale::class,
            'plan' => Plan::class,
            'avatar_kind' => AvatarKind::class,
            'avatar_provider_hidden_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'age_confirmed_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Comptes fournisseurs liés — au plus un par fournisseur.
     *
     * @return HasMany<LinkedAccount, $this>
     */
    public function linkedAccounts(): HasMany
    {
        return $this->hasMany(LinkedAccount::class);
    }

    /**
     * Historique des consentements, en ajout seul et conservé à l'anonymisation.
     *
     * @return HasMany<UserConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    /**
     * Archives d'export de données, supprimées avant l'anonymisation.
     *
     * @return HasMany<DataExport, $this>
     */
    public function dataExports(): HasMany
    {
        return $this->hasMany(DataExport::class);
    }

    /**
     * Configurations de salon sauvegardées — strictement privées, y compris d'un admin.
     *
     * @return HasMany<SavedConfig, $this>
     */
    public function savedConfigs(): HasMany
    {
        return $this->hasMany(SavedConfig::class);
    }

    /**
     * Sièges pris par ce compte. Le lien est nullable et rompu à l'anonymisation.
     *
     * @return HasMany<Player, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * Revues d'image signées — jamais touchées par l'anonymisation, `reviewer_name`
     * comprise : c'est une preuve opposable.
     *
     * @return HasMany<FrameReview, $this>
     */
    public function frameReviews(): HasMany
    {
        return $this->hasMany(FrameReview::class, 'reviewer_id');
    }

    /**
     * Journal d'administration — ajout seul, `actor_name` jamais touchée non plus.
     *
     * @return HasMany<AdminAction, $this>
     */
    public function adminActions(): HasMany
    {
        return $this->hasMany(AdminAction::class, 'actor_id');
    }

    /**
     * Signalements VISANT ce compte (pseudo ou avatar de fournisseur).
     *
     * @return HasMany<Report, $this>
     */
    public function reportsAgainst(): HasMany
    {
        return $this->hasMany(Report::class, 'target_user_id');
    }

    /**
     * @return HasMany<Frame, $this>
     */
    public function uploadedFrames(): HasMany
    {
        return $this->hasMany(Frame::class, 'uploaded_by_id');
    }

    /**
     * @return HasMany<Movie, $this>
     */
    public function curatedMovies(): HasMany
    {
        return $this->hasMany(Movie::class, 'curated_by_id');
    }

    /**
     * @return HasMany<Movie, $this>
     */
    public function contentVerifiedMovies(): HasMany
    {
        return $this->hasMany(Movie::class, 'content_verified_by_id');
    }

    /**
     * @return HasMany<MovieTitle, $this>
     */
    public function editedMovieTitles(): HasMany
    {
        return $this->hasMany(MovieTitle::class, 'edited_by_id');
    }

    /**
     * @return HasMany<Alias, $this>
     */
    public function createdAliases(): HasMany
    {
        return $this->hasMany(Alias::class, 'created_by_id');
    }

    /**
     * @return HasMany<MovieGroup, $this>
     */
    public function createdMovieGroups(): HasMany
    {
        return $this->hasMany(MovieGroup::class, 'created_by_id');
    }

    /**
     * @return HasMany<MovieTheme, $this>
     */
    public function assignedMovieThemes(): HasMany
    {
        return $this->hasMany(MovieTheme::class, 'assigned_by_id');
    }

    /**
     * @return HasMany<ImportRun, $this>
     */
    public function importRuns(): HasMany
    {
        return $this->hasMany(ImportRun::class, 'actor_id');
    }

    /**
     * @return HasMany<TakedownRequest, $this>
     */
    public function decidedTakedownRequests(): HasMany
    {
        return $this->hasMany(TakedownRequest::class, 'decided_by_id');
    }

    /**
     * Locale de notification, lue HORS de toute requête HTTP : les mails Fortify
     * partent en file, sans cookie ni session. C'est la raison pour laquelle
     * `users.locale` est NOT NULL avec le repli d'instance en défaut, et n'est jamais
     * résolue à la lecture.
     */
    public function preferredLocale(): string
    {
        return $this->locale->value;
    }

    /**
     * Un compte sans e-mail est vérifié par construction.
     *
     * Le périmètre n'est pas « la suppression de son propre compte » mais TOUTE route
     * sous le middleware `verified` : sans ce correctif, un compte Discord sans e-mail
     * boucle sur `/email/verify`, qui lui propose d'envoyer un message à une adresse
     * nulle.
     */
    public function hasVerifiedEmail(): bool
    {
        if ($this->email === null) {
            return true;
        }

        return $this->email_verified_at !== null;
    }

    /**
     * Inopérant sans adresse : corollaire strict de {@see self::hasVerifiedEmail()}.
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->email === null) {
            return;
        }

        parent::sendEmailVerificationNotification();
    }

    /**
     * Accesseur de domaine, seule résolution d'avatar du serveur.
     *
     * **Jamais nommée `avatar()`** : `HasAttributes::hasAttributeMutator()` exige le
     * type de retour exact `Illuminate\Database\Eloquent\Casts\Attribute`. Un
     * `public function avatar(): AvatarRef` ferait retomber `mutateAttributeForArray()`
     * sur un `getAvatarAttribute()` inexistant, et TOUTE page authentifiée renverrait
     * 500 — le modèle est sérialisé partout.
     */
    public function avatarRef(): AvatarRef
    {
        $initials = AvatarRef::initialsFrom($this->name);

        if ($this->avatar_kind === AvatarKind::Preset && $this->avatar_preset !== null) {
            return AvatarRef::preset($this->avatar_preset, $initials);
        }

        if ($this->avatar_kind === AvatarKind::Provider
            && $this->avatar_provider_path !== null
            && $this->avatar_provider_hidden_at === null
        ) {
            return AvatarRef::provider($this->avatar_provider_path, $initials);
        }

        return AvatarRef::initials($initials);
    }

    /**
     * L'attribut sérialisé, de type `string|null` STRICT : un `Attribute` qui rendrait
     * l'objet complet produirait `"avatar":{"url":…}`, que `user-info.tsx` poserait en
     * `src="[object Object]"`.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatarRef()->url);
    }
}
