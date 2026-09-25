<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\PublishMovie;
use App\Http\Controllers\Controller;
use App\Models\Frame;
use App\Models\Movie;
use App\Settings\PlatformLimits;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Curation\CoverageLossPreview;
use App\Support\Curation\FrameBankSnapshot;
use App\Support\Frames\FrameGeometry;
use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbErrorKind;
use App\Support\Tmdb\TmdbException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'éditeur de la banque d'images d'un film — spec 20 § 6, ligne 4 de la
 * matrice des capacités (`can:curate,movie` : curateur et au-delà, jamais sur
 * un film retiré).
 *
 * Trois zones et un pied (§ 6.1) : les visuels TMDB du film, le recadreur
 * avec le choix du niveau et l'envoi, la banque du film — images groupées par
 * niveau, couverture, prévisualisation en conditions de jeu —, et la
 * publiabilité. Toutes les écritures ont leur propre route, leur propre garde
 * et leur propre limiteur (lots L20-7 et L20-8) : cet écran n'écrit rien.
 *
 * **La page n'attend jamais TMDB** : les visuels sont une prop DIFFÉRÉE
 * (`Inertia::defer`), chargée par un second aller-retour après l'affichage.
 * Leur liste est tenue en cache `catalog.curation.images_cache_minutes`
 * minutes par identifiant TMDB — une réponse seulement, jamais une panne, que
 * « Réessayer » doit pouvoir rejouer. Une panne ne fait jamais une page
 * d'erreur : elle devient un état (`failed`, `rate_limited`,
 * `not_configured`), que l'écran traduit et rend rejouable d'un bouton
 * (§ 3.6, décision 9).
 *
 * **Le curateur n'attend jamais le job** : tant qu'une image est en
 * traitement, l'écran se recharge partiellement (`frames`, `movie`,
 * `sequencePreview`) toutes les `catalog.curation.poll_seconds` secondes.
 *
 * `unpublish_preview` est une prop FACULTATIVE, calculée au seul rechargement
 * partiel qui ouvre la confirmation d'un geste faisant sortir une image du
 * jeu (§ 5.7, § 8.4) : l'image visée est désignée par `preview_frame`.
 * `publication_preview` aussi, au rechargement qui ouvre la confirmation de
 * « Publier le film » (§ 8.1, § 8.2) : l'aperçu d'ambiguïté, en lecture
 * seule, dont l'empreinte repart avec la publication (lot L20-13).
 *
 * Seul endroit, avec l'ajout d'une image, où le back-office parle à TMDB
 * (`TmdbBoundaryTest`) ; aucun DTO `App\Support\Tmdb` ne quitte ce
 * contrôleur. Les URL des visuels pointent le serveur d'images de TMDB, dans
 * le navigateur du curateur seulement (§ 6.2, AN20-2) ; aucune surface joueur
 * n'y pointe jamais.
 */
class FrameBankController extends Controller
{
    /** Mot-clé TMDB des vignettes de la grille (§ 6.2). */
    public const string THUMBNAIL_SIZE = 'w300';

    /** Mot-clé TMDB du visuel affiché dans le recadreur (§ 6.2, § 6.3). */
    public const string DISPLAY_SIZE = 'w1280';

    /** Paramètre du rechargement partiel qui demande l'avertissement de couverture. */
    public const string PREVIEW_FRAME_PARAMETER = 'preview_frame';

    /** Clé de cache de la liste des visuels d'un film, par identifiant TMDB. */
    public const string BACKDROPS_CACHE_PREFIX = 'admin:tmdb-backdrops:';

    /** Les états de la prop différée `backdrops`. */
    public const string BACKDROPS_READY = 'ready';

    public const string BACKDROPS_EMPTY = 'empty';

    public const string BACKDROPS_FAILED = 'failed';

    public const string BACKDROPS_RATE_LIMITED = 'rate_limited';

    public const string BACKDROPS_NOT_CONFIGURED = 'not_configured';

    /** Le motif d'un visuel proposé désactivé : le même refus que l'ajout (§ 5.3). */
    public const string BACKDROP_REFUSAL = 'admin.validation.frame_source.dimensions';

    /**
     * L'éditeur. Aucune écriture, aucun appel TMDB avant l'affichage.
     */
    public function show(
        Request $request,
        Movie $movie,
        TmdbClient $tmdb,
        CoverageLossPreview $coverageLoss,
        AmbiguityPreview $ambiguity,
    ): Response {
        $bank = new FrameBankSnapshot($movie);

        return Inertia::render('admin/catalog/bank', [
            'movie' => fn (): array => $this->movie($bank),
            'frames' => fn (): array => $this->frames($bank),
            'sequencePreview' => fn (): array => $bank->sequences(
                fn (Frame $frame): string => route('admin.catalog.frames.game', ['movie' => $movie->id, 'frame' => $frame->id]),
            ),
            'limits' => [
                'frameCropMaxWidthPercent' => PlatformLimits::frameCropMaxWidthPercent(),
                'frameCropMinWidthPx' => PlatformLimits::frameCropMinWidthPx(),
                'frameUploadMaxKilobytes' => PlatformLimits::frameUploadMaxKilobytes(),
            ],
            'captureEnabled' => Config::boolean('catalog.curation.capture_enabled', false),
            'pollSeconds' => Config::integer('catalog.curation.poll_seconds'),
            // Ne sert qu'à masquer un bouton : l'ajout et la publication
            // gardent leur propre policy à l'écriture (§ 2.1).
            'abilities' => fn (): array => [
                'createFrame' => Gate::allows('create', [Frame::class, $movie]),
                'publish' => Gate::allows('publish', $movie),
            ],
            'unpublish_preview' => Inertia::optional(fn (): ?array => $this->unpublishPreview($request, $movie, $coverageLoss)),
            'publication_preview' => Inertia::optional(fn (): array => $ambiguity->forPublication($movie)->toArray()),
            'backdrops' => Inertia::defer(fn (): array => $this->backdrops($tmdb, $bank)),
        ]);
    }

    /**
     * Le film : identité, disponibilité, drapeau, couverture (§ 6.1),
     * l'état « le traitement d'arrière-plan ne répond pas » (§ 13.5), et les
     * conditions de publication qui manquent — le pied de l'éditeur les nomme
     * sous « Publier le film » (§ 8.1). Rechargées avec le film, par le
     * sondage comme après chaque écriture.
     *
     * @return array<string, mixed>
     */
    private function movie(FrameBankSnapshot $bank): array
    {
        $movie = $bank->movie;

        return [
            'id' => $movie->id,
            'tmdb_id' => $movie->tmdb_id,
            'title_original' => $movie->title_original,
            'title_original_latin' => $movie->title_original_latin,
            'release_year' => $movie->release_year,
            'availability' => $movie->availability->value,
            'content_flag' => $movie->content_flag->value,
            'import_source' => $movie->import_source->value,
            'coverage' => $bank->coverage(),
            'has_pending' => $bank->hasPending(),
            'processing_stalled' => $bank->processingStalled(
                Date::now()->toImmutable(),
                Config::integer('catalog.curation.stale_pending_minutes'),
            ),
            'publication' => PublishMovie::conditions($movie, $bank->projection()),
        ];
    }

    /**
     * La banque : chaque image, groupable par niveau côté écran.
     *
     * @return list<array<string, mixed>>
     */
    private function frames(FrameBankSnapshot $bank): array
    {
        $rows = [];

        foreach ($bank->frames() as $frame) {
            $rows[] = AdminCatalogPresenter::bankFrame($frame, $bank->stateOf($frame), $bank->reviewRejected($frame));
        }

        return $rows;
    }

    /**
     * L'avertissement de perte de couverture pour l'image désignée par
     * `preview_frame`, ou `null` s'il n'y a rien à annoncer — image d'un
     * autre film comprise : elle n'est pas de cette banque.
     *
     * @return array{frame_id: int, playable_up_to: int|null}|null
     */
    private function unpublishPreview(Request $request, Movie $movie, CoverageLossPreview $coverageLoss): ?array
    {
        $frameId = $request->integer(self::PREVIEW_FRAME_PARAMETER);

        if ($frameId < 1) {
            return null;
        }

        $frame = Frame::query()->where('movie_id', $movie->id)->find($frameId);

        if (! $frame instanceof Frame) {
            return null;
        }

        $frame->setRelation('movie', $movie);

        return $coverageLoss->forFrame($frame);
    }

    /**
     * Les visuels proposés : les **backdrops seuls** — affiches et logos ne
     * sont jamais candidats (§ 6.2) —, sans texte d'abord, ordre de TMDB
     * conservé à l'intérieur. Un visuel trop étroit ou en portrait reste
     * proposé, désactivé, avec le motif que l'ajout opposerait.
     *
     * @return array{status: string, items: list<array<string, mixed>>}
     */
    private function backdrops(TmdbClient $tmdb, FrameBankSnapshot $bank): array
    {
        $movie = $bank->movie;

        if ($movie->tmdb_id === null) {
            return ['status' => self::BACKDROPS_EMPTY, 'items' => []];
        }

        try {
            $backdrops = self::cachedBackdrops($tmdb, $movie->tmdb_id);
        } catch (TmdbException $exception) {
            Log::warning('Visuels TMDB de l’éditeur en échec.', [
                'movie_id' => $movie->id,
                'kind' => $exception->kind->value,
                'status' => $exception->httpStatus,
            ]);

            return [
                'status' => match ($exception->kind) {
                    TmdbErrorKind::RateLimited => self::BACKDROPS_RATE_LIMITED,
                    TmdbErrorKind::NotConfigured => self::BACKDROPS_NOT_CONFIGURED,
                    TmdbErrorKind::NotFound => self::BACKDROPS_EMPTY,
                    TmdbErrorKind::Unauthorized, TmdbErrorKind::ServerError, TmdbErrorKind::Transport,
                    TmdbErrorKind::Malformed, TmdbErrorKind::UnexpectedStatus, TmdbErrorKind::TooLarge => self::BACKDROPS_FAILED,
                },
                'items' => [],
            ];
        }

        if ($backdrops === []) {
            return ['status' => self::BACKDROPS_EMPTY, 'items' => []];
        }

        $neutral = array_filter($backdrops, static fn (array $backdrop): bool => $backdrop['language_neutral']);
        $tagged = array_filter($backdrops, static fn (array $backdrop): bool => ! $backdrop['language_neutral']);

        $limits = PlatformLimits::current();
        $usedLevels = $bank->usedLevelsByFilePath();
        $items = [];

        foreach ([...$neutral, ...$tagged] as $backdrop) {
            $path = $backdrop['file_path'];

            $items[] = [
                'file_path' => $path,
                'width' => $backdrop['width'],
                'height' => $backdrop['height'],
                'language_neutral' => $backdrop['language_neutral'],
                'thumb_url' => $tmdb->imageUrl($path, self::THUMBNAIL_SIZE),
                'image_url' => $tmdb->imageUrl($path, self::DISPLAY_SIZE),
                'refusal' => FrameGeometry::acceptsSource($backdrop['width'], $backdrop['height'], $limits)
                    ? null
                    : self::BACKDROP_REFUSAL,
                'used_levels' => $usedLevels[$path] ?? [],
            ];
        }

        return ['status' => self::BACKDROPS_READY, 'items' => $items];
    }

    /**
     * Les backdrops d'un film, dans l'ordre de TMDB, en données primitives.
     *
     * Mis en cache `catalog.curation.images_cache_minutes` minutes par
     * identifiant TMDB : une liste un peu ancienne est inoffensive, un chemin
     * de fichier TMDB restant valide (§ 6.2). Seule une RÉPONSE entre au
     * cache : une panne lève, n'écrit rien, et « Réessayer » rappelle TMDB.
     * Des tableaux et jamais des objets : le cache ne désérialise aucune
     * classe (`cache.serializable_classes`).
     *
     * **Le même ensemble sert à la vérification d'appartenance de l'ajout**
     * ({@see FrameTmdbController}, § 5.3) : ce que la grille propose est ce
     * que l'ajout accepte, sans second appel à TMDB — et un quota atteint
     * sur l'API n'empêche pas d'ajouter un visuel déjà listé.
     *
     * @return list<array{file_path: string, width: int, height: int, language_neutral: bool}>
     *
     * @throws TmdbException
     */
    public static function cachedBackdrops(TmdbClient $tmdb, int $tmdbId): array
    {
        /** @var list<array{file_path: string, width: int, height: int, language_neutral: bool}> $backdrops */
        $backdrops = Cache::remember(
            self::BACKDROPS_CACHE_PREFIX.$tmdbId,
            Date::now()->addMinutes(Config::integer('catalog.curation.images_cache_minutes')),
            function () use ($tmdb, $tmdbId): array {
                $rows = [];

                foreach ($tmdb->images($tmdbId)->backdrops as $image) {
                    $rows[] = [
                        'file_path' => $image->filePath,
                        'width' => $image->width,
                        'height' => $image->height,
                        'language_neutral' => $image->isLanguageNeutral(),
                    ];
                }

                return $rows;
            },
        );

        return $backdrops;
    }
}
