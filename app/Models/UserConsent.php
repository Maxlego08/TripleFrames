<?php

namespace App\Models;

use App\Enums\ConsentKind;
use Carbon\CarbonImmutable;
use Database\Factories\UserConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un consentement daté, en AJOUT SEUL (§ 5.4).
 *
 * La table existe parce qu'une seule paire de colonnes sur `users` porte le
 * consentement COURANT : une nouvelle version des CGU écrase la précédente, et la
 * colonne conservée à travers l'anonymisation prouverait alors la mauvaise version.
 * `users.terms_accepted_at`, `users.terms_version` et `users.age_confirmed_at` sont
 * des PROJECTIONS de la dernière ligne ; l'historique est ici.
 *
 * **Hors périmètre de purge**, et **conservée à l'anonymisation** : une fois `users`
 * vidé, elle ne porte plus aucun identifiant direct et reste la preuve d'une base
 * légale.
 *
 * La table n'a PAS d'`updated_at` : sans `const UPDATED_AT = null`, `$timestamps = true`
 * écrirait une colonne inexistante — erreur 1054 en MySQL, « no such column » en SQLite.
 *
 * @property int $id
 * @property int $user_id
 * @property ConsentKind $kind
 * @property string $version
 * @property CarbonImmutable $accepted_at
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 */
#[Table('user_consent')]
// Un consentement est une colonne d'AUTORITÉ : ni la version acceptée ni son instant
// ne viennent d'un formulaire. La ligne est écrite par l'action de consentement seule,
// qui pose ses attributs explicitement — jamais par affectation de masse.
#[Fillable([])]
class UserConsent extends Model
{
    /** @use HasFactory<UserConsentFactory> */
    use HasFactory;

    /** La table ne porte que `created_at` : l'ajout seul est une propriété du schéma. */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ConsentKind::class,
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
