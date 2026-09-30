<?php

namespace App\Models;

use App\Enums\OAuthProvider;
use Carbon\CarbonImmutable;
use Database\Factories\LinkedAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un compte fournisseur lié à un compte TripleFrames (§ 5.4).
 *
 * Deux unicités : `(provider, provider_user_id)` — un compte fournisseur appartient à
 * un seul `User`, et c'est l'index du chemin chaud du callback OAuth — et
 * `(user_id, provider)` — un `User` a au plus un compte par fournisseur.
 *
 * **Aucune adresse IP, aucun jeton d'accès ni de rafraîchissement** : la v1 ne rappelle
 * jamais l'API du fournisseur après le callback, hors téléchargement unique de la photo.
 * **La déliaison SUPPRIME la ligne**, elle ne la marque pas : identifiant et e-mail du
 * fournisseur disparaissent réellement, et l'avatar effectif redescend la chaîne de
 * repli par simple recalcul de l'accesseur, sans écriture.
 *
 * **`#[Fillable]` vide.** `provider_user_id` n'est pas une donnée de formulaire :
 * c'est l'assertion d'identité rendue par Socialite, et la moitié de l'unicité
 * `(provider, provider_user_id)`. Un `$user->linkedAccounts()->create($request->validated())`
 * laisserait poster l'identifiant Discord d'un tiers et pré-revendiquer son compte
 * fournisseur : la victime ne pourrait plus jamais lier le sien. Le callback OAuth
 * construit la ligne colonne par colonne depuis l'objet Socialite.
 *
 * @property int $id
 * @property int $user_id
 * @property OAuthProvider $provider
 * @property string $provider_user_id
 * @property string|null $provider_email
 * @property bool $provider_email_verified
 * @property string|null $suggested_nickname
 * @property string|null $provider_avatar_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Table('linked_account')]
#[Fillable([])]
#[Hidden(['provider_user_id', 'provider_email', 'provider_avatar_url'])]
class LinkedAccount extends Model
{
    /** @use HasFactory<LinkedAccountFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `linked_account` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'provider_email_verified' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => OAuthProvider::class,
            'provider_email_verified' => 'boolean',
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
