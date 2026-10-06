<?php

namespace App\Models;

use App\Enums\ContentOrigin;
use App\Support\Catalog\OriginalLanguageTitle;
use Carbon\CarbonImmutable;
use Database\Factories\MovieTitleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Titre affichable d'un film dans une locale de catalogue.
 *
 * L'absence d'une ligne EST l'information : aucun titre n'est jamais recopié
 * d'une langue vers une autre, le repli d'affichage vit dans `05` — sauf le
 * titre de la langue originale activée, égal à `title_original`
 * ({@see OriginalLanguageTitle}, D52 du 02/10). Aucune forme
 * normalisée ici : `answer_key` en est l'unique propriétaire.
 *
 * @property int $id
 * @property int $movie_id
 * @property string $locale locale de CATALOGUE string(12), jamais castée par App\Enums\Locale
 * @property string $title
 * @property ContentOrigin $origin
 * @property int|null $edited_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 * @property-read User|null $editedBy
 */
#[Table('movie_title')]
#[Fillable(['locale', 'title'])]
class MovieTitle extends Model
{
    /** @use HasFactory<MovieTitleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => ContentOrigin::class,
        ];
    }

    /**
     * Le film dont ce titre est l'affichage dans une locale de catalogue.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * L'auteur de la correction de titre, nul pour une ligne importée.
     *
     * @return BelongsTo<User, $this>
     */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id');
    }
}
