<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PerfSlowQueryFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une requête SQL lente, rattachée à son échantillon — spec 10 § 7.11
 * (D47 du 01/10).
 *
 * **Le texte SQL à paramètres, jamais les valeurs liées** : une saisie de
 * joueur, une adresse ou un jeton n'y entrent jamais. Regroupée par
 * `sql_hash` (sha1 du texte). Part en cascade avec son échantillon.
 *
 * @property int $id
 * @property int $perf_sample_id
 * @property string $sql_text SQL à paramètres, tronqué à 2 000 caractères.
 * @property string $sql_hash
 * @property int $duration_ms
 * @property CarbonImmutable $recorded_at
 * @property-read PerfSample $sample
 */
#[Table('perf_slow_query')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
class PerfSlowQuery extends Model
{
    /** @use HasFactory<PerfSlowQueryFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'perf_sample_id' => 'integer',
            'duration_ms' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PerfSample, $this>
     */
    public function sample(): BelongsTo
    {
        return $this->belongsTo(PerfSample::class, 'perf_sample_id');
    }
}
