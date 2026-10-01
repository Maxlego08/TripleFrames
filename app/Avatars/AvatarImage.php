<?php

namespace App\Avatars;

use App\Enums\AvatarImageFailure;
use finfo;
use Imagick;
use ImagickException;

/**
 * La normalisation d'un avatar téléversé — spec 40 § 11.2, D49 du 01/10.
 *
 * Le navigateur recadre au carré et envoie une petite source ; le serveur
 * reste autoritaire et ne fait confiance à rien de ce qu'il reçoit :
 *
 * 1. `Imagick::setResourceLimit` posé avant toute lecture ;
 * 2. type relu par `finfo` (JPEG, PNG, WebP), jamais l'extension ni le type
 *    déclaré ;
 * 3. dimensions lues sur l'EN-TÊTE, refusées au-delà de
 *    {@see self::MAX_SOURCE_PX} avant tout décodage ;
 * 4. animation refusée par un « ping », qui compte les images sans les
 *    décoder ;
 * 5. carré CENTRAL, sRGB, `stripImage()` (aucune métadonnée ne survit, aucune
 *    n'est lue), réduction à {@see self::OUTPUT_SIZE_PX} ;
 * 6. WebP à qualité descendante jusqu'à {@see self::MAX_OUTPUT_BYTES}.
 *
 * **Synchrone, dans la requête** : exception assumée et circonscrite à cette
 * classe — la source est petite et bornée, et le joueur attend son image.
 * « Aucun traitement Imagick dans une requête HTTP » reste vrai pour les
 * images de jeu (`FrameImageProcessor`). La transparence est conservée : un
 * avatar n'est pas une image de jeu opaque.
 */
final class AvatarImage
{
    /** Côté du carré que le navigateur envoie (spec 40 § 11.2). */
    public const int SOURCE_SIZE_PX = 512;

    /** Plus grand côté admis à l'en-tête, avant tout décodage. */
    public const int MAX_SOURCE_PX = 4096;

    /** Côté de l'image servie. */
    public const int OUTPUT_SIZE_PX = 256;

    /** Poids maximal de l'image servie. */
    public const int MAX_OUTPUT_BYTES = 40_960;

    /** Formats d'entrée admis, tels que `finfo` les nomme. */
    private const array SOURCE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Qualités essayées, de la meilleure à la plus basse. */
    private const array QUALITIES = [85, 75, 65, 55, 45];

    private const int MEMORY_LIMIT_BYTES = 64 * 1_048_576;

    private const int MAP_LIMIT_BYTES = 128 * 1_048_576;

    private const int AREA_LIMIT_PIXELS = 20_000_000;

    private const int TIME_LIMIT_SECONDS = 10;

    /** Flou neutre du filtre de redimensionnement. */
    private const float RESIZE_BLUR = 1.0;

    /**
     * Les octets d'une source téléversée → les octets WebP servis.
     *
     * @throws AvatarImageException Un refus connu, porteur de sa cause.
     */
    public static function normalize(string $source): string
    {
        self::applyResourceLimits();

        try {
            return self::process($source);
        } finally {
            self::limit('TIME', PHP_INT_MAX);
        }
    }

    /**
     * @throws AvatarImageException
     */
    private static function process(string $source): string
    {
        if (! in_array(self::mimeType($source), self::SOURCE_MIME_TYPES, true)) {
            throw new AvatarImageException(AvatarImageFailure::Format, 'Format hors de la liste admise.');
        }

        $size = @getimagesizefromstring($source);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new AvatarImageException(AvatarImageFailure::Unreadable, 'En-tête d’image illisible.');
        }

        if ($size[0] > self::MAX_SOURCE_PX || $size[1] > self::MAX_SOURCE_PX) {
            throw new AvatarImageException(AvatarImageFailure::Dimensions, sprintf('Source de %d × %d.', $size[0], $size[1]));
        }

        if (self::countImages($source) > 1) {
            throw new AvatarImageException(AvatarImageFailure::Animated, 'Source animée.');
        }

        $image = self::imagick(AvatarImageFailure::Unreadable, static function () use ($source): Imagick {
            $image = new Imagick;
            $image->readImageBlob($source);

            return $image;
        });

        try {
            if ($image->getNumberImages() > 1) {
                throw new AvatarImageException(AvatarImageFailure::Animated, 'Source animée.');
            }

            self::imagick(AvatarImageFailure::Unreadable, static function () use ($image): void {
                $width = $image->getImageWidth();
                $height = $image->getImageHeight();
                $side = min($width, $height);

                $image->cropImage($side, $side, intdiv($width - $side, 2), intdiv($height - $side, 2));
                $image->setImagePage(0, 0, 0, 0);
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                $image->stripImage();
                $image->resizeImage(self::OUTPUT_SIZE_PX, self::OUTPUT_SIZE_PX, Imagick::FILTER_LANCZOS, self::RESIZE_BLUR);
                $image->setImageFormat('webp');
                $image->setOption('webp:lossless', 'false');
            });

            foreach (self::QUALITIES as $quality) {
                $encoded = self::imagick(AvatarImageFailure::Unreadable, static function () use ($image, $quality): string {
                    $image->setImageCompressionQuality($quality);

                    return $image->getImageBlob();
                });

                if (strlen($encoded) <= self::MAX_OUTPUT_BYTES) {
                    return $encoded;
                }
            }
        } finally {
            $image->clear();
        }

        throw new AvatarImageException(AvatarImageFailure::TooHeavy, 'Image au-delà du poids maximal à la qualité minimale.');
    }

    private static function applyResourceLimits(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, self::MEMORY_LIMIT_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, self::MAP_LIMIT_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, self::AREA_LIMIT_PIXELS);

        self::limit('WIDTH', self::MAX_SOURCE_PX);
        self::limit('HEIGHT', self::MAX_SOURCE_PX);
        self::limit('TIME', self::TIME_LIMIT_SECONDS);
    }

    /** Une limite que seules certaines versions d'ImageMagick exposent. */
    private static function limit(string $type, int $value): void
    {
        $constant = Imagick::class.'::RESOURCETYPE_'.$type;

        if (defined($constant)) {
            $resource = constant($constant);

            if (is_int($resource)) {
                Imagick::setResourceLimit($resource, $value);
            }
        }
    }

    /**
     * @throws AvatarImageException
     */
    private static function countImages(string $bytes): int
    {
        return self::imagick(AvatarImageFailure::Unreadable, static function () use ($bytes): int {
            $probe = new Imagick;

            try {
                $probe->pingImageBlob($bytes);

                return $probe->getNumberImages();
            } finally {
                $probe->clear();
            }
        });
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     *
     * @throws AvatarImageException
     */
    private static function imagick(AvatarImageFailure $failure, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ImagickException $exception) {
            throw new AvatarImageException($failure, 'Imagick : '.$exception->getMessage(), $exception);
        }
    }

    private static function mimeType(string $bytes): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mime) ? $mime : '';
    }
}
