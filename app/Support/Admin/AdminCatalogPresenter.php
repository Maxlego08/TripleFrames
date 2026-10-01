<?php

namespace App\Support\Admin;

use App\Actions\Curation\SetMovieGroup;
use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingState;
use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Enums\Locale;
use App\Enums\ReviewDecision;
use App\Enums\TmdbTagKind;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Collection;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\MovieTheme;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\Theme;
use App\Models\ThemeLabel;
use App\Settings\RoomSettingsBounds;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\FrameCurationState;
use App\Support\Curation\ReviewQueue;
use App\Support\Frames\CropRect;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 * sa disponibilité et l'état de son job. `frame.id` lui-même n'est pas exposé
 * par la fiche : il est `#[Hidden]` (§ 4.1), un identifiant séquentiel
 * permettant de regrouper les images d'un même film, et la fiche est en
 * lecture seule. Seul l'éditeur de la banque, qui adresse ses gestes par cet
 * identifiant, le reçoit ({@see self::bankFrame()}, E10-11) — jamais une
 * surface joueur.
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
     * Une ligne de la file de curation (spec 20 § 4.1) : la ligne de liste, son
     * rang dans la file filtrée, et l'instant de sa dernière retouche — `null`
     * pour un film non entamé, qui n'a encore aucune image hors des écartées.
     *
     * @return array<string, mixed>
     */
    public static function curationQueueRow(Movie $movie, ?CarbonImmutable $touchedAt, int $rank): array
    {
        return [
            ...self::movieRow($movie),
            'rank' => $rank,
            'is_started' => $touchedAt !== null,
            'touched_at' => self::moment($touchedAt),
        ];
    }

    /**
     * Un film écarté (spec 20 § 4.2), tel que le tableau de bord le liste : la
     * ligne de liste, le motif — obligatoire au geste, relu tel quel — et
     * l'instant où il a été écarté.
     *
     * @return array<string, mixed>
     */
    public static function setAsideRow(Movie $movie): array
    {
        return [
            ...self::movieRow($movie),
            'availability_reason' => $movie->availability_reason,
            'availability_changed_at' => self::moment($movie->availability_changed_at),
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

        // Les bornes de `N` sont celles des réglages de salon, jamais un
        // littéral (règle 2, n° 26).
        foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
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
     * `id` adresse le geste « Retirer » (spec 20 § 9.2), vers le back-office
     * seulement ; `created_by` est le NOM de l'auteur d'un alias curé, nul pour
     * un alias TMDB.
     *
     * @return array{id: int, locale: string, alias: string, origin: string, created_by: string|null}
     */
    public static function movieAlias(Alias $alias): array
    {
        return [
            'id' => $alias->id,
            'locale' => $alias->locale,
            'alias' => $alias->alias,
            'origin' => $alias->origin->value,
            'created_by' => $alias->createdBy?->name,
        ];
    }

    /**
     * Une forme acceptée du film, en lecture seule (spec 20 § 9.2) : la forme
     * normalisée, sa nature, et son ambiguïté — ce qui rend un alias
     * vérifiable par un non-technicien.
     *
     * @return array{form: string, kind: string, is_ambiguous: bool}
     */
    public static function answerKey(AnswerKey $key): array
    {
        return [
            'form' => (string) $key->normalized,
            'kind' => $key->key_kind->value,
            'is_ambiguous' => $key->is_ambiguous,
        ];
    }

    /**
     * Le groupe « même œuvre » d'un film (spec 20 § 9.4) : libellé interne,
     * note, auteur, et ses films — jamais montré à un joueur.
     *
     * @param  EloquentCollection<int, Movie>  $members
     * @return array{id: int, label: string, note: string|null, created_by: string|null, created_at: string|null, movies: list<array{id: int, title_original: string, release_year: int|null, availability: string}>}
     */
    public static function movieGroup(MovieGroup $group, EloquentCollection $members): array
    {
        $movies = [];

        foreach ($members as $member) {
            $movies[] = self::movieIdentity($member);
        }

        return [
            'id' => $group->id,
            'label' => $group->label,
            'note' => $group->note,
            'created_by' => $group->createdBy?->name,
            'created_at' => self::moment($group->created_at),
            'movies' => $movies,
        ];
    }

    /**
     * Un candidat exact au regroupement (spec 20 § 9.4) : un film au titre
     * normalisé identique, son groupe éventuel, et le libellé que le
     * regroupement pré-remplirait.
     *
     * @return array{id: int, title_original: string, release_year: int|null, availability: string, group_label: string|null, same_group: bool, default_label: string}
     */
    public static function groupCandidate(Movie $movie, Movie $candidate): array
    {
        return [
            ...self::movieIdentity($candidate),
            'group_label' => $candidate->group?->label,
            'same_group' => $movie->group_id !== null && $candidate->group_id === $movie->group_id,
            'default_label' => SetMovieGroup::defaultLabel($movie, $candidate),
        ];
    }

    /**
     * L'identité courte d'un film cité par un autre écran que sa fiche.
     *
     * @return array{id: int, title_original: string, release_year: int|null, availability: string}
     */
    private static function movieIdentity(Movie $movie): array
    {
        return [
            'id' => $movie->id,
            'title_original' => $movie->title_original,
            'release_year' => $movie->release_year,
            'availability' => $movie->availability->value,
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
     * Une étiquette TMDB **brute**, et le nom TMDB de la société quand
     * `tmdb_company` le connaît (§ 3.6 bis, D43 du 01/10). Un genre n'a jamais
     * de nom : son libellé est celui de son thème (§ 3.6).
     *
     * @return array{tag_kind: string, tmdb_tag_id: int, name: string|null}
     */
    public static function movieTag(MovieTmdbTag $tag, ?string $name): array
    {
        return [
            'tag_kind' => $tag->tag_kind->value,
            'tmdb_tag_id' => $tag->tmdb_tag_id,
            'name' => $tag->tag_kind === TmdbTagKind::Company ? $name : null,
        ];
    }

    /**
     * Une appartenance thématique : la règle automatique, l'exception manuelle
     * et l'appartenance **effective**, séparées (§ 3.7), avec ce qu'il faut au
     * bloc « Thèmes » de la fiche pour porter un geste (spec 20 § 9.6, D43 du
     * 01/10) : l'identifiant du thème, sa nature et sa publication.
     *
     * Le thème est passé à part, pris dans la liste des thèmes disponibles
     * déjà chargée avec ses libellés : aucune requête par appartenance.
     *
     * @return array{theme_id: int, key: string, label: string, kind: string, is_published: bool, is_auto: bool, manual_state: string|null, is_active: bool}
     */
    public static function movieTheme(MovieTheme $membership, Theme $theme): array
    {
        return [
            'theme_id' => $theme->id,
            'key' => $theme->key,
            'label' => self::themeLabel($theme),
            'kind' => $theme->theme_kind->value,
            'is_published' => $theme->is_published,
            'is_auto' => $membership->is_auto,
            'manual_state' => $membership->manual_state?->value,
            'is_active' => $membership->is_active,
        ];
    }

    /**
     * Un thème du sélecteur « Ajouter un thème » de la fiche : tous, publiés
     * ou non (spec 20 § 9.6).
     *
     * @return array{id: int, key: string, label: string, kind: string, is_published: bool}
     */
    public static function availableTheme(Theme $theme): array
    {
        return [
            'id' => $theme->id,
            'key' => $theme->key,
            'label' => self::themeLabel($theme),
            'kind' => $theme->theme_kind->value,
            'is_published' => $theme->is_published,
        ];
    }

    /**
     * La collection TMDB du film, et le thème de saga qui la désigne s'il
     * existe : sans lui, la fiche offre « Créer la saga depuis cette
     * collection » (spec 20 § 9.6).
     *
     * @return array{id: int, name: string, saga: array{id: int, key: string, label: string, is_published: bool}|null}
     */
    public static function movieCollection(Collection $collection, ?Theme $saga): array
    {
        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'saga' => $saga === null ? null : [
                'id' => $saga->id,
                'key' => $saga->key,
                'label' => self::themeLabel($saga),
                'is_published' => $saga->is_published,
            ],
        ];
    }

    /**
     * Le libellé d'un thème dans la locale du back-office — le français,
     * forcé par `ForceAdminLocale` —, ou sa clé technique quand il manque :
     * un thème sans libellé n'est de toute façon pas publiable.
     */
    private static function themeLabel(Theme $theme): string
    {
        $label = $theme->labels
            ->first(fn (ThemeLabel $themeLabel): bool => $themeLabel->locale === Locale::French);

        return $label instanceof ThemeLabel ? $label->label : $theme->key;
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
     * Une image de l'éditeur de la banque — `AdminMovieFrame` étendu (contrat
     * C9, spec 20 § 5.8) : la ligne de la fiche, plus ce que les gestes de
     * l'éditeur exigent.
     *
     * - `id` : **admin seulement** (E10-11) — c'est l'adresse des gestes et de
     *   l'aperçu (`scopeBindings`) ; il ne sort jamais vers une surface joueur ;
     * - `crop` : le rectangle dans l'espace du master, que le re-recadrage
     *   rouvre tel quel ;
     * - `game_url` / `master_url` : l'aperçu admin (C9-bis), `null` tant que
     *   le job n'a rien produit (`game_path` NULL) et pour une image retirée,
     *   que `FramePolicy::view` refuse ;
     * - `review_outdated` : image en jeu revue sous une version antérieure de
     *   la grille — la file « à re-revoir » (§ 7.7) ;
     * - `curation_state` : l'état affiché, DÉRIVÉ
     *   ({@see FrameCurationState}), jamais stocké ;
     * - `review_rejected` : sa dernière revue qui la juge encore est rejetée,
     *   quelle que soit sa disponibilité — le seul signal d'une image en jeu
     *   rejetée en re-revue, qui reste `in_play` jusqu'à décision (§ 7.5).
     *
     * **Aucun chemin disque, ni `source_hash`, ni `published_hash`, ni
     * `tmdb_file_path`** (C9 § 3) : le lien d'une image à son visuel TMDB ne
     * sort que par les niveaux `used_levels` de ce visuel, calculés côté
     * serveur.
     *
     * @return array{
     *     id: int,
     *     frame_level: int,
     *     availability: string,
     *     processing_state: string,
     *     processing_error: string|null,
     *     is_retryable: bool,
     *     source_kind: string,
     *     crop: array{x: int, y: int, width: int, height: int},
     *     game_url: string|null,
     *     master_url: string|null,
     *     review_outdated: bool,
     *     curation_state: string,
     *     review_rejected: bool,
     * }
     */
    public static function bankFrame(Frame $frame, FrameCurationState $state, bool $reviewRejected): array
    {
        $viewable = $frame->game_path !== null
            && $frame->availability !== ContentAvailability::Withdrawn;

        $parameters = ['movie' => $frame->movie_id, 'frame' => $frame->id];

        return [
            'id' => $frame->id,
            ...self::movieFrame($frame),
            'source_kind' => $frame->source_kind->value,
            'crop' => CropRect::fromFrame($frame)->toArray(),
            'game_url' => $viewable ? route('admin.catalog.frames.game', $parameters) : null,
            'master_url' => $viewable ? route('admin.catalog.frames.master', $parameters) : null,
            'review_outdated' => $frame->availability === ContentAvailability::Published
                && ExclusionGrid::isOutdated($frame->review_grid_version),
            'curation_state' => $state->value,
            'review_rejected' => $reviewRejected,
        ];
    }

    /**
     * Le film d'un groupe de la file de revue (spec 20 § 7.3, § 7.4) : ce que
     * l'écran affiche à côté de l'image — titre original, année.
     *
     * @return array{id: int, title_original: string, title_original_latin: string|null, release_year: int|null}
     */
    public static function reviewMovie(Movie $movie): array
    {
        return [
            'id' => $movie->id,
            'title_original' => $movie->title_original,
            'title_original_latin' => $movie->title_original_latin,
            'release_year' => $movie->release_year,
        ];
    }

    /**
     * Une image de la file de revue — les props de revue du contrat C14-bis
     * § 3, plus ce que les listes de l'écran exigent.
     *
     * - `published_hash` : **admin seulement** — l'empreinte des octets
     *   affichés, que l'envoi rend en `reviewed_hash` ; comparée sous verrou,
     *   elle refuse une revue d'octets remplacés entre-temps ;
     * - `game_url` : l'aperçu admin du rendu FINAL (C9-bis), jamais l'aperçu
     *   de recadrage (§ 7.4), **versionné par `published_hash`** (paramètre
     *   `v`, ignoré par `FrameImageController`) : l'adresse change avec les
     *   octets. Sans cela, un re-recadrage terminé entre l'affichage et
     *   l'envoi laisserait l'ancien rendu à l'écran — même `src`, donc aucun
     *   rechargement de l'`<img>`, et la liste des images disponibles du
     *   document réutilise l'image déjà chargée malgré `no-store` — pendant
     *   que le champ caché `reviewed_hash` porterait la nouvelle empreinte :
     *   le curateur publierait des octets qu'il n'a jamais vus ;
     * - `grid_version` : {@see ExclusionGrid::CURRENT_VERSION} ;
     * - `items` : les items applicables à ce niveau, et leurs deux clés ;
     * - `declared_source` : le chemin du visuel TMDB ou le timecode `h:mm:ss`
     *   d'une capture, affiché en lecture seule et confirmé par l'envoi
     *   (§ 7.6) — jamais un support ni un outil (A7) ;
     * - `failed_items` : les items en défaut de la revue rejetée qui la juge
     *   encore — vide hors de la liste « Rejetées » ;
     * - `id`, `movie_id` : adresse des gestes, back-office seulement (E10-11).
     *
     * @return array{
     *     id: int,
     *     movie_id: int,
     *     frame_level: int,
     *     availability: string,
     *     published_hash: string,
     *     game_url: string,
     *     grid_version: int,
     *     items: list<array{slug: string, label_key: string, help_key: string}>,
     *     declared_source: array{kind: string, reference: string},
     *     failed_items: list<string>,
     * }
     */
    public static function reviewFrame(Frame $frame, ?FrameReview $judging): array
    {
        $items = [];

        foreach (ExclusionGrid::slugsFor($frame->frame_level) as $slug) {
            $items[] = [
                'slug' => $slug,
                'label_key' => ExclusionGrid::labelKey($slug),
                'help_key' => ExclusionGrid::helpKey($slug),
            ];
        }

        $publishedHash = (string) $frame->published_hash;

        return [
            'id' => $frame->id,
            'movie_id' => $frame->movie_id,
            'frame_level' => $frame->frame_level->value,
            'availability' => $frame->availability->value,
            'published_hash' => $publishedHash,
            'game_url' => route('admin.catalog.frames.game', [
                'movie' => $frame->movie_id,
                'frame' => $frame->id,
                'v' => $publishedHash,
            ]),
            'grid_version' => ExclusionGrid::CURRENT_VERSION,
            'items' => $items,
            'declared_source' => ReviewQueue::declaredSource($frame),
            'failed_items' => $judging?->decision === ReviewDecision::Rejected
                ? ReviewQueue::failedItems($judging)
                : [],
        ];
    }

    /**
     * Le lot d'un film à valider en une fois (D42 du 30/09, spec 20 § 7.9),
     * ou `null` s'il n'y a rien à valider — le bouton est alors absent —, ou
     * si le lot dépasse {@see ReviewQueue::BATCH_MAX_FRAMES} : l'envoi serait
     * toujours refusé, le bouton n'est donc pas proposé.
     *
     * - `frames` : chaque image du lot, `{ id, hash }` — l'identifiant et
     *   l'empreinte des octets affichés, **admin seulement**, que l'envoi
     *   rend tels quels : le serveur refuse tout le lot si la liste ou une
     *   empreinte a changé ;
     * - `grid_version` : {@see ExclusionGrid::CURRENT_VERSION}, la grille dont
     *   chaque item sera enregistré « rien à signaler ».
     *
     * @param  list<Frame>  $frames
     * @return array{grid_version: int, frames: list<array{id: int, hash: string}>}|null
     */
    public static function reviewBatch(array $frames): ?array
    {
        if ($frames === [] || count($frames) > ReviewQueue::BATCH_MAX_FRAMES) {
            return null;
        }

        return [
            'grid_version' => ExclusionGrid::CURRENT_VERSION,
            'frames' => array_map(static fn (Frame $frame): array => [
                'id' => $frame->id,
                'hash' => (string) $frame->published_hash,
            ], $frames),
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
            'added_themes' => self::importRunThemes($run),
            'total_themes_applied' => $run->total_themes_applied,
            'total_themes_kept_removed' => $run->total_themes_kept_removed,
        ];
    }

    /**
     * Les thèmes choisis au collage (D43 du 01/10), dans l'ordre écrit sur la
     * ligne — une requête pour les thèmes et leurs libellés, `[]` sans
     * sélection. Un thème disparu est ignoré. Le libellé suit la règle de
     * {@see self::themeLabel()} : la locale du back-office, sinon la clé.
     *
     * @return list<array{id: int, key: string, label: string, kind: string, is_published: bool}>
     */
    public static function importRunThemes(ImportRun $run): array
    {
        $ids = $run->addedThemeIds();

        if ($ids === []) {
            return [];
        }

        $themes = Theme::query()->with('labels')->whereKey($ids)->get()->keyBy('id');

        $rows = [];

        foreach ($ids as $id) {
            $theme = $themes->get($id);

            if ($theme instanceof Theme) {
                $rows[] = self::availableTheme($theme);
            }
        }

        return $rows;
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
