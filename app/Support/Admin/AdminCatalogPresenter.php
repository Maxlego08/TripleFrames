<?php

namespace App\Support\Admin;

use App\Enums\FrameProcessingState;
use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\Frame;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieProjection;
use App\Models\MovieTheme;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\ThemeLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * La forme EXACTE des props d'administration, écrite une seule fois.
 *
 * Trois écrans servent la même ligne de film et trois servent la même ligne de
 * balayage ; les laisser se recopier d'un contrôleur à l'autre produirait un
 * `tmdb_id` ici et un `tmdbId` là, c'est-à-dire une journée perdue pour l'agent
 * qui écrit le TypeScript en face. Les noms de champs sont en **snake_case**,
 * miroir exact des colonnes.
 *
 * **Règle de sécurité tenue ici, et non déléguée à un `#[Hidden]`** : aucune
 * prop ne sérialise jamais un modèle entier. On ne compose que ce que l'écran
 * affiche. En particulier, **aucun chemin de fichier d'image ne quitte le
 * serveur** — le § 10 est formel, et un `frame` n'expose ici que son niveau,
 * sa disponibilité et l'état de son job. `frame.id` lui-même n'est pas exposé :
 * il est `#[Hidden]` (§ 4.1), un identifiant séquentiel permettant de regrouper
 * les images d'un même film, et la fiche est en lecture seule.
 *
 * Aucun `LengthAwarePaginator` n'est expédié tel quel : son tableau `links`
 * embarque « Previous », « Next » et « &laquo; » produits par le framework en
 * anglais, intraduisibles depuis `lang/fr/admin.php`. {@see self::paginated()}
 * ne rend que `data` et les six champs de `meta`.
 */
final class AdminCatalogPresenter
{
    /**
     * Une ligne de catalogue — les colonnes des trois listes de films.
     *
     * `levels_count`, `levels_mask` et `variants_total` viennent de la
     * projection (§ 3.2) et jamais d'un agrégat sur `frame` : un film sans
     * ligne de projection vaut zéro partout, ce qui est la vérité à afficher.
     *
     * @return array{
     *     id: int,
     *     tmdb_id: int|null,
     *     title_original: string,
     *     title_original_latin: string|null,
     *     original_language: string,
     *     release_year: int|null,
     *     vote_count: int,
     *     availability: string,
     *     content_flag: string,
     *     import_source: string,
     *     is_import_exception: bool,
     *     exception_for_language: bool,
     *     exception_for_vote_count: bool,
     *     exception_for_release_year: bool,
     *     levels_count: int,
     *     levels_mask: int,
     *     variants_total: int,
     *     created_at: string|null,
     * }
     */
    public static function movieRow(Movie $movie): array
    {
        $projection = self::projectionOf($movie);

        return [
            'id' => $movie->id,
            'tmdb_id' => $movie->tmdb_id,
            'title_original' => $movie->title_original,
            'title_original_latin' => $movie->title_original_latin,
            'original_language' => $movie->original_language,
            'release_year' => $movie->release_year,
            'vote_count' => $movie->vote_count,
            'availability' => $movie->availability->value,
            'content_flag' => $movie->content_flag->value,
            'import_source' => $movie->import_source->value,
            'is_import_exception' => $movie->is_import_exception,
            'exception_for_language' => $movie->exception_for_language,
            'exception_for_vote_count' => $movie->exception_for_vote_count,
            'exception_for_release_year' => $movie->exception_for_release_year,
            'levels_count' => $projection->levels_count ?? 0,
            'levels_mask' => $projection->levels_mask ?? 0,
            'variants_total' => $projection->variants_total ?? 0,
            'created_at' => self::moment($movie->created_at),
        ];
    }

    /**
     * La fiche film — la ligne de liste, plus tout ce que la fiche affiche.
     *
     * Les deux auteurs sont rendus par leur **nom** et non par leur
     * identifiant : la fiche les affiche, elle ne s'en sert pas pour appeler
     * quoi que ce soit.
     *
     * @return array<string, mixed>
     */
    public static function movieDetail(Movie $movie): array
    {
        return [
            ...self::movieRow($movie),
            'adult' => $movie->adult,
            'availability_changed_at' => self::moment($movie->availability_changed_at),
            'availability_reason' => $movie->availability_reason,
            'first_published_at' => self::moment($movie->first_published_at),
            'content_verified_by' => $movie->contentVerifiedBy?->name,
            'content_verified_at' => self::moment($movie->content_verified_at),
            'movie_difficulty' => $movie->movie_difficulty?->value,
            'movie_difficulty_derived' => $movie->movie_difficulty_derived?->value,
            'movie_difficulty_override' => $movie->movie_difficulty_override?->value,
            'collection_name' => $movie->collection?->name,
            'group_label' => $movie->group?->label,
            'curated_by' => $movie->curatedBy?->name,
            'curation_active_seconds' => $movie->curation_active_seconds,
            'import_run_id' => $movie->import_run_id,
            'updated_at' => self::moment($movie->updated_at),
        ];
    }

    /**
     * La projection d'un film. `covers_publishable` et `playable_at` sont
     * **dérivés**, jamais stockés (§ 3.2) : la couverture se teste par
     * `levels_mask & 21 = 21`, arithmétique entière portable, et jamais par
     * `BIT_COUNT`, inexistant en SQLite.
     *
     * @return array{
     *     levels_mask: int,
     *     levels_count: int,
     *     level_1_variants: int,
     *     level_2_variants: int,
     *     level_3_variants: int,
     *     level_4_variants: int,
     *     level_5_variants: int,
     *     variants_total: int,
     *     title_locale_mask: int,
     *     title_mask_version: int,
     *     title_mask_current: bool,
     *     covers_publishable: bool,
     *     playable_at: list<int>,
     *     recomputed_at: string|null,
     * }
     */
    public static function movieProjection(MovieProjection $projection): array
    {
        /** @var list<int> $playableAt */
        $playableAt = [];

        foreach (range(2, 5) as $framesPerRound) {
            if ($projection->supportsFramesPerRound($framesPerRound)) {
                $playableAt[] = $framesPerRound;
            }
        }

        return [
            'levels_mask' => $projection->levels_mask,
            'levels_count' => $projection->levels_count,
            'level_1_variants' => $projection->level_1_variants,
            'level_2_variants' => $projection->level_2_variants,
            'level_3_variants' => $projection->level_3_variants,
            'level_4_variants' => $projection->level_4_variants,
            'level_5_variants' => $projection->level_5_variants,
            'variants_total' => $projection->variants_total,
            'title_locale_mask' => $projection->title_locale_mask,
            'title_mask_version' => $projection->title_mask_version,
            'title_mask_current' => $projection->hasCurrentTitleMask(),
            'covers_publishable' => $projection->coversPublishableLevels(),
            'playable_at' => $playableAt,
            'recomputed_at' => self::moment($projection->recomputed_at),
        ];
    }

    /**
     * Un titre affichable. `locale` est une locale de **catalogue** string(12),
     * jamais castée par `App\Enums\Locale` : elle accepte `ja`, `zh-Hant`.
     *
     * @return array{locale: string, title: string, origin: string, edited_by: string|null}
     */
    public static function movieTitle(MovieTitle $title): array
    {
        return [
            'locale' => $title->locale,
            'title' => $title->title,
            'origin' => $title->origin->value,
            'edited_by' => $title->editedBy?->name,
        ];
    }

    /**
     * Une variante acceptée en réponse — **à usage exclusif de validation**,
     * jamais affichée à un joueur (§ 3.4). L'écran le dit en toutes lettres.
     *
     * @return array{locale: string, alias: string, origin: string}
     */
    public static function movieAlias(Alias $alias): array
    {
        return [
            'locale' => $alias->locale,
            'alias' => $alias->alias,
            'origin' => $alias->origin->value,
        ];
    }

    /**
     * La certification retenue pour un pays, chaîne brute TMDB conservée telle
     * quelle (§ 3.8).
     *
     * @return array{country: string, certification: string, is_restrictive: bool, released_on: string|null, read_at: string|null}
     */
    public static function movieCertification(MovieCertification $certification): array
    {
        return [
            'country' => $certification->country->value,
            'certification' => $certification->certification,
            'is_restrictive' => $certification->is_restrictive,
            'released_on' => $certification->released_on?->toDateString(),
            'read_at' => self::moment($certification->read_at),
        ];
    }

    /**
     * Une étiquette TMDB **brute** : le schéma ne stocke aucun libellé, et la
     * fiche le dit (§ 3.6).
     *
     * @return array{tag_kind: string, tmdb_tag_id: int}
     */
    public static function movieTag(MovieTmdbTag $tag): array
    {
        return [
            'tag_kind' => $tag->tag_kind->value,
            'tmdb_tag_id' => $tag->tmdb_tag_id,
        ];
    }

    /**
     * Une appartenance thématique : la règle automatique, l'exception manuelle
     * et l'appartenance **effective**, séparées (§ 3.7).
     *
     * Le libellé est celui de la locale du back-office — le français, forcé par
     * `ForceAdminLocale` — et retombe sur la clé technique du thème quand le
     * libellé manque : un thème sans libellé n'est de toute façon pas publiable.
     *
     * @return array{key: string, label: string, is_auto: bool, manual_state: string|null, is_active: bool}
     */
    public static function movieTheme(MovieTheme $membership): array
    {
        $theme = $membership->theme;

        $label = $theme->labels
            ->first(fn (ThemeLabel $themeLabel): bool => $themeLabel->locale === Locale::French);

        return [
            'key' => $theme->key,
            'label' => $label instanceof ThemeLabel ? $label->label : $theme->key,
            'is_auto' => $membership->is_auto,
            'manual_state' => $membership->manual_state?->value,
            'is_active' => $membership->is_active,
        ];
    }

    /**
     * Une image de la banque — **aucun chemin** (§ 10).
     *
     * `processing_error` est la valeur de `FrameProcessingFailure`, donc une
     * clé de traduction complète, jamais un message brut (spec 20 § 5.6) : le
     * back-office doit rester lisible par un non-technicien. `is_retryable`
     * dit si « Relancer » est offert : seulement sur un échec rejouable
     * (`FrameProcessingFailure::isRetryable()`) ; sinon l'écran propose
     * re-recadrer ou écarter.
     *
     * @return array{frame_level: int, availability: string, processing_state: string, processing_error: string|null, is_retryable: bool}
     */
    public static function movieFrame(Frame $frame): array
    {
        return [
            'frame_level' => $frame->frame_level->value,
            'availability' => $frame->availability->value,
            'processing_state' => $frame->processing_state->value,
            'processing_error' => $frame->processing_error?->value,
            'is_retryable' => $frame->processing_state === FrameProcessingState::Failed
                && $frame->processing_error?->isRetryable() === true,
        ];
    }

    /**
     * Une ligne de journal d'import.
     *
     * `is_queued` n'est **pas** une valeur d'enum — `import_run.status` n'en a
     * que trois et le schéma est clos. « En file » est le couple
     * `status = running` ET `started_at = null`, c'est-à-dire l'intervalle
     * pendant lequel aucun worker n'a encore pris le travail. L'écran le rend
     * par `admin.enum.import_run_status.queued`, et signale l'absence de worker
     * quand cet état dure.
     *
     * `actor_name` est nul quand le compte a disparu : `actor_id` est
     * `nullOnDelete`, et la traçabilité d'un balayage ne dépend pas de la survie
     * d'un compte.
     *
     * @return array{
     *     id: int,
     *     run_kind: string,
     *     status: string,
     *     actor_name: string|null,
     *     is_widened: bool,
     *     filter_min_vote_count: int|null,
     *     filter_languages: string|null,
     *     filter_min_release_year: int|null,
     *     total_seen: int,
     *     total_imported: int,
     *     total_skipped: int,
     *     total_refused_content: int,
     *     started_at: string|null,
     *     finished_at: string|null,
     *     created_at: string|null,
     *     is_queued: bool,
     * }
     */
    public static function importRunRow(ImportRun $run): array
    {
        return [
            'id' => $run->id,
            'run_kind' => $run->run_kind->value,
            'status' => $run->status->value,
            'actor_name' => $run->actor?->name,
            'is_widened' => $run->is_widened,
            'filter_min_vote_count' => $run->filter_min_vote_count,
            'filter_languages' => $run->filter_languages,
            'filter_min_release_year' => $run->filter_min_release_year,
            'total_seen' => $run->total_seen,
            'total_imported' => $run->total_imported,
            'total_skipped' => $run->total_skipped,
            'total_refused_content' => $run->total_refused_content,
            'started_at' => self::moment($run->started_at),
            'finished_at' => self::moment($run->finished_at),
            'created_at' => self::moment($run->created_at),
            'is_queued' => self::isQueued($run),
        ];
    }

    /**
     * Le détail d'un balayage : la ligne de journal, plus l'état reprenable.
     *
     * @return array<string, mixed>
     */
    public static function importRunDetail(ImportRun $run): array
    {
        return [
            ...self::importRunRow($run),
            'tmdb_page_cursor' => $run->tmdb_page_cursor,
            'last_request_at' => self::moment($run->last_request_at),
        ];
    }

    /**
     * « En file » : le travail est ouvert, mais aucun worker ne l'a pris.
     */
    public static function isQueued(ImportRun $run): bool
    {
        return $run->status === ImportRunStatus::Running && $run->started_at === null;
    }

    /**
     * Un balayage reprenable depuis l'écran. **Seuls les `discover` le sont** :
     * la liste collée d'un `paste` n'est stockée nulle part — aucune colonne de
     * la spec 10 ne la porte, et en inventer une serait empiéter sur le
     * propriétaire du schéma. Le bouton est donc rendu désactivé avec son
     * motif, jamais absent en silence. Question renvoyée à la spec 20.
     */
    public static function isResumable(ImportRun $run): bool
    {
        return $run->run_kind === ImportRunKind::Discover
            && $run->status === ImportRunStatus::Running
            // Un balayage « en file » (`started_at = null`) n'est pas à
            // reprendre, il est à ATTENDRE : le job dort déjà dans la file et
            // `ShouldBeUnique` avalerait silencieusement un second dispatch,
            // pendant que l'écran annoncerait « balayage repris ». Un message
            // d'état qui ment est exactement ce que la décision 9 proscrit.
            && $run->started_at !== null;
    }

    /**
     * `{ data, meta }`, explicitement — **jamais** le tableau `links` d'un
     * paginateur, qui embarque « Previous » et « Next » en anglais dans une
     * charge utile qu'aucune clé de `lang/fr/admin.php` ne peut corriger.
     *
     * @template TModel of Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $paginator
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null},
     * }
     */
    public static function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = [];

        foreach ($paginator->items() as $item) {
            $data[] = $map($item);
        }

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * La projection d'un film, **nullable pour de vrai**.
     *
     * `$movie->projection` est typé NON NUL par l'extension Eloquent — une
     * `HasOne` rend toujours le modèle lié, de son point de vue — alors que la
     * ligne peut manquer : un film restauré avant `catalog:reproject` n'en a
     * pas, et l'écran doit afficher zéro plutôt que de tomber.
     * La relation est chargée si elle ne l'est pas déjà — les appelants de ce
     * lot la chargent tous d'avance, donc aucune requête supplémentaire et aucun
     * N+1 — puis lue dans le TABLEAU des relations, qui rend `mixed` et laisse le
     * rétrécissement se faire par `instanceof`, sans annotation qui mentirait
     * dans l'autre sens.
     */
    public static function projectionOf(Movie $movie): ?MovieProjection
    {
        if (! $movie->relationLoaded('projection')) {
            $movie->load('projection');
        }

        $projection = $movie->getRelations()['projection'] ?? null;

        return $projection instanceof MovieProjection ? $projection : null;
    }

    /**
     * Un instant, en ISO-8601 — jamais une chaîne pré-formatée côté serveur
     * (règle 4) : la mise en forme d'une date est un fait d'affichage, et le
     * back-office est déjà le seul endroit où une locale est imposée.
     */
    private static function moment(?CarbonImmutable $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
