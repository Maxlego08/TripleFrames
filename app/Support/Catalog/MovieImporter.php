<?php

namespace App\Support\Catalog;

use App\Actions\Curation\SetMovieThemeMembership;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ContentOrigin;
use App\Enums\ImportRunKind;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Enums\TmdbTagKind;
use App\Models\Alias;
use App\Models\Collection;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\Theme;
use App\Models\TmdbCompany;
use App\Models\User;
use App\Support\Tmdb\TmdbMovie;
use App\Support\Tmdb\TmdbMovieSummary;
use App\Support\Tmdb\TmdbTitle;
use App\Support\Tmdb\TmdbTitleKind;
use App\ValueObjects\Catalog\ExceptionMotives;
use App\ValueObjects\Catalog\ImportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Le service d'import — il applique les filtres, déduplique, écrit le
 * catalogue, et ne parle jamais à TMDB.
 *
 * **L'asymétrie des deux voies est tout entière ici** (§ 9.2, décision 11) :
 *
 * | | Balayage `discover` | Voie d'exception (`paste`) |
 * |---|---|---|
 * | Filtre de **goût** | appliqué | **ignoré entièrement** |
 * | Filtre de **contenu** | appliqué | **appliqué — jamais contournable** |
 * | `is_import_exception` | `false`, sauf si `is_widened` | **`true` toujours** |
 *
 * Les trois motifs se mesurent toujours contre le filtre **par défaut**, jamais
 * contre le filtre appliqué : sur un balayage élargi, un motif dit pourquoi le
 * film ne serait pas entré par la voie ordinaire.
 *
 * **Ce que l'import n'écrit jamais** : aucune `frame`. La curation des images
 * est un acte humain (spec 20), et un film fraîchement importé a
 * `levels_count = 0`, donc n'entre dans aucun vivier. Aucune publication non
 * plus : `availability` reste `draft`, valeur par défaut de la table.
 *
 * **Aucun appel TMDB pendant une partie** (règle 6) : rien ici n'est
 * atteignable depuis une route de joueur.
 */
final class MovieImporter
{
    /**
     * Les pays dont un titre alternatif sans `iso_639_1` se laisse rattacher à
     * une locale de catalogue **sans ambiguïté**.
     *
     * `alternative_titles` porte un pays et non une langue. CA, CH et BE sont
     * volontairement absents : leur titre peut être dans l'une ou l'autre de
     * leurs langues, et un alias rangé sous la mauvaise locale serait accepté
     * en réponse sans que personne ne sache pourquoi. Un titre non rattachable
     * est **ignoré** — un alias qui manque se rattrape en curation, un alias
     * mal étiqueté fausse `answer_key` et la couverture par langue.
     *
     * @var array<string, string>
     */
    private const array COUNTRY_TO_LOCALE = [
        'FR' => 'fr',
        'US' => 'en',
        'GB' => 'en',
        'JP' => 'ja',
    ];

    /**
     * Le `type` d'`alternative_titles` qui porte la translittération latine.
     * Elle devient `movie.title_original_latin` et **jamais** un alias (A4).
     */
    private const string ROMAJI_TYPE = 'romaji';

    /**
     * Tentatives de la transaction d'un film sur un interblocage MySQL : les
     * lignes `movie_theme` qu'elle verrouille (évaluation, thèmes du collage)
     * peuvent croiser un `syncTheme()`. La transaction est la plus externe, et
     * son écriture repart de zéro : Laravel la rejoue.
     */
    private const int DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly AnswerKeyProjector $answerKeys,
        private readonly MovieProjector $projection,
        private readonly ThemeEvaluator $themes,
        private readonly SetMovieThemeMembership $membership,
    ) {}

    /**
     * Le tri fait **avant** l'appel de détail, sur la seule fiche de balayage.
     *
     * Rend un verdict terminal quand il y en a un, `null` quand il faut lire la
     * fiche complète. C'est ce qui économise le quota : un film écarté par le
     * filtre de goût, déjà en base ou déclaré `adult` par `discover` lui-même ne
     * coûte aucun appel supplémentaire.
     *
     * Les certifications, elles, ne sont **jamais** lues ici : `discover` ne les
     * rend pas, et `certification.lte` écarterait silencieusement les films non
     * classifiés dans le pays demandé (§ 9.2).
     */
    public function screen(
        TmdbMovieSummary $summary,
        ImportRun $run,
        ImportFilter $applied,
        ?Movie $existing,
    ): ?ImportOutcome {
        if ($existing instanceof Movie) {
            return $this->outcomeForExisting($existing, $run);
        }

        // Le drapeau `adult` est le seul morceau de filtre de contenu que
        // `discover` rende. Il refuse par les DEUX voies, décision 12.
        if ($summary->adult) {
            return ImportOutcome::refusedContent(
                $summary->tmdbId,
                'admin.catalog.import.refused.adult',
            );
        }

        if ($run->run_kind === ImportRunKind::Discover && ! $applied->accepts(
            $summary->originalLanguage,
            $summary->voteCount,
            $summary->releaseYear(),
        )) {
            return ImportOutcome::skippedByFilter(
                $summary->tmdbId,
                ImportFilter::default()->exceptionMotivesFor(
                    $summary->originalLanguage,
                    $summary->voteCount,
                    $summary->releaseYear(),
                ),
            );
        }

        return null;
    }

    /**
     * Importe une fiche complète. Tout le catalogue d'un film — sa ligne, sa
     * projection, ses titres, ses alias, ses étiquettes, ses certifications et
     * ses clés de réponse — est écrit dans **une seule** transaction : le § 3.2
     * exige que `movie_projection` naisse avec `movie`, et une projection
     * absente rend le film jouable mais invisible au tirage des leurres.
     */
    public function import(
        TmdbMovie $tmdb,
        ImportRun $run,
        ImportFilter $applied,
        bool $dryRun = false,
    ): ImportOutcome {
        $existing = Movie::query()->where('tmdb_id', $tmdb->tmdbId)->first();

        if ($existing instanceof Movie) {
            $outcome = $this->outcomeForExisting($existing, $run);

            if ($outcome !== null) {
                return $outcome;
            }

            return $dryRun
                ? ImportOutcome::resynchronized($existing)
                : $this->resynchronize($existing, $tmdb);
        }

        $gate = ContentGate::inspect($tmdb);

        // Le filtre de contenu, avant tout le reste et pour les deux voies.
        if ($gate->isRefused()) {
            $reason = $gate->refusalReason() ?? [
                'key' => 'admin.catalog.import.refused.adult',
                'replacements' => [],
            ];

            return ImportOutcome::refusedContent($tmdb->tmdbId, $reason['key'], $reason['replacements']);
        }

        $motives = ImportFilter::default()->exceptionMotivesFor(
            $tmdb->originalLanguage,
            $tmdb->voteCount,
            $tmdb->releaseYear(),
        );

        // Le filtre de goût ne gouverne QUE le balayage. C'est l'asymétrie qui
        // fait entrer Parasite, Le Labyrinthe de Pan et Blanche-Neige.
        if ($run->run_kind === ImportRunKind::Discover && ! $applied->accepts(
            $tmdb->originalLanguage,
            $tmdb->voteCount,
            $tmdb->releaseYear(),
        )) {
            return ImportOutcome::skippedByFilter($tmdb->tmdbId, $motives);
        }

        $isException = $this->isImportException($run);

        if ($dryRun) {
            return ImportOutcome::simulated($tmdb->tmdbId, $motives, $isException);
        }

        /** @var array{applied: int, kept_removed: int} $themeCounts */
        $themeCounts = ['applied' => 0, 'kept_removed' => 0];

        /** @var Movie $movie */
        $movie = DB::transaction(function () use ($tmdb, $run, $gate, $motives, $isException, &$themeCounts): Movie {
            $movie = $this->create($tmdb, $run, $gate, $motives, $isException);

            // Les thèmes choisis au collage, dans la MÊME transaction, après
            // l'évaluation automatique de `create()` (spec 30 § 13.1).
            $themeCounts = $this->applyRunThemes($movie, $run);

            return $movie;
        }, self::DEADLOCK_ATTEMPTS);

        // Compté après le commit seulement : un import annulé ne compte rien.
        $this->tallyRunThemes($run, $themeCounts);

        return ImportOutcome::imported($movie, $motives, $isException);
    }

    /**
     * Les films déjà connus, lus **en lot** par l'unique `movie_tmdb_uq`
     * (§ 9.2). Le lot vient de `config('catalog.import.deduplication_chunk')`,
     * qui borne la taille du `IN (…)` d'un collage de plusieurs centaines
     * d'identifiants.
     *
     * @param  list<int>  $tmdbIds
     * @return array<int, Movie> Indexé par `tmdb_id`.
     */
    public function existingByTmdbId(array $tmdbIds): array
    {
        $chunk = max(1, Config::integer('catalog.import.deduplication_chunk', 200));

        /** @var array<int, Movie> $found */
        $found = [];

        foreach (array_chunk(array_values(array_unique($tmdbIds)), $chunk) as $slice) {
            foreach (Movie::query()->whereIn('tmdb_id', $slice)->get() as $movie) {
                if ($movie->tmdb_id !== null) {
                    $found[$movie->tmdb_id] = $movie;
                }
            }
        }

        return $found;
    }

    /**
     * Accumule les quatre compteurs de `import_run` **en mémoire**. La commande
     * enregistre à chaque page, ce qui fait de la reprise une propriété du
     * couple (curseur, compteurs) et non d'un `UPDATE` par film.
     */
    public function journal(ImportRun $run, ImportOutcome $outcome): void
    {
        foreach ($outcome->countersFor() as $column => $increment) {
            if ($increment === 0) {
                continue;
            }

            /** @var int $current */
            $current = $run->getAttribute($column);
            $run->setAttribute($column, $current + $increment);
        }
    }

    /**
     * Les thèmes choisis au collage (`import_run.added_theme_ids`, spec 20
     * § 3.3, D43 du 01/10), posés en exception `added` sur `$movie` — **dans
     * la transaction ouverte de l'appelant**, celle de l'import du film ou,
     * pour un film déjà présent, celle que la commande ouvre pour lui.
     *
     * Par {@see SetMovieThemeMembership::applyPasteAddition()}, seul écrivain
     * de l'exception, signée de `import_run.actor_id` (critique C6) :
     * - un `removed` posé par un curateur n'est jamais changé en `added` —
     *   compté dans `kept_removed` ;
     * - une ligne déjà active (par la règle ou par un ajout) n'est pas
     *   touchée, et n'est pas comptée.
     *
     * Rien hors d'un collage (`discover`, `resync`) ni sans sélection. Les
     * thèmes sont relus à chaque appel, jamais mis en cache sur le balayage ;
     * un thème disparu est ignoré. Ordre par identifiant : deux collages
     * concurrents verrouillent les lignes dans le même ordre.
     *
     * Aucune ligne de journal par film (exception assumée à D41, spec 20
     * § 2.7) : le collage est journalisé une fois, à son ouverture.
     *
     * @return array{applied: int, kept_removed: int}
     */
    public function applyRunThemes(Movie $movie, ImportRun $run): array
    {
        $counts = ['applied' => 0, 'kept_removed' => 0];

        $themeIds = $run->run_kind === ImportRunKind::Paste ? $run->addedThemeIds() : [];

        if ($themeIds === []) {
            return $counts;
        }

        // La policy est relue à CHAQUE écriture, pour l'auteur du collage et
        // ce film (CLAUDE.md § 5) : un auteur supprimé (`actor_id` passé à
        // NULL), rétrogradé en cours de balayage, ou un film qui ne se cure
        // plus (retiré) ne reçoit aucune exception — jamais une exception
        // signée de personne ni d'un compte qui n'a plus le droit de la poser.
        $actor = $run->actor_id === null ? null : User::query()->find($run->actor_id);

        if (! $actor instanceof User || Gate::forUser($actor)->denies('curate', $movie)) {
            return $counts;
        }

        $at = CarbonImmutable::now();

        $themes = Theme::query()->whereKey($themeIds)->orderBy('id')->get();

        foreach ($themes as $theme) {
            $result = $this->membership->applyPasteAddition($movie, $theme, $actor->id, $at);

            if ($result === SetMovieThemeMembership::PASTE_APPLIED) {
                $counts['applied']++;
            } elseif ($result === SetMovieThemeMembership::PASTE_KEPT_REMOVED) {
                $counts['kept_removed']++;
            }
        }

        return $counts;
    }

    /**
     * Les thèmes d'un collage appliqués à un film DÉJÀ PRÉSENT (issue
     * `duplicate`), dans une transaction à lui : le film est relu sous
     * verrou — même ordre que le geste de la fiche, film puis ligne — et un
     * film retiré entre-temps ne reçoit rien. Les deux compteurs du balayage
     * sont accumulés en mémoire après le commit, comme les quatre autres.
     */
    public function applyRunThemesToExisting(Movie $movie, ImportRun $run): void
    {
        if ($run->run_kind !== ImportRunKind::Paste || $run->addedThemeIds() === []) {
            return;
        }

        /** @var array{applied: int, kept_removed: int} $counts */
        $counts = DB::transaction(function () use ($movie, $run): array {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->first();

            // Un film disparu ne reçoit rien ; un film retiré entre-temps est
            // refusé par la policy `curate`, relue sur la ligne verrouillée.
            if (! $locked instanceof Movie) {
                return ['applied' => 0, 'kept_removed' => 0];
            }

            return $this->applyRunThemes($locked, $run);
        }, self::DEADLOCK_ATTEMPTS);

        $this->tallyRunThemes($run, $counts);
    }

    /**
     * Accumule les deux compteurs de thèmes **en mémoire**, comme
     * {@see self::journal()} les quatre autres : la commande les enregistre
     * avec eux.
     *
     * @param  array{applied: int, kept_removed: int}  $counts
     */
    private function tallyRunThemes(ImportRun $run, array $counts): void
    {
        if ($counts['applied'] > 0) {
            $run->total_themes_applied += $counts['applied'];
        }

        if ($counts['kept_removed'] > 0) {
            $run->total_themes_kept_removed += $counts['kept_removed'];
        }
    }

    /**
     * Le verdict d'un film déjà en base, ou `null` quand la voie autorise une
     * resynchronisation.
     *
     * Publique parce que la voie de collage n'a **pas** de fiche de balayage :
     * elle n'a qu'un identifiant, et c'est ici qu'elle demande si le film connu
     * se relit, se saute ou se refuse.
     */
    public function outcomeForExisting(Movie $existing, ImportRun $run): ?ImportOutcome
    {
        // Un retrait juridique fait du `tmdb_id` unique le blocage de réimport
        // à lui seul : le motif, l'auteur et l'horodatage sont déjà consignés
        // sur place, aucune table de bannissement (A10).
        if ($existing->availability === ContentAvailability::Withdrawn) {
            return ImportOutcome::refusedWithdrawn($existing);
        }

        // `demo` exclut définitivement un film de la resynchronisation TMDB.
        if ($run->run_kind !== ImportRunKind::Resync || ! $existing->import_source->isResyncable()) {
            return ImportOutcome::duplicate($existing);
        }

        return null;
    }

    /**
     * `is_import_exception` n'est **jamais dérivable** des trois motifs, d'où
     * la quatrième colonne (§ 9.2).
     *
     * - `paste` — toujours vrai, même si le film satisfait tout le filtre : ce
     *   qui est tracé, c'est la **voie**, pas le verdict.
     * - `discover` — faux, sauf sur un balayage élargi, où c'est `is_widened`,
     *   figé au démarrage, qui répond.
     * - `resync` — un film inconnu découvert par une commande de
     *   resynchronisation entre par la voie manuelle, donc vrai.
     */
    private function isImportException(ImportRun $run): bool
    {
        return match ($run->run_kind) {
            ImportRunKind::Discover => $run->is_widened,
            ImportRunKind::Paste, ImportRunKind::Resync => true,
        };
    }

    /**
     * La voie d'entrée écrite sur `movie`. `resync` n'est pas une voie d'entrée :
     * un film qui entre par une commande de resynchronisation est entré par la
     * main d'un humain, donc par `paste`.
     */
    private function importSource(ImportRun $run): ImportSource
    {
        return $run->run_kind === ImportRunKind::Discover
            ? ImportSource::Discover
            : ImportSource::Paste;
    }

    /**
     * L'écriture complète d'un film neuf, dans la transaction de l'appelant.
     */
    private function create(
        TmdbMovie $tmdb,
        ImportRun $run,
        ContentGate $gate,
        ExceptionMotives $motives,
        bool $isException,
    ): Movie {
        $movie = new Movie;

        $movie->forceFill(array_merge($motives->toAttributes(), [
            'tmdb_id' => $tmdb->tmdbId,
            'import_source' => $this->importSource($run),
            'import_run_id' => $run->id,
            'is_import_exception' => $isException,
            'title_original' => mb_substr($tmdb->originalTitle, 0, 255),
            'title_original_latin' => $this->latinTitle($tmdb),
            'original_language' => mb_substr($tmdb->originalLanguage, 0, 8),
            'release_year' => $tmdb->releaseYear(),
            'vote_count' => $tmdb->voteCount,
            'adult' => $tmdb->adult,
            'collection_id' => $this->collectionId($tmdb),
            // `availability` reste `draft` : l'import ne publie jamais, la
            // publication est un geste de curation (spec 20).
            'availability' => ContentAvailability::Draft,
            'content_flag' => $gate->flag,
        ]));

        $movie->save();

        $this->writeTmdbTags($movie, $tmdb);
        $this->writeTmdbCompanies($tmdb);
        $this->writeCertifications($movie, $gate);
        $this->writeTmdbTitles($movie, $tmdb);
        $this->writeTmdbAliases($movie, $tmdb);

        // Appartenance aux thèmes, synchrone et dans la transaction du film
        // (spec 30 § 13.2) : les étiquettes viennent de l'appel de détail.
        $this->themes->syncMovie($movie, tagKeys: $this->tagKeys($tmdb));

        $this->answerKeys->project($movie);
        $this->projection->recompute($movie);

        return $movie;
    }

    /**
     * Resynchronisation manuelle — **liste close** de ce qui est écrasable
     * (§ 9.3).
     *
     * Écrasable : `title_original`, `title_original_latin`, `original_language`,
     * `release_year`, `vote_count`, `adult`, `collection_id`, les lignes
     * `movie_tmdb_tag`, les lignes `movie_certification`, les seules lignes
     * `movie_title` et `alias` d'`origin = 'tmdb'`, plus `movie_projection`,
     * `answer_key` et les lignes `movie_theme` automatiques (`is_auto`,
     * jamais `manual_state`), reprojetés librement, et le nom des sociétés du
     * film dans `tmdb_company` (D43 du 01/10).
     *
     * Jamais touché : les lignes `curator`, `movie_difficulty_override`,
     * `group_id`, `is_import_exception` et ses trois motifs, `import_source`,
     * `import_run_id`, `availability` et son motif, `content_verified_by_id` et
     * `content_verified_at`, les colonnes de curation, tout `frame` et tout
     * `frame_review`.
     *
     * **Le filtre d'import n'est jamais réappliqué** : un film marqué exception
     * n'est jamais proposé au retrait pour sa langue, ses votes ou sa date.
     *
     * `content_flag` est le seul verdict que la relecture puisse changer, et
     * seulement sur deux transitions, toutes deux dictées par la lecture :
     *
     *  - vers `blocked`, quand une certification restrictive apparaît après
     *    coup — et cette bascule **propose** une dépublication ;
     *  - de `unrated_pending` vers `clear`, quand le visa manquant au premier
     *    import est désormais connu et non restrictif.
     *
     * `unrated_pending` n'est pas une vérification humaine, c'est le défaut de
     * la colonne : l'y laisser garderait le film hors du vivier pour toujours
     * et obligerait le curateur à poser `content_verified_*`, geste que le
     * § 3.1 réserve à un film sans certification FR ni US connue — la coche
     * deviendrait une signature fausse. **Un `blocked` n'est jamais levé** :
     * `content_verified_*` est dans la colonne « jamais touché », et un import
     * qui le contredirait annulerait une vérification humaine signée.
     */
    private function resynchronize(Movie $movie, TmdbMovie $tmdb): ImportOutcome
    {
        $gate = ContentGate::inspect($tmdb);

        /** @var Movie $refreshed */
        $refreshed = DB::transaction(function () use ($movie, $tmdb, $gate): Movie {
            $movie->forceFill([
                'title_original' => mb_substr($tmdb->originalTitle, 0, 255),
                'title_original_latin' => $this->latinTitle($tmdb),
                'original_language' => mb_substr($tmdb->originalLanguage, 0, 8),
                'release_year' => $tmdb->releaseYear(),
                'vote_count' => $tmdb->voteCount,
                'adult' => $tmdb->adult,
                'collection_id' => $this->collectionId($tmdb),
            ]);

            if ($gate->isRefused()) {
                $movie->content_flag = ContentFlag::Blocked;
            } elseif (
                $movie->content_flag === ContentFlag::UnratedPending
                && $gate->flag === ContentFlag::Clear
            ) {
                // Sortie du défaut de la colonne, et de lui seul : un film importé
                // avant que TMDB ne porte son visa restait hors du vivier à vie
                // alors que sa classification est désormais connue et non
                // restrictive — et le curateur devait cocher `content_verified_*`
                // pour l'en sortir, c'est-à-dire signer une vérification qu'il
                // n'a pas faite. Ce n'est PAS un lever de `blocked`.
                $movie->content_flag = ContentFlag::Clear;
            }

            $movie->save();

            MovieTmdbTag::query()->where('movie_id', $movie->id)->delete();
            $this->writeTmdbTags($movie, $tmdb);
            $this->writeTmdbCompanies($tmdb);

            MovieCertification::query()->where('movie_id', $movie->id)->delete();
            $this->writeCertifications($movie, $gate);

            MovieTitle::query()
                ->where('movie_id', $movie->id)
                ->where('origin', ContentOrigin::Tmdb->value)
                ->delete();
            $this->writeTmdbTitles($movie, $tmdb);

            Alias::query()
                ->where('movie_id', $movie->id)
                ->where('origin', ContentOrigin::Tmdb->value)
                ->delete();
            $this->writeTmdbAliases($movie, $tmdb);

            // `is_auto` réécrit pour tous les thèmes ; `manual_state` jamais
            // touché (spec 30 § 13.1, spec 10 § 9.3).
            $this->themes->syncMovie($movie, tagKeys: $this->tagKeys($tmdb));

            $this->answerKeys->project($movie);
            $this->projection->recompute($movie);

            return $movie;
        });

        return ImportOutcome::resynchronized($refreshed);
    }

    /**
     * Les étiquettes TMDB brutes, genres et sociétés dans la même table : sans
     * elles, deux des cinq discriminants de thème n'ont aucune donnée locale, et
     * publier un thème de genre exigerait un re-balayage du catalogue entier
     * (§ 3.6).
     */
    private function writeTmdbTags(Movie $movie, TmdbMovie $tmdb): void
    {
        /** @var list<array{TmdbTagKind, int}> $tags */
        $tags = [];

        foreach ($tmdb->genreIds as $genreId) {
            $tags[] = [TmdbTagKind::Genre, $genreId];
        }

        foreach ($tmdb->productionCompanyIds as $companyId) {
            $tags[] = [TmdbTagKind::Company, $companyId];
        }

        foreach ($tags as [$kind, $tagId]) {
            $tag = new MovieTmdbTag;
            $tag->movie_id = $movie->id;
            $tag->tag_kind = $kind;
            $tag->tmdb_tag_id = $tagId;
            $tag->save();
        }
    }

    /**
     * Le nom TMDB de chaque société de production du film, déjà dans la
     * réponse de l'appel de détail (spec 10 § 3.6 bis, D43 du 01/10) : insérée
     * si absente, **nom réécrit** sinon — métadonnée TMDB pure, comme les
     * étiquettes. Une société sans nom n'écrit rien : elle reste désignée par
     * son identifiant.
     */
    private function writeTmdbCompanies(TmdbMovie $tmdb): void
    {
        $rows = [];

        foreach ($tmdb->productionCompanyNames as $tmdbId => $name) {
            $rows[] = [
                'tmdb_id' => $tmdbId,
                'name' => mb_substr($name, 0, TmdbCompany::NAME_MAX_LENGTH),
            ];
        }

        if ($rows === []) {
            return;
        }

        TmdbCompany::query()->upsert($rows, ['tmdb_id'], ['name']);
    }

    /**
     * Les clés d'étiquette de l'appel de détail, pour l'évaluateur : aucune
     * relecture de `movie_tmdb_tag` en base à l'import.
     *
     * @return array<string, true>
     */
    private function tagKeys(TmdbMovie $tmdb): array
    {
        $keys = [];

        foreach ($tmdb->genreIds as $genreId) {
            $keys[ThemeEvaluator::tagKey(TmdbTagKind::Genre, $genreId)] = true;
        }

        foreach ($tmdb->productionCompanyIds as $companyId) {
            $keys[ThemeEvaluator::tagKey(TmdbTagKind::Company, $companyId)] = true;
        }

        return $keys;
    }

    /**
     * Au plus une certification par pays, la plus récente ayant déjà gagné dans
     * {@see ContentGate} : la résolution se fait en PHP, jamais en SQL (§ 3.8).
     */
    private function writeCertifications(Movie $movie, ContentGate $gate): void
    {
        $readAt = CarbonImmutable::now();

        foreach ($gate->certificationRows($readAt) as $attributes) {
            $certification = new MovieCertification;
            $certification->movie_id = $movie->id;
            $certification->forceFill($attributes);
            $certification->save();
        }
    }

    /**
     * Les titres affichables, **des seules locales activées**.
     *
     * `movie_title.locale` accepte n'importe quelle locale de catalogue, et un
     * curateur peut donc ajouter `ko` à la main. L'import, lui, s'en tient aux
     * locales activées, pour deux raisons mesurées : `title_locale_mask` ne
     * porte un bit que pour celles-là, et `answer_key` n'accepte que
     * celles-là — importer les cinquante traductions de TMDB écrirait des
     * dizaines de milliers de lignes qu'aucune règle ne lit, tout en gonflant
     * la file « titres manquants » d'un bruit permanent.
     *
     * **Aucun titre n'est jamais recopié d'une langue vers une autre** :
     * l'absence d'une ligne EST l'information (§ 3.4). Une traduction au titre
     * vide ne produit donc aucune ligne.
     */
    private function writeTmdbTitles(Movie $movie, TmdbMovie $tmdb): void
    {
        /** @var list<string> $covered */
        $covered = MovieTitle::query()->where('movie_id', $movie->id)->pluck('locale')->all();

        foreach ($tmdb->titlesOfKind(TmdbTitleKind::Translation) as $translation) {
            $locale = $translation->languageCode;

            if ($locale === null || Locale::tryFrom($locale) === null) {
                continue;
            }

            if (trim($translation->title) === '' || in_array($locale, $covered, true)) {
                continue;
            }

            $covered[] = $locale;

            $title = new MovieTitle;
            $title->movie_id = $movie->id;
            $title->locale = $locale;
            $title->title = mb_substr($translation->title, 0, 255);
            $title->origin = ContentOrigin::Tmdb;
            $title->save();
        }
    }

    /**
     * Les alias d'origine TMDB, à usage exclusif de validation et **jamais
     * affichés**.
     *
     * Le titre `Romaji` n'entre pas ici : il est une propriété de
     * `title_original`, et il vit sur `movie.title_original_latin` (A4).
     *
     * Aucune unicité de texte n'existe sur `alias` — en MySQL
     * `utf8mb4_unicode_ci` « Amelie » et « Amélie » violeraient une contrainte
     * que SQLite laisserait passer (§ 3.4) —, donc le dédoublonnage se fait
     * ici, sur le couple (locale, texte), et le vrai filet reste l'UNIQUE
     * `answer_key_norm_movie_uq`.
     */
    private function writeTmdbAliases(Movie $movie, TmdbMovie $tmdb): void
    {
        /** @var list<string> $seen */
        $seen = Alias::query()
            ->where('movie_id', $movie->id)
            ->get()
            ->map(static fn (Alias $alias): string => $alias->locale.'|'.mb_strtolower($alias->alias))
            ->all();

        foreach ($tmdb->titlesOfKind(TmdbTitleKind::Alternative) as $alternative) {
            if ($this->isRomaji($alternative)) {
                continue;
            }

            $locale = $this->aliasLocale($alternative);
            $text = trim($alternative->title);

            if ($locale === null || $text === '') {
                continue;
            }

            $key = $locale.'|'.mb_strtolower($text);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;

            $alias = new Alias;
            $alias->movie_id = $movie->id;
            $alias->locale = $locale;
            $alias->alias = mb_substr($text, 0, 255);
            $alias->origin = ContentOrigin::Tmdb;
            $alias->save();
        }
    }

    /**
     * La translittération latine, seule source possible étant le titre
     * alternatif de type `Romaji` (A4). Rend `null` quand TMDB n'en fournit
     * aucun — la colonne est nullable, et `answer_key` s'en passe alors.
     */
    private function latinTitle(TmdbMovie $tmdb): ?string
    {
        foreach ($tmdb->titlesOfKind(TmdbTitleKind::Alternative) as $alternative) {
            if ($this->isRomaji($alternative) && trim($alternative->title) !== '') {
                return mb_substr(trim($alternative->title), 0, 255);
            }
        }

        return null;
    }

    private function isRomaji(TmdbTitle $title): bool
    {
        return $title->type !== null && mb_strtolower(trim($title->type)) === self::ROMAJI_TYPE;
    }

    /**
     * La locale de catalogue d'un alias : la langue quand TMDB la donne, sinon
     * le pays lorsqu'il est sans ambiguïté, sinon rien.
     */
    private function aliasLocale(TmdbTitle $title): ?string
    {
        if ($title->languageCode !== null && trim($title->languageCode) !== '') {
            return mb_substr(mb_strtolower(trim($title->languageCode)), 0, 12);
        }

        if ($title->countryCode === null) {
            return null;
        }

        return self::COUNTRY_TO_LOCALE[mb_strtoupper(trim($title->countryCode))] ?? null;
    }

    /**
     * La saga TMDB. Cardinalité 0..1 côté TMDB, donc une colonne et pas un
     * pivot — et à ne jamais confondre avec `group_id`, qui est un geste manuel
     * et ne survit que parce que rien d'automatique ne l'écrit.
     *
     * Le nom est **non localisé** : une collection n'est jamais affichée à un
     * joueur, c'est le thème de saga et ses `theme_label` qui le sont.
     */
    private function collectionId(TmdbMovie $tmdb): ?int
    {
        if ($tmdb->collectionTmdbId === null) {
            return null;
        }

        $collection = Collection::query()->where('tmdb_id', $tmdb->collectionTmdbId)->first();

        if ($collection instanceof Collection) {
            return $collection->id;
        }

        $collection = new Collection;
        $collection->tmdb_id = $tmdb->collectionTmdbId;
        $collection->name = mb_substr($tmdb->collectionName ?? ('TMDB '.$tmdb->collectionTmdbId), 0, 160);
        $collection->save();

        return $collection->id;
    }
}
