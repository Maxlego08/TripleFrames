<?php

namespace App\Models;

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un balayage d'import TMDB : la provenance, et la reprise (§ 9.1).
 *
 * Trois besoins qu'aucune autre table ne porte : reprendre un balayage de 500+
 * films interrompu par le quota TMDB ou un `queue:restart` ; prouver qu'un
 * balayage a été élargi, sans quoi le marquage d'exception devient arbitraire ;
 * faire survivre l'étranglement de quota à un redémarrage de worker.
 *
 * `filter_languages` est une chaîne jointe par virgules, **jamais interrogée** :
 * affichage et rejeu, explicitement pas un critère de requête — d'où l'absence
 * de cast et d'index sur cette colonne.
 *
 * Les trois colonnes `filter_*` sont ce qui permet de **rejouer** un balayage ;
 * les valeurs par défaut (500 votes, `{fr, en, ja}`, 1970 — décision 11) vivent
 * en configuration (`config('catalog.import_filter')`), jamais en littéral ici,
 * ni dans une migration, ni dans un FormRequest. `App\ValueObjects\Catalog\ImportFilter`
 * est le seul lecteur de ce couple configuration / colonnes : il pose
 * `is_widened` par `isWiderThanDefault()` et les trois motifs d'exception de
 * `movie` par `exceptionMotivesFor()`.
 *
 * `total_refused_content` est le nombre de lignes refusées par le filtre de
 * **contenu** — chiffre qu'il faut pouvoir produire devant une mise en demeure
 * autant qu'à l'écran d'aperçu d'un collage. **Aucune adresse IP, aucune donnée
 * de joueur** : rien ici n'est caché parce que rien ici n'est personnel, et la
 * table ne quitte jamais le back-office.
 *
 * Seules les trois colonnes de filtre sont mass-assignables. `run_kind` porte le
 * droit d'écrasement du balayage (§ 9.3), `is_widened` est **figée au démarrage**,
 * `status`, les quatre compteurs, le curseur de page et les horodatages sont
 * écrits par le job : aucune de ces colonnes n'entre dans `#[Fillable]`.
 *
 * @property int $id
 * @property ImportRunKind $run_kind
 * @property ImportRunStatus $status
 * @property int|null $actor_id
 * @property int|null $filter_min_vote_count
 * @property string|null $filter_languages
 * @property int|null $filter_min_release_year
 * @property bool $is_widened
 * @property int|null $tmdb_page_cursor
 * @property CarbonImmutable|null $last_request_at
 * @property int $total_seen
 * @property int $total_imported
 * @property int $total_skipped
 * @property int $total_refused_content
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $actor
 * @property-read Collection<int, Movie> $movies
 */
#[Table('import_run')]
#[Fillable(['filter_min_vote_count', 'filter_languages', 'filter_min_release_year'])]
class ImportRun extends Model
{
    /** @use HasFactory<ImportRunFactory> */
    use HasFactory;

    /**
     * Le curateur ou l'administrateur à l'origine du balayage, `nullOnDelete`.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Les films entrés par ce balayage. La FK `movie.import_run_id` est
     * `nullOnDelete` : un film survit toujours à la disparition de son balayage.
     *
     * @return HasMany<Movie, $this>
     */
    public function movies(): HasMany
    {
        return $this->hasMany(Movie::class);
    }

    /**
     * Les balayages à reprendre au démarrage d'un worker — la raison d'être de
     * l'index `(status)` (§ 9.1). L'état reprenable lui-même vit dans
     * `tmdb_page_cursor`, `last_request_at` et les quatre compteurs.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function resumable(Builder $query): void
    {
        $query->where('status', ImportRunStatus::Running);
    }

    /**
     * Miroir EXACT des défauts SQL de `import_run` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'status' => ImportRunStatus::Running->value,
        'is_widened' => false,
        'total_seen' => 0,
        'total_imported' => 0,
        'total_skipped' => 0,
        'total_refused_content' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_kind' => ImportRunKind::class,
            'status' => ImportRunStatus::class,
            'filter_min_vote_count' => 'integer',
            'filter_min_release_year' => 'integer',
            'is_widened' => 'boolean',
            'tmdb_page_cursor' => 'integer',
            'last_request_at' => 'datetime',
            'total_seen' => 'integer',
            'total_imported' => 'integer',
            'total_skipped' => 'integer',
            'total_refused_content' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
