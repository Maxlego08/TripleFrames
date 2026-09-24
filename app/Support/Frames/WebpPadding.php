<?php

namespace App\Support\Frames;

use InvalidArgumentException;

/**
 * Padding du dérivé servi jusqu'au multiple de `FrameGeometry::GAME_PAD_BYTES`
 * (contrat C9, spec 20 § 5.5 étape 4 ; spec 10 § 4.1).
 *
 * Une taille servie quantifiée retire une surface d'anti-corrélation : deux
 * images d'un même film ne se reconnaissent plus à leur poids exact.
 *
 * Les octets ajoutés sont des NUL placés APRÈS le bloc RIFF, dont le champ de
 * taille reste intact : un décodeur WebP lit le bloc déclaré et ignore la
 * traîne. Le résultat est donc un WebP décodable à l'identique, dont les octets
 * paddés sont ceux que le job hache (`published_hash`) et que la route de jeu
 * sert.
 *
 * Idempotent : un fichier déjà paddé ressort inchangé. Refuse tout ce qui
 * n'est pas un WebP RIFF entier suivi, au plus, de NUL — un en-tête étranger,
 * un bloc tronqué ou une traîne non nulle sont des fautes de la chaîne, jamais
 * des octets à servir.
 *
 * Ne juge pas le plafond : le job encode d'abord sous
 * `FrameGeometry::gameEncodeCeilingBytes()`, dont le multiple paddé tient par
 * construction sous `FrameGeometry::GAME_MAX_BYTES`.
 */
final class WebpPadding
{
    /** Balise d'ouverture d'un conteneur RIFF. */
    private const string RIFF_TAG = 'RIFF';

    /** Forme du conteneur, juste après le champ de taille. */
    private const string WEBP_TAG = 'WEBP';

    /** `RIFF` + champ de taille (uint32 petit-boutiste) : le champ compte à partir d'ici. */
    private const int RIFF_PREAMBLE_BYTES = 8;

    /** Octet de padding. */
    private const string PAD_BYTE = "\0";

    /**
     * @throws InvalidArgumentException Un en-tête qui n'est pas `RIFF…WEBP`, un
     *                                  bloc RIFF tronqué ou une traîne non nulle.
     */
    public static function pad(string $webp): string
    {
        $length = strlen($webp);
        $headerBytes = self::RIFF_PREAMBLE_BYTES + strlen(self::WEBP_TAG);

        if ($length < $headerBytes
            || ! str_starts_with($webp, self::RIFF_TAG)
            || substr($webp, self::RIFF_PREAMBLE_BYTES, strlen(self::WEBP_TAG)) !== self::WEBP_TAG) {
            throw new InvalidArgumentException('WebpPadding : en-tête RIFF…WEBP absent.');
        }

        $blockEnd = self::RIFF_PREAMBLE_BYTES + self::riffSize($webp);

        if ($blockEnd < $headerBytes || $blockEnd > $length) {
            throw new InvalidArgumentException(sprintf(
                'WebpPadding : bloc RIFF incohérent (fin déclarée %d, longueur %d).',
                $blockEnd,
                $length,
            ));
        }

        if (strspn($webp, self::PAD_BYTE, $blockEnd) !== $length - $blockEnd) {
            throw new InvalidArgumentException('WebpPadding : octets non nuls après le bloc RIFF.');
        }

        return str_pad($webp, FrameGeometry::paddedLength($length), self::PAD_BYTE);
    }

    /**
     * Champ de taille RIFF, uint32 petit-boutiste à l'octet 4.
     */
    private static function riffSize(string $webp): int
    {
        $unpacked = unpack('Vsize', $webp, strlen(self::RIFF_TAG));
        $size = is_array($unpacked) ? ($unpacked['size'] ?? null) : null;

        if (! is_int($size)) {
            throw new InvalidArgumentException('WebpPadding : champ de taille RIFF illisible.');
        }

        return $size;
    }
}
