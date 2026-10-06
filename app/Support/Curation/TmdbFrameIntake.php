<?php

namespace App\Support\Curation;

use App\Actions\Curation\AddFrame;
use App\Enums\FrameLevel;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameGeometry;
use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbErrorKind;
use App\Support\Tmdb\TmdbException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La voie TMDB de l'ajout d'une variante, en un seul endroit — contrat C9,
 * spec 20 § 5.3 ; partagée par l'ajout unitaire de l'éditeur
 * (`FrameTmdbController`) et par l'import d'un lot d'images (§ 5.10, D57 du
 * 05/10), pour qu'un lot ne franchisse jamais une garde que l'éditeur
 * refuserait.
 *
 * Séquence, chaque refus étant une **erreur de validation traduite**, jamais
 * une page d'erreur :
 *
 * 1. **Appartenance** : le chemin doit être l'un des backdrops du film, lus
 *    dans la liste en cache ({@see self::backdrops()}, § 6.2) — jamais une
 *    affiche ni un logo ;
 * 2. **Langue, avant tout téléchargement** : un backdrop auquel TMDB attache
 *    une langue peut contenir du texte (`admin.frame.tmdb.with_text`, D39 du
 *    28/09) ;
 * 3. **Dimensions, avant tout téléchargement** : un visuel trop petit, en
 *    portrait ou sans cadre admis échouerait au job après avoir déposé des
 *    octets inutiles ;
 * 4. **Plancher à double borne** sur la hauteur de master tirée des
 *    métadonnées ; sans cadre fourni (un lot peut l'omettre), le cadre par
 *    défaut, le plus grand admis, centré ;
 * 5. **Téléchargement** de l'original par le serveur, plafonné — un échec ne
 *    crée aucune frame ;
 * 6. **Ajout** par {@see AddFrame::fromTmdb()}, dédoublonné sous verrou.
 *
 * Seule classe de `App\Support\Curation` admise à parler à TMDB
 * (`TmdbBoundaryTest`) : elle ne sert que le back-office et la console,
 * jamais une surface de jeu (règle 6). Les pannes de TMDB sont journalisées
 * par leur cas et leur statut, jamais par une URL ni un chemin de visuel.
 */
final class TmdbFrameIntake
{
    /** Clé de cache de la liste des visuels d'un film, par identifiant TMDB. */
    public const string BACKDROPS_CACHE_PREFIX = 'admin:tmdb-backdrops:';

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly AddFrame $addFrame,
    ) {}

    /**
     * Ajoute la variante et confie son traitement à la file `default`.
     *
     * @param  CropRect|null  $crop  le cadre, dans l'espace du master ; `null` = le cadre par défaut
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function add(
        Movie $movie,
        User $curator,
        FrameLevel $level,
        ?CropRect $crop,
        string $filePath,
        ?int $cropSeconds = null,
    ): Frame {
        $limits = PlatformLimits::current();
        $image = $this->backdrop($movie, $filePath);

        if (! $image['language_neutral']) {
            // Le refus ne propose la capture que si ce site l'offre.
            throw ValidationException::withMessages([
                'tmdb_file_path' => Config::boolean('catalog.curation.capture_enabled', true)
                    ? __('admin.frame.tmdb.with_text')
                    : __('admin.frame.tmdb.with_text_no_capture'),
            ]);
        }

        if (! FrameGeometry::acceptsSource($image['width'], $image['height'], $limits)) {
            throw ValidationException::withMessages([
                'tmdb_file_path' => __('admin.validation.frame_source.dimensions', ['width' => FrameGeometry::GAME_WIDTH]),
            ]);
        }

        $masterHeight = FrameGeometry::masterHeightFor($image['width'], $image['height']);
        $crop ??= FrameGeometry::defaultCrop($masterHeight, $limits);
        $violation = FrameGeometry::violation($crop, $masterHeight, $limits);

        if ($violation !== null) {
            throw ValidationException::withMessages([
                'crop' => __($violation->translationKey()),
            ]);
        }

        try {
            $bytes = $this->tmdb->downloadImage($filePath);
        } catch (TmdbException $exception) {
            throw $this->refusal($exception, $movie, 'téléchargement');
        }

        return $this->addFrame->fromTmdb(
            movie: $movie,
            curator: $curator,
            level: $level,
            crop: $crop,
            tmdbFilePath: $filePath,
            originalBytes: $bytes,
            cropSeconds: $cropSeconds,
        );
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
     * **Tous les backdrops y entrent**, ceux auxquels TMDB attache une langue
     * compris (`language_neutral` faux) : la grille ne les propose pas mais
     * les compte, et l'ajout les refuse d'un motif précis — « peut contenir
     * du texte » — plutôt que de les dire étrangers au film (D39 du 28/09).
     *
     * **Le même ensemble sert à la vérification d'appartenance de l'ajout** :
     * ce que la grille propose est ce que l'ajout accepte, sans second appel
     * à TMDB — et un quota atteint sur l'API n'empêche pas d'ajouter un
     * visuel déjà listé.
     *
     * @return list<array{file_path: string, width: int, height: int, language_neutral: bool}>
     *
     * @throws TmdbException
     */
    public function backdrops(int $tmdbId): array
    {
        /** @var list<array{file_path: string, width: int, height: int, language_neutral: bool}> $backdrops */
        $backdrops = Cache::remember(
            self::BACKDROPS_CACHE_PREFIX.$tmdbId,
            Date::now()->addMinutes(Config::integer('catalog.curation.images_cache_minutes')),
            function () use ($tmdbId): array {
                $rows = [];

                foreach ($this->tmdb->images($tmdbId)->backdrops as $image) {
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

    /**
     * Le backdrop du film que désigne ce chemin, ou un refus.
     *
     * Un film sans identifiant TMDB (catalogue de démonstration) n'a aucun
     * visuel proposé, et un identifiant que TMDB ne connaît plus non plus :
     * dans les deux cas, le chemin n'est pas un backdrop du film.
     *
     * @return array{file_path: string, width: int, height: int, language_neutral: bool}
     *
     * @throws ValidationException
     */
    private function backdrop(Movie $movie, string $filePath): array
    {
        $notABackdrop = ValidationException::withMessages([
            'tmdb_file_path' => __('admin.frame.tmdb.not_a_backdrop'),
        ]);

        if ($movie->tmdb_id === null) {
            throw $notABackdrop;
        }

        try {
            $backdrops = $this->backdrops($movie->tmdb_id);
        } catch (TmdbException $exception) {
            if ($exception->kind === TmdbErrorKind::NotFound) {
                throw $notABackdrop;
            }

            throw $this->refusal($exception, $movie, 'visuels du film');
        }

        foreach ($backdrops as $backdrop) {
            if ($backdrop['file_path'] === $filePath) {
                return $backdrop;
            }
        }

        throw $notABackdrop;
    }

    /**
     * Une panne de TMDB, rendue au curateur en message traduit sous le
     * visuel — ce qu'il peut faire, jamais le détail technique, qui part au
     * journal.
     *
     * - quota atteint : `admin.tmdb.error.rate_limited_interactive`, et le
     *   formulaire, conservé, se renvoie tel quel (§ 3.6) ;
     * - aucune clé TMDB : `admin.tmdb.error.not_configured` ;
     * - visuel plus lourd que le plafond : `admin.frame.tmdb.too_large` ;
     * - toute autre panne : `admin.frame.tmdb.download_failed`.
     */
    private function refusal(TmdbException $exception, Movie $movie, string $step): ValidationException
    {
        Log::warning('Ajout d’image TMDB en échec.', [
            'movie_id' => $movie->id,
            'step' => $step,
            'kind' => $exception->kind->value,
            'status' => $exception->httpStatus,
        ]);

        $message = match ($exception->kind) {
            TmdbErrorKind::RateLimited => __('admin.tmdb.error.rate_limited_interactive'),
            TmdbErrorKind::NotConfigured => __('admin.tmdb.error.not_configured'),
            TmdbErrorKind::TooLarge => __('admin.frame.tmdb.too_large'),
            TmdbErrorKind::Unauthorized, TmdbErrorKind::NotFound, TmdbErrorKind::ServerError,
            TmdbErrorKind::Transport, TmdbErrorKind::Malformed, TmdbErrorKind::UnexpectedStatus => __('admin.frame.tmdb.download_failed'),
        };

        return ValidationException::withMessages(['tmdb_file_path' => $message]);
    }
}
