<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\AddFrame;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FrameTmdbStoreRequest;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameGeometry;
use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbErrorKind;
use App\Support\Tmdb\TmdbException;
use App\Support\Tmdb\TmdbImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Ajouter une variante depuis un visuel TMDB — contrat C9, spec 20 § 5.3,
 * ligne 13 de la matrice des capacités (`can:create,App\Models\Frame,movie`,
 * `throttle:admin-frame`).
 *
 * Le seul endroit de la voie qui parle à TMDB : `TmdbClient` ne quitte jamais
 * la console et `App\Http\Controllers\Admin` (`TmdbBoundaryTest`). L'action
 * {@see AddFrame} et le job ne reçoivent que des primitives et des octets.
 *
 * Séquence, chaque refus étant une **erreur de validation traduite**, jamais
 * une page d'erreur :
 *
 * 1. **Appartenance** : le chemin doit être l'un des backdrops du film
 *    (`TmdbClient::images()`), jamais une affiche ni un logo (§ 6.2) ;
 * 2. **Dimensions, avant tout téléchargement** : un visuel de moins de
 *    `FrameGeometry::GAME_WIDTH` de large, sans hauteur, en portrait, ou
 *    sur lequel aucun cadre n'est admis, échouerait au job en
 *    `source_too_small` ou `source_aspect` après avoir déposé des octets
 *    inutiles ;
 * 3. **Plancher à double borne** sur la hauteur de master tirée des
 *    métadonnées (`FrameGeometry::masterHeightFor()`, `violation()`) : la
 *    requête ne connaît que la largeur ;
 * 4. **Téléchargement** de l'original par le serveur, plafonné — un échec ne
 *    crée aucune frame ;
 * 5. **Ajout** par {@see AddFrame::fromTmdb()}, dédoublonné sous verrou.
 *
 * Retour arrière avec le toast `admin.frame.flash.queued`, **sans chemin ni
 * empreinte** : le curateur n'attend jamais le job, il enchaîne (§ 6.1).
 *
 * Les pannes de TMDB sont journalisées par leur cas et leur statut, jamais
 * par une URL ni un chemin de visuel.
 */
class FrameTmdbController extends Controller
{
    /**
     * Ajoute la variante et confie son traitement à la file `default`.
     *
     * @throws ValidationException
     */
    public function store(FrameTmdbStoreRequest $request, Movie $movie, TmdbClient $tmdb, AddFrame $addFrame): RedirectResponse
    {
        $limits = PlatformLimits::current();
        $filePath = $request->tmdbFilePath();

        $image = $this->backdrop($tmdb, $movie, $filePath);

        if (! self::isUsableSource($image, $limits)) {
            throw ValidationException::withMessages([
                'tmdb_file_path' => __('admin.validation.frame_source.dimensions', ['width' => FrameGeometry::GAME_WIDTH]),
            ]);
        }

        $crop = $request->crop();
        $violation = FrameGeometry::violation(
            $crop,
            FrameGeometry::masterHeightFor($image->width, $image->height),
            $limits,
        );

        if ($violation !== null) {
            throw ValidationException::withMessages([
                'crop' => __($violation->translationKey()),
            ]);
        }

        try {
            $bytes = $tmdb->downloadImage($filePath);
        } catch (TmdbException $exception) {
            throw $this->refusal($exception, $movie, 'téléchargement');
        }

        /** @var User $curator */
        $curator = $request->user();

        $addFrame->fromTmdb(
            movie: $movie,
            curator: $curator,
            level: $request->frameLevel(),
            crop: $crop,
            tmdbFilePath: $filePath,
            originalBytes: $bytes,
            cropSeconds: $request->cropSeconds(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.frame.flash.queued')]);

        return back();
    }

    /**
     * Le backdrop du film que désigne ce chemin, ou un refus.
     *
     * Un film sans identifiant TMDB (catalogue de démonstration) n'a aucun
     * visuel proposé, et un identifiant que TMDB ne connaît plus non plus :
     * dans les deux cas, le chemin n'est pas un backdrop du film.
     *
     * @throws ValidationException
     */
    private function backdrop(TmdbClient $tmdb, Movie $movie, string $filePath): TmdbImage
    {
        $notABackdrop = ValidationException::withMessages([
            'tmdb_file_path' => __('admin.frame.tmdb.not_a_backdrop'),
        ]);

        if ($movie->tmdb_id === null) {
            throw $notABackdrop;
        }

        try {
            $images = $tmdb->images($movie->tmdb_id);
        } catch (TmdbException $exception) {
            if ($exception->kind === TmdbErrorKind::NotFound) {
                throw $notABackdrop;
            }

            throw $this->refusal($exception, $movie, 'visuels du film');
        }

        foreach ($images->backdrops as $backdrop) {
            if ($backdrop->filePath === $filePath) {
                return $backdrop;
            }
        }

        throw $notABackdrop;
    }

    /**
     * Vrai si ce visuel peut donner une image de jeu : au moins
     * `FrameGeometry::GAME_WIDTH` de large, en paysage, et au moins un cadre
     * admis par le plancher sur son master — les trois refus que le job
     * opposerait (`source_too_small`, `source_aspect`), lus ici sur les
     * dimensions déclarées par TMDB, avant tout téléchargement.
     */
    private static function isUsableSource(TmdbImage $image, PlatformLimits $limits): bool
    {
        // Une hauteur absente ou nulle se lit 0 (`TmdbData::counter()`) :
        // refusée ici, elle ne fait jamais lever `masterHeightFor()`.
        if ($image->width < FrameGeometry::GAME_WIDTH || $image->height < 1 || $image->height > $image->width) {
            return false;
        }

        $masterHeight = FrameGeometry::masterHeightFor($image->width, $image->height);

        return FrameGeometry::maxCropWidth($masterHeight, $limits) >= $limits->frameCropMinWidthPx;
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
