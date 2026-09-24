<?php

namespace Tests\Support\Frames;

use Imagick;

/**
 * Sources SYNTHÉTIQUES de la chaîne d'image (contrat C9, spec 20 § 5.5),
 * générées par Imagick au moment du test et jamais suivies par git.
 *
 * Aucune image réelle n'entre dans le dépôt (spec 100 § 7.3,
 * `NoRealFixtureTest`) : un dégradé, un plasma ou un bruit ne représentent
 * rien. Chaque méthode rend des OCTETS, exactement ce que la requête d'ajout
 * dépose provisoirement sous `master_path`.
 */
final class SourceImages
{
    /** Commentaire JPEG témoin : il ne doit jamais survivre au traitement. */
    public const string COMMENT = 'tripleframes-fixture-comment';

    /** Paquet XMP témoin, même règle. */
    public const string XMP = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">tripleframes-fixture-xmp</rdf:RDF></x:xmpmeta>';

    /**
     * Bloc EXIF témoin minimal : en-tête `Exif`, TIFF petit-boutiste, un IFD
     * vide. Assez pour qu'un segment APP1 existe, sans jamais être LU.
     */
    public const string EXIF = "Exif\0\0II*\0\x08\0\0\0\0\0\0\0\0\0";

    /**
     * Un JPEG en dégradé, porteur d'un commentaire, d'un paquet XMP et d'un
     * bloc EXIF quand `$withMetadata` est vrai.
     */
    public static function jpeg(int $width, int $height, bool $withMetadata = false): string
    {
        $image = new Imagick;
        $image->newPseudoImage($width, $height, 'gradient:navy-orange');
        $image->setImageFormat('jpeg');

        if ($withMetadata) {
            $image->commentImage(self::COMMENT);
            $image->setImageProfile('xmp', self::XMP);
            $image->setImageProfile('exif', self::EXIF);
        }

        return self::blob($image);
    }

    /**
     * Un PNG en dégradé, avec un canal alpha si demandé.
     */
    public static function png(int $width, int $height, bool $withAlpha = false): string
    {
        $image = new Imagick;
        $image->newPseudoImage($width, $height, 'gradient:teal-maroon');

        if ($withAlpha) {
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $image->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.5, Imagick::CHANNEL_ALPHA);
        }

        $image->setImageFormat('png');

        return self::blob($image);
    }

    /**
     * Un plasma : un contenu chargé, qui ne tient sous le plafond d'encodage
     * qu'après une descente de qualité.
     */
    public static function plasma(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newPseudoImage($width, $height, 'plasma:');
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(95);

        return self::blob($image);
    }

    /**
     * Un bruit aléatoire : incompressible, il dépasse le plafond d'encodage à
     * toute qualité admise.
     */
    public static function noise(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newPseudoImage($width, $height, 'xc:gray50');
        $image->addNoiseImage(Imagick::NOISE_RANDOM);
        $image->setImageFormat('png');

        return self::blob($image);
    }

    /**
     * Un WebP ANIMÉ de `$frames` images.
     */
    public static function animatedWebp(int $width, int $height, int $frames = 2): string
    {
        $animation = new Imagick;

        for ($index = 0; $index < $frames; $index++) {
            $frame = new Imagick;
            $frame->newImage($width, $height, $index % 2 === 0 ? 'navy' : 'orange');
            $frame->setImageFormat('webp');
            $frame->setImageDelay(10);
            $animation->addImage($frame);
            $frame->clear();
        }

        $animation->setFormat('webp');
        $animation->setImageFormat('webp');

        $bytes = $animation->getImagesBlob();
        $animation->clear();

        return $bytes;
    }

    /**
     * Un WebP statique en dégradé, sans métadonnée : la forme d'un master déjà
     * normalisé quand `$width` vaut `FrameGeometry::MASTER_WIDTH`.
     */
    public static function webp(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newPseudoImage($width, $height, 'gradient:navy-orange');
        $image->setImageFormat('webp');
        $image->stripImage();

        return self::blob($image);
    }

    /**
     * Un GIF : format hors de la liste admise.
     */
    public static function gif(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newPseudoImage($width, $height, 'gradient:navy-orange');
        $image->setImageFormat('gif');

        return self::blob($image);
    }

    /**
     * Un JPEG TRONQUÉ : `finfo` y lit encore un JPEG, Imagick n'y trouve
     * aucune image.
     */
    public static function truncatedJpeg(): string
    {
        return substr(self::jpeg(1920, 1080), 0, 200);
    }

    /**
     * Les blocs de premier niveau d'un conteneur RIFF WebP, dans l'ordre, sans
     * lire au-delà du bloc déclaré — le padding n'en fait pas partie.
     *
     * @return list<string>
     */
    public static function riffChunks(string $webp): array
    {
        $unpacked = unpack('Vsize', $webp, 4);
        $end = 8 + (is_array($unpacked) && is_int($unpacked['size'] ?? null) ? $unpacked['size'] : 0);
        $chunks = [];
        $offset = 12;

        while ($offset + 8 <= $end) {
            $chunks[] = substr($webp, $offset, 4);
            $sizeField = unpack('Vsize', $webp, $offset + 4);
            $size = is_array($sizeField) && is_int($sizeField['size'] ?? null) ? $sizeField['size'] : 0;
            $offset += 8 + $size + ($size % 2);
        }

        return $chunks;
    }

    private static function blob(Imagick $image): string
    {
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }
}
