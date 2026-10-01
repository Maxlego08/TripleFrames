<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TmdbCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Le nom TMDB d'une société de production, pour le back-office seul
 * (spec 10 § 3.6 bis, D43 du 01/10).
 *
 * `name` est **non localisé** (identique en `fr-FR` et en `en-US`) et n'est
 * jamais affiché à un joueur : il sert à nommer une société là où un curateur
 * désigne un thème studio (« Marvel Studios (420) »).
 *
 * **Aucune relation déclarée vers `movie_tmdb_tag`** : `tmdb_tag_id` reste
 * l'identifiant brut, une étiquette existe sans nom, et un lecteur joint par
 * `tmdb_id` en une requête par écran.
 *
 * Écrivains : `MovieImporter` (upsert à l'import et à la resynchronisation, nom
 * réécrit), `PlatformDataSeeder` (insertion si absente) et
 * `catalog:company-names` (rattrapage). Table de catalogue, hors des cinq tables
 * de la règle 12, au périmètre interdit de la purge (§ 11.2).
 *
 * @property int $id
 * @property int $tmdb_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('tmdb_company')]
#[Fillable(['tmdb_id', 'name'])]
class TmdbCompany extends Model
{
    /** @use HasFactory<TmdbCompanyFactory> */
    use HasFactory;

    /** Largeur de `name`, à laquelle tout nom est tronqué à l'écriture. */
    public const int NAME_MAX_LENGTH = 160;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tmdb_id' => 'integer',
        ];
    }
}
