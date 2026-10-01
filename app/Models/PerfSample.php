<?php

namespace App\Models;

use App\Support\Perf\PerfRecorder;
use Carbon\CarbonImmutable;
use Database\Factories\PerfSampleFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un échantillon de mesure : une requête HTTP du groupe `web` ou un job —
 * spec 10 § 7.11, spec 100 § 10.11 (D47 du 01/10).
 *
 * **Aucune donnée personnelle** : le nom de la route et jamais l'URL (qui
 * porte codes de salon et jetons), ni IP, ni compte, ni contenu. Écrit par
 * {@see PerfRecorder} seul, conservé 14 jours (périmètre
 * `perf`).
 *
 * En ajout seul : `recorded_at` tient lieu d'horodatage, `$timestamps = false`.
 *
 * @property int $id
 * @property string $kind `request` ou `job`.
 * @property string $name Nom de route, `unnamed`, ou classe du job.
 * @property string|null $method
 * @property string|null $queue
 * @property string $status Code HTTP, ou `processed` / `failed`.
 * @property int $duration_ms
 * @property int $query_count
 * @property int $query_ms
 * @property int $memory_kb
 * @property int|null $wait_ms Job : démarrage − mise en file.
 * @property CarbonImmutable $recorded_at
 * @property-read Collection<int, PerfSlowQuery> $slowQueries
 */
#[Table('perf_sample')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
class PerfSample extends Model
{
    /** @use HasFactory<PerfSampleFactory> */
    use HasFactory;

    public const string KIND_REQUEST = 'request';

    public const string KIND_JOB = 'job';

    public const string STATUS_PROCESSED = 'processed';

    public const string STATUS_FAILED = 'failed';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'query_count' => 'integer',
            'query_ms' => 'integer',
            'memory_kb' => 'integer',
            'wait_ms' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PerfSlowQuery, $this>
     */
    public function slowQueries(): HasMany
    {
        return $this->hasMany(PerfSlowQuery::class);
    }
}
