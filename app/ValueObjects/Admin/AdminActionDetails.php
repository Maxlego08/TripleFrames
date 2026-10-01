<?php

namespace App\ValueObjects\Admin;

use App\Casts\AdminActionDetailsCast;
use App\Enums\ContentOrigin;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameSourceKind;
use App\Enums\ThemeKind;
use App\Models\AdminAction;
use App\Models\FrameReview;
use App\Support\Frames\CropRect;
use JsonSerializable;

/**
 * Le complément d'une ligne du journal : `admin_action.details`, JSON
 * nullable (D41 du 30/09, spec 10 § 8.3), porté par
 * {@see AdminActionDetailsCast} — jamais un cast `'array'` nu.
 *
 * **Ce qu'un geste détruit ou écrase, et rien d'autre.** Un titre ou un alias
 * supprimés physiquement, un titre, un niveau ou un rectangle réécrits en
 * place, un groupe dissous, les identifiants d'un collage qui ne sont stockés
 * nulle part : sans cette colonne, la ligne dirait « quelqu'un a changé
 * quelque chose » sans pouvoir dire quoi.
 *
 * **Jamais une donnée personnelle** : la ligne est permanente. Des données de
 * catalogue, des identifiants et des rectangles — jamais une adresse, un
 * pseudo ni une saisie libre de recherche. Un constructeur nommé par cas, et
 * aucun constructeur public : aucun appelant ne compose un tableau libre.
 *
 * **Affiché seulement** : la colonne n'est jamais lue dans une clause `WHERE`
 * ni `ORDER BY` (tests en SQLite, développement en MySQL). Filtrer par
 * décision de revue se fait sur `frame_review.decision`, colonne typée.
 *
 * Bornée à {@see self::MAX_BYTES} octets sérialisée, par la garde `creating`
 * d'{@see AdminAction}.
 */
final readonly class AdminActionDetails implements JsonSerializable
{
    /** Taille maximale de la charge sérialisée. */
    public const int MAX_BYTES = 4096;

    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(public array $values) {}

    /** `movie.title_saved` : l'ancien titre (NULL si la ligne est créée) et le nouveau. */
    public static function titleSaved(string $locale, ?string $before, ?ContentOrigin $beforeOrigin, string $after): self
    {
        return new self([
            'locale' => $locale,
            'before' => $before,
            'before_origin' => $beforeOrigin?->value,
            'after' => $after,
        ]);
    }

    /** `movie.title_removed` : le texte supprimé. */
    public static function titleRemoved(string $locale, string $title): self
    {
        return new self([
            'locale' => $locale,
            'title' => $title,
        ]);
    }

    /** `movie.alias_added`. */
    public static function aliasAdded(int $aliasId, string $locale, string $alias): self
    {
        return new self([
            'alias_id' => $aliasId,
            'locale' => $locale,
            'alias' => $alias,
        ]);
    }

    /** `movie.alias_removed` : le texte supprimé et son origine. */
    public static function aliasRemoved(int $aliasId, string $locale, string $alias, ContentOrigin $origin): self
    {
        return new self([
            'alias_id' => $aliasId,
            'locale' => $locale,
            'alias' => $alias,
            'origin' => $origin->value,
        ]);
    }

    /**
     * `movie.grouped` : l'issue (`created` ou `joined`), le groupe, son
     * libellé et, pour un regroupement avec un film, le partenaire.
     */
    public static function grouped(string $outcome, int $groupId, string $label, ?int $withMovieId): self
    {
        return new self([
            'outcome' => $outcome,
            'group_id' => $groupId,
            'label' => $label,
            'with_movie_id' => $withMovieId,
        ]);
    }

    /** `movie.ungrouped` : le groupe quitté, et s'il a été dissous. */
    public static function ungrouped(int $groupId, ?string $label, bool $dissolved): self
    {
        return new self([
            'group_id' => $groupId,
            'group_label' => $label,
            'dissolved' => $dissolved,
        ]);
    }

    /**
     * `movie.theme_set` : la clé du thème — stable, immuable après création —
     * et l'exception avant et après (`added`, `removed`, ou `null` quand la
     * règle décide seule).
     */
    public static function movieThemeSet(string $themeKey, ?string $from, ?string $to): self
    {
        return new self([
            'theme_key' => $themeKey,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** `frame.added` : la voie et le niveau au moment de l'ajout. */
    public static function frameAdded(FrameSourceKind $sourceKind, FrameLevel $level): self
    {
        return new self([
            'source_kind' => $sourceKind->value,
            'frame_level' => $level->value,
        ]);
    }

    /** `frame.recropped` : le rectangle écrasé et le nouveau. */
    public static function recropped(CropRect $before, CropRect $after): self
    {
        return new self([
            'before' => $before->toArray(),
            'after' => $after->toArray(),
        ]);
    }

    /** `frame.processing_retried` : l'échec que la relance efface. */
    public static function processingRetried(?FrameProcessingFailure $failure): self
    {
        return new self([
            'failure' => $failure?->value,
        ]);
    }

    /** `frame.level_changed` : l'ancien niveau et le nouveau. */
    public static function levelChanged(FrameLevel $from, FrameLevel $to): self
    {
        return new self([
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    /** `frame.reviewed` : la preuve pointée, jamais dupliquée. */
    public static function reviewed(FrameReview $review): self
    {
        return new self([
            'review_id' => $review->id,
            'decision' => $review->decision->value,
            'grid_version' => $review->grid_version,
        ]);
    }

    /**
     * `movie.frames_reviewed` (D42 du 30/09) : les images validées en lot, et
     * la version de la grille appliquée à chacune. Chaque preuve reste sa
     * ligne `frame_review`, jamais dupliquée ici.
     *
     * @param  list<int>  $frameIds
     */
    public static function framesReviewed(array $frameIds, int $gridVersion): self
    {
        return new self([
            'frame_ids' => $frameIds,
            'grid_version' => $gridVersion,
        ]);
    }

    /** `import.discover_started` et `import.resumed` : le budget de pages confié au job. */
    public static function importPages(int $pages): self
    {
        return new self([
            'pages' => $pages,
        ]);
    }

    /**
     * `import.paste_started` et `import.seed_list_started` : les identifiants
     * TMDB confiés au job, que rien d'autre ne conserve — et, pour un collage
     * avec thèmes (D43 du 01/10, spec 20 § 2.7), les thèmes choisis. La clé
     * `theme_ids` n'est présente que si la sélection n'est pas vide : le
     * geste est journalisé une fois, jamais par film.
     *
     * @param  list<int>  $tmdbIds
     * @param  list<int>  $themeIds
     */
    public static function importIds(array $tmdbIds, array $themeIds = []): self
    {
        return new self([
            'tmdb_ids' => $tmdbIds,
            ...($themeIds === [] ? [] : ['theme_ids' => $themeIds]),
        ]);
    }

    /**
     * `theme.created` (D43 du 01/10) : la clé générée, la nature, la règle
     * (NULL pour un thème manuel), sa négation et les libellés par locale —
     * des données de catalogue, jamais une donnée personnelle.
     *
     * @param  array<string, string>  $labels  locale => libellé
     */
    public static function themeCreated(string $key, ThemeKind $kind, ?string $ruleValue, bool $ruleNegated, array $labels): self
    {
        ksort($labels);

        return new self([
            'key' => $key,
            'kind' => $kind->value,
            'rule_value' => $ruleValue,
            'rule_negated' => $ruleNegated,
            'labels' => $labels,
        ]);
    }

    /**
     * `theme.updated` : l'avant et l'après des seuls champs changés parmi
     * `rule_value`, `rule_negated`, `labels` et `sort_order` — l'écriture
     * écrase en place, la ligne garde ce qui a été écrasé.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function themeUpdated(array $before, array $after): self
    {
        return new self([
            'before' => $before,
            'after' => $after,
        ]);
    }

    /** `theme.published` et `theme.unpublished` : le nombre d'œuvres relu dans la transaction du geste. */
    public static function themePublication(int $works): self
    {
        return new self([
            'works' => $works,
        ]);
    }

    /**
     * Relecture d'une charge stockée — le cast seul l'appelle.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromStorage(array $values): self
    {
        return new self($values);
    }

    /** La charge sérialisée, telle qu'elle est écrite en base. */
    public function toJson(): string
    {
        return json_encode($this->values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
