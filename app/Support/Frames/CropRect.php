<?php

namespace App\Support\Frames;

use App\Models\Frame;
use InvalidArgumentException;

/**
 * Rectangle de recadrage, exprimé en pixels dans l'ESPACE DU MASTER : largeur
 * exactement `FrameGeometry::MASTER_WIDTH`, hauteur
 * `FrameGeometry::masterHeightFor()` (contrat C9, spec 20 § 5.1, E10-18).
 *
 * Une valeur, jamais une validation : construire un rectangle ne dit rien de
 * sa conformité. Seul {@see FrameGeometry::violation()} juge un cadre, contre
 * un master et un plancher donnés — c'est lui qu'appellent le contrôleur puis
 * le job, sur la hauteur de master tirée des métadonnées puis sur le master
 * réel.
 *
 * Même forme que la prop `crop` d'`AdminMovieFrame` (`{ x, y, width, height }`)
 * et que le type `CropRect` du miroir `resources/js/lib/frame-geometry.ts`.
 */
final readonly class CropRect
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    /**
     * Le rectangle que porte une frame (`crop_x`, `crop_y`, `crop_width`,
     * `crop_height`), colonnes `#[Hidden]` qui ne quittent jamais le serveur.
     */
    public static function fromFrame(Frame $frame): self
    {
        return new self(
            x: $frame->crop_x,
            y: $frame->crop_y,
            width: $frame->crop_width,
            height: $frame->crop_height,
        );
    }

    /**
     * Depuis la forme de {@see self::toArray()}.
     *
     * Stricte : chaque clé doit être présente et porter un entier. Une chaîne
     * numérique est refusée plutôt que convertie en silence — le contrôleur
     * lit la requête par ses accesseurs typés avant de construire le cadre.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException Une clé absente ou non entière.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            x: self::integer($data, 'x'),
            y: self::integer($data, 'y'),
            width: self::integer($data, 'width'),
            height: self::integer($data, 'height'),
        );
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function toArray(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'CropRect : la clé [%s] manque ou ne porte pas un entier.',
                $key,
            ));
        }

        return $value;
    }
}
