<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\AddFrame;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FrameCaptureStoreRequest;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Movie;
use App\Models\User;
use App\Policies\FramePolicy;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameGeometry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Ajouter une variante par capture personnelle — contrat C9, spec 20 § 5.4,
 * ligne 14 de la matrice des capacités
 * (`can:createFromCapture,App\Models\Frame,movie`, `throttle:admin-frame`).
 * Lot L20-33 : la voie est ouverte au jalon 1 (D38 du 28/09).
 *
 * **R-46** : le navigateur normalise la source — un WebP de
 * `FrameGeometry::MASTER_WIDTH` de large, ramené sous le plafond d'entrée —
 * et n'envoie que ce fichier, son minutage et le rectangle ; le SERVEUR dérive
 * toujours l'image de jeu, du master et du rectangle, ce qui rend vérifiable
 * le plancher de recadrage (D6 du 23/09). Le cadre vit dans l'espace du
 * master, comme sur la voie TMDB : la hauteur de master que le navigateur a
 * calculée est celle que ce contrôleur retrouve sur le fichier reçu.
 *
 * Séquence, chaque refus étant une **erreur de validation traduite**, jamais
 * une page d'erreur :
 *
 * 1. **Garde** : voie fermée par une valeur explicitement fausse de
 *    `catalog.curation.capture_enabled`, la route répond 403 motivé par
 *    {@see FramePolicy::CAPTURE_DISABLED}, avant toute résolution de requête ;
 * 2. **Requête** ({@see FrameCaptureStoreRequest}) : type WebP lu dans le
 *    contenu, poids sous le plafond, largeur, minutage `h:mm:ss`, niveau et
 *    forme du cadre ;
 * 3. **Dimensions réelles** du fichier reçu : paysage, et place d'au moins un
 *    cadre admis (`FrameGeometry::acceptsSource()`) — le refus que le job
 *    opposerait en `source_aspect`, rendu avant tout dépôt d'octets ;
 * 4. **Plancher à double borne** sur la hauteur de master de ces dimensions
 *    (`FrameGeometry::masterHeightFor()`, `violation()`) : la requête ne
 *    connaît que la largeur ;
 * 5. **Ajout** par {@see AddFrame::fromCapture()}, dédoublonné sous verrou,
 *    où la garde est rejouée.
 *
 * Aucun octet n'est transformé ici : le job ({@see ProcessFrameImage}, file
 * `default`) normalise TOUJOURS une capture — refus de l'animation,
 * `stripImage()`, réencodage —, ses octets provisoires ayant pour SHA-256
 * `source_hash`.
 *
 * **Ni métadonnées ni outil** (§ 7.6, A7) : la source déclarée d'une capture
 * est son minutage, un instant DANS L'ŒUVRE. Rien ne lit l'EXIF du fichier
 * (`stripImage()` l'efface sans le lire, `ext-exif` est absente), et rien ne
 * consigne le logiciel ni la méthode d'extraction.
 *
 * Retour arrière avec le toast `admin.frame.flash.queued`, **sans chemin ni
 * empreinte** : le curateur n'attend jamais le job (§ 6.1).
 */
class FrameCaptureController extends Controller
{
    /**
     * Ajoute la variante et confie son traitement à la file `default`.
     *
     * @throws ValidationException
     */
    public function store(FrameCaptureStoreRequest $request, Movie $movie, AddFrame $addFrame): RedirectResponse
    {
        $limits = PlatformLimits::current();
        $source = $request->source();

        [$width, $height] = self::dimensions($source);

        if (! FrameGeometry::acceptsSource($width, $height, $limits)) {
            throw ValidationException::withMessages([
                'source' => __('admin.validation.frame_source.dimensions', ['width' => FrameGeometry::GAME_WIDTH]),
            ]);
        }

        $crop = $request->crop();
        $violation = FrameGeometry::violation(
            $crop,
            FrameGeometry::masterHeightFor($width, $height),
            $limits,
        );

        if ($violation !== null) {
            throw ValidationException::withMessages([
                'crop' => __($violation->translationKey()),
            ]);
        }

        /** @var User $curator */
        $curator = $request->user();

        $addFrame->fromCapture(
            movie: $movie,
            curator: $curator,
            level: $request->frameLevel(),
            crop: $crop,
            source: $source,
            timecodeMs: $request->timecodeMs(),
            cropSeconds: $request->cropSeconds(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.frame.flash.queued')]);

        return back();
    }

    /**
     * Largeur et hauteur du fichier REÇU, lues par `getimagesize` — jamais
     * celles que le navigateur déclarerait. Un fichier illisible se lit
     * « 0 × 0 », que `FrameGeometry::acceptsSource()` refuse sans lever.
     *
     * @return array{0: int, 1: int}
     */
    private static function dimensions(UploadedFile $source): array
    {
        $size = @getimagesize((string) $source->getRealPath());

        if ($size === false) {
            return [0, 0];
        }

        return [$size[0], $size[1]];
    }
}
