<?php

namespace App\Models;

use App\Support\Audience\AudienceRecorder;
use Carbon\CarbonImmutable;
use Database\Factories\AudienceDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un compteur d'audience d'un jour — spec 10 § 7.12 (D48 du 01/10).
 *
 * Écrit par incréments (`upsert`) par {@see AudienceRecorder} seul, jamais
 * une ligne par visite ; conservé 13 mois (périmètre `audience`). Aucune
 * donnée personnelle : une mesure, une dimension (route, domaine référent,
 * langue, type d'appareil) et un total.
 *
 * @property int $id
 * @property CarbonImmutable $day
 * @property string $metric
 * @property string $dimension Vide pour un total.
 * @property int $total
 */
#[Table('audience_daily')]
#[Fillable([])]
class AudienceDaily extends Model
{
    /** @use HasFactory<AudienceDailyFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Les défauts SQL, miroités pour l'instance qui vient d'écrire la ligne.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'dimension' => '',
        'total' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'total' => 'integer',
        ];
    }
}
