<?php

namespace App\Http\Controllers\Avatar;

use App\Avatars\AccountImage;
use App\Avatars\UploadedAvatars;
use App\Http\Controllers\Controller;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Les octets d'un avatar téléversé — `avatar.show`, `GET /a/{file}` (spec 40
 * § 11.3, D49 du 01/10).
 *
 * Ne sert un fichier que s'il est l'image COURANTE et VISIBLE d'un compte non
 * anonymisé : une image masquée, retirée ou remplacée répond 404, même à qui
 * en connaît l'URL. Sans session ni cookie. Cacheable et immuable : un nom
 * aléatoire ne désigne jamais deux contenus.
 */
class AvatarFileController extends Controller
{
    /** Un an : le nom change à chaque téléversement. */
    private const int MAX_AGE_SECONDS = 31_536_000;

    public function show(string $file): Response
    {
        // Un nom aléatoire ne désigne jamais deux fichiers : l'image téléversée
        // et la copie de la photo du fournisseur partagent la même URL.
        foreach (AccountImage::cases() as $image) {
            $path = UploadedAvatars::pathOf($file, $image);

            $user = User::query()
                ->select(AccountImage::columns())
                ->where($image->pathColumn(), $path)
                ->first();

            if ($user !== null) {
                if (! $image->isVisible($user)) {
                    break;
                }

                return self::respond($path, 'public, max-age='.self::MAX_AGE_SECONDS.', immutable');
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * La réponse d'image, partagée avec l'écran « Avatars » de l'admin, qui sert
     * une image masquée sous `no-store`.
     */
    public static function respond(string $path, string $cacheControl): Response
    {
        $disk = UploadedAvatars::disk();

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        return response((string) $disk->get($path), Response::HTTP_OK, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
