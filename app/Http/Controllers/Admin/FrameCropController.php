<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\RecropFrame;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FrameCropUpdateRequest;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Re-recadrer une image, en place — contrat C9, spec 20 § 5.7, ligne 15 de la
 * matrice des capacités (`can:update,frame`, `throttle:admin-frame` : chaque
 * re-recadrage distribue un job Imagick).
 *
 * Séquence, chaque refus étant une **erreur de validation traduite**, jamais
 * une page d'erreur :
 *
 * 1. **État** ({@see RecropFrame::refusal()}) : suspendue ou retirée, en
 *    traitement, sans rendu — sous la clé `frame` ;
 * 2. **Plancher à double borne et débordement** (`FrameGeometry::violation()`)
 *    sur la hauteur du master RÉEL, lue dans l'en-tête du fichier — la
 *    requête ne connaît que la largeur — sous la clé `crop` ;
 * 3. **Réécriture** par {@see RecropFrame::handle()}, qui rejoue l'état sous
 *    verrou, fait sortir du jeu une image publiée et distribue le job.
 *
 * Le job revérifie enfin le cadre sur le master qu'il décode (§ 5.2 : vérifié
 * trois fois). Retour arrière avec le toast `admin.frame.flash.recrop_queued`,
 * sans chemin ni empreinte : le curateur n'attend pas le job.
 *
 * `Movie $movie` figure dans la signature pour la liaison implicite : c'est
 * lui qui scope `{frame}` (`scopeBindings`, une frame d'un autre film répond
 * 404).
 */
class FrameCropController extends Controller
{
    /**
     * Octets lus au début du master pour en connaître les dimensions :
     * l'en-tête d'un WebP (`VP8 `, `VP8L` ou `VP8X`) tient dans ses quarante
     * premiers octets ; la marge est large, et jamais l'image entière.
     */
    private const int MASTER_HEADER_BYTES = 4_096;

    /**
     * Réécrit le cadre et confie le nouveau rendu à la file `default`.
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function update(FrameCropUpdateRequest $request, Movie $movie, Frame $frame, RecropFrame $recrop): RedirectResponse
    {
        $refusal = RecropFrame::refusal($frame);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['frame' => __($refusal)]);
        }

        $crop = $request->crop();
        $violation = FrameGeometry::violation($crop, $this->masterHeight($frame), PlatformLimits::current());

        if ($violation !== null) {
            throw ValidationException::withMessages([
                'crop' => __($violation->translationKey()),
            ]);
        }

        /** @var User $curator */
        $curator = $request->user();

        $recrop->handle($frame, $curator, $crop, $request->reason(), $request->cropSeconds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.frame.flash.recrop_queued')]);

        return back();
    }

    /**
     * La hauteur du master de cette frame, lue dans l'EN-TÊTE du fichier —
     * jamais par Imagick, qui ne tourne dans aucune requête HTTP, et sans que
     * rien du fichier ne quitte le serveur.
     *
     * Une frame qui a un rendu a un master normalisé : un WebP de
     * `FrameGeometry::MASTER_WIDTH` de large. Absent ou illisible, le
     * re-recadrage ne pourrait qu'échouer au job après avoir fait sortir
     * l'image du jeu : il est refusé ici, par le texte de l'échec que le job
     * aurait posé.
     *
     * @throws ValidationException
     */
    private function masterHeight(Frame $frame): int
    {
        $path = $frame->master_path;
        $disk = Storage::disk(FrameStoragePrefix::DISK);

        if ($path === null || ! FrameStoragePrefix::Master->owns($path) || ! $disk->exists($path)) {
            throw ValidationException::withMessages(['frame' => __('admin.frame.processing_error.source_missing')]);
        }

        $stream = $disk->readStream($path);
        $header = false;

        if (is_resource($stream)) {
            try {
                $header = stream_get_contents($stream, self::MASTER_HEADER_BYTES);
            } finally {
                fclose($stream);
            }
        }

        $size = is_string($header) && $header !== '' ? @getimagesizefromstring($header) : false;

        if ($size === false
            || $size[2] !== IMAGETYPE_WEBP
            || $size[0] !== FrameGeometry::MASTER_WIDTH
            || $size[1] < 1) {
            throw ValidationException::withMessages(['frame' => __('admin.frame.processing_error.source_unreadable')]);
        }

        return $size[1];
    }
}
