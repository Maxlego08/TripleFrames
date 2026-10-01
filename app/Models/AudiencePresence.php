<?php

namespace App\Models;

use App\Support\Audience\AudienceRecorder;
use Carbon\CarbonImmutable;
use Database\Factories\AudiencePresenceFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un visiteur présent — spec 10 § 7.12 (D48 du 01/10).
 *
 * Une ligne par empreinte du jour vue dans les dix dernières minutes,
 * effacée au-delà par {@see AudienceRecorder} : un état technique
 * transitoire. L'empreinte est salée par un sel du jour gardé en cache
 * seulement ; elle ne se relie à aucun autre jour et ne quitte jamais le
 * serveur (`#[Hidden]`).
 *
 * @property string $visitor_hash `#[Hidden]`.
 * @property string $route
 * @property CarbonImmutable $last_seen_at
 */
#[Table('audience_presence')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden(['visitor_hash'])]
class AudiencePresence extends Model
{
    /** @use HasFactory<AudiencePresenceFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'visitor_hash';

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
