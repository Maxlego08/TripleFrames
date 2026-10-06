<?php

namespace App\Models;

use App\Support\Visitor\VisitorTracker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un navigateur qui a consenti à être reconnu (D62 du 06/10, spec 10 § 7.1
 * bis). Créé par {@see VisitorTracker::accept()} seulement, supprimé au
 * retrait du consentement ou 13 mois après sa dernière activité.
 *
 * `token_hash` est l'empreinte du jeton du cookie `visitor`, jamais le jeton ;
 * `consent_version` et `consented_at` sont la preuve du consentement.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $consent_version
 * @property CarbonImmutable $consented_at
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
#[Hidden(['token_hash'])]
class Visitor extends Model
{
    protected $table = 'visitor';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @return HasMany<Player, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consented_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
