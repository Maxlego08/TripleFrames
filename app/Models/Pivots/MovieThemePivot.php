<?php

namespace App\Models\Pivots;

use App\Enums\ThemeMembershipState;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Le pivot typé de l'unique `belongsToMany` du schéma —
 * {@see Movie::themes()} et {@see Theme::movies()}.
 *
 * **Il n'est pas un modèle de plus** : la source canonique de `movie_theme` reste
 * {@see MovieTheme}, seul chemin d'écriture. Ce pivot n'existe que pour que la
 * lecture de confort rende les MÊMES types que le modèle canonique.
 *
 * Sans `->using(...)`, `hydratePivotRelation()` passe par
 * `Pivot::fromRawAttributes(..., true)` donc `setRawAttributes()` : les valeurs
 * restent BRUTES et `Pivot` n'a aucun `casts()`. Conséquences mesurables, toutes
 * muettes : `$movie->themes->first()->pivot->manual_state === ThemeMembershipState::Removed`
 * est TOUJOURS faux (le pivot rend la chaîne `'removed'`), donc une fiche de
 * curation réaffiche un thème que le curateur a explicitement retiré — l'exception
 * même que `manual_state` existe pour faire survivre au réimport (§ 9.3) ;
 * `$m->pivot->assigned_at->format(...)` lève « on string » ; et
 * `$m->pivot->is_active === true` est faux, la valeur étant l'entier 1.
 *
 * @property bool $is_auto
 * @property ThemeMembershipState|null $manual_state
 * @property bool $is_active
 * @property int|null $assigned_by_id
 * @property CarbonImmutable|null $assigned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('movie_theme')]
final class MovieThemePivot extends Pivot
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_auto' => 'boolean',
            'manual_state' => ThemeMembershipState::class,
            'is_active' => 'boolean',
            'assigned_by_id' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }
}
