<?php

namespace App\Support\Frames;

use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameProcessingState;
use App\Models\Frame;
use App\Models\Movie;
use App\Settings\PlatformLimits;
use App\Support\Catalog\MovieProjector;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickException;
use InvalidArgumentException;
use Throwable;

/**
 * La chaîne Imagick d'une frame — contrat C9, spec 20 § 5.5.
 *
 * Appelée par le job `ProcessFrameImage` et par rien d'autre : **aucun
 * traitement Imagick n'a lieu dans une requête HTTP**. Une image par appel.
 *
 * 1. `Imagick::setResourceLimit` est posé **avant toute lecture**, depuis
 *    `catalog.curation.imagick.*`.
 * 2. **Refus durs** avant tout fichier : une frame `withdrawn` (aucun fichier
 *    n'est écrit, spec 10 § 10) ou `published` (un traitement ne réécrit
 *    jamais une frame en jeu, n° 16).
 * 3. **Normalisation du master** : format lu par `finfo`, image statique,
 *    largeur, paysage et plancher possible, puis `stripImage()`, sRGB,
 *    largeur exacte `FrameGeometry::MASTER_WIDTH`, WebP à
 *    `webp.master_quality`, écrit À LA PLACE des octets provisoires sous le
 *    même `master_path`. Sautée quand le master est déjà le nôtre (voir
 *    {@see self::isNormalizedMaster()}) : aucune perte de génération au
 *    re-recadrage.
 * 4. **Dérivé de jeu** : rectangle revalidé sur le master RÉEL, découpe,
 *    exactement `GAME_WIDTH` × `GAME_HEIGHT`, `stripImage()`, WebP à qualité
 *    descendante jusqu'à passer sous `FrameGeometry::gameEncodeCeilingBytes()`,
 *    puis {@see WebpPadding::pad()}.
 * 5. Écriture sous un **nouveau** nom, bascule dans UNE transaction sous
 *    `lockForUpdate` (le film, puis la frame) avec `MovieProjector::recompute`,
 *    suppression de l'ancien dérivé **après** le commit.
 *
 * Un échec connu lève {@see FrameProcessingException}, porteuse de la clé
 * {@see FrameProcessingFailure} que le job écrit ; toute autre panne remonte
 * telle quelle, transitoire, pour que la file la rejoue.
 *
 * Imagick seul : ni `gd` ni `exif` ne sont installées, et on ne LIT jamais
 * l'EXIF — on le supprime.
 */
final class FrameImageProcessor
{
    /** Formats d'entrée admis, tels que `finfo` les nomme. */
    private const array SOURCE_MIME_TYPES = ['image/jpeg', 'image/png', self::WEBP_MIME_TYPE];

    private const string WEBP_MIME_TYPE = 'image/webp';

    /** Format d'écriture Imagick des deux préfixes. */
    private const string WEBP_FORMAT = 'webp';

    /** Aplat sur lequel une source à canal alpha est aplatie : une image de jeu est opaque. */
    private const string FLATTEN_BACKGROUND = 'black';

    private const int BYTES_PER_MEGABYTE = 1_048_576;

    private const int PIXELS_PER_MEGAPIXEL = 1_000_000;

    /** Flou neutre du filtre de redimensionnement (1 = ni flou, ni accentuation). */
    private const float RESIZE_BLUR = 1.0;

    /**
     * Gravités ImageMagick d'une limite de ressources : `ResourceLimitWarning`,
     * `ResourceLimitError`, `ResourceLimitFatalError`, puis le cache de pixels
     * épuisé (`CacheWarning`, `CacheError`, `CacheFatalError`).
     */
    private const array RESOURCE_LIMIT_SEVERITIES = [300, 400, 700, 345, 445, 745];

    /** Même diagnostic par le texte, quand un décodeur renvoie sa propre gravité. */
    private const string RESOURCE_LIMIT_PATTERN = '/ResourceLimit|CacheResourcesExhausted|TimeLimitExceeded|exceeds limit/i';

    public function __construct(
        private readonly PlatformLimits $limits,
        private readonly MovieProjector $projector,
    ) {}

    /**
     * Traite une frame de bout en bout, ou lève.
     *
     * @throws FrameProcessingException Un échec connu, porteur de sa cause.
     */
    public function process(Frame $frame): void
    {
        self::applyResourceLimits();

        try {
            $this->refuseLocked($frame);

            $disk = Storage::disk(FrameStoragePrefix::DISK);
            [$masterPath, $masterBytes] = $this->readMaster($frame, $disk);

            if (! $this->isNormalizedMaster($frame, $masterBytes)) {
                $masterBytes = $this->normalizeMaster($masterBytes);
                $disk->put($masterPath, $masterBytes);
            }

            $game = $this->deriveGame($frame, $masterBytes);

            $this->commit($frame, $game, $disk);
        } finally {
            self::liftTimeLimit();
        }
    }

    /**
     * Étape 1 : les plafonds d'Imagick, posés avant toute lecture.
     *
     * `area` s'exprime en pixels, `memory` et `map` en octets, `time` en
     * secondes. Les trois derniers types n'existent qu'à partir d'une certaine
     * version d'ImageMagick : ils sont posés quand l'extension les expose,
     * et la largeur et la hauteur sont de toute façon refusées avant décodage
     * par {@see self::normalizeMaster()}.
     */
    private static function applyResourceLimits(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, self::setting('imagick.memory_mb') * self::BYTES_PER_MEGABYTE);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, self::setting('imagick.map_mb') * self::BYTES_PER_MEGABYTE);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, self::setting('imagick.area_mpx') * self::PIXELS_PER_MEGAPIXEL);

        self::optionalLimit('WIDTH', self::setting('imagick.width_px'));
        self::optionalLimit('HEIGHT', self::setting('imagick.height_px'));
        self::optionalLimit('TIME', self::setting('imagick.time_s'));
    }

    /**
     * Lève la limite de temps une fois l'image traitée.
     *
     * ImageMagick compte ce temps depuis la dernière pose de la limite, pour
     * tout le processus : laissée en place, elle frapperait une opération
     * Imagick étrangère à ce traitement longtemps après lui, dans un worker qui
     * vit une heure — et une limite de temps atteinte termine le processus.
     */
    private static function liftTimeLimit(): void
    {
        self::optionalLimit('TIME', PHP_INT_MAX);
    }

    private static function optionalLimit(string $type, int $value): void
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
     * Étape 2 : refus durs, avant toute lecture de fichier.
     *
     * @throws FrameProcessingException
     */
    private function refuseLocked(Frame $frame): void
    {
        if ($frame->availability === ContentAvailability::Withdrawn) {
            throw FrameProcessingException::because(FrameProcessingFailure::Withdrawn, 'Frame retirée : aucun fichier n’est écrit.');
        }

        if ($frame->availability === ContentAvailability::Published) {
            throw FrameProcessingException::because(FrameProcessingFailure::Published, 'Frame publiée : un traitement ne réécrit jamais une frame en jeu.');
        }
    }

    /**
     * Le chemin et les octets sous `master_path` : provisoires (l'original
     * déposé par la requête d'ajout) ou master déjà normalisé.
     *
     * @return array{0: string, 1: string}
     *
     * @throws FrameProcessingException
     */
    private function readMaster(Frame $frame, Filesystem $disk): array
    {
        $path = $frame->master_path;

        if ($path === null || ! FrameStoragePrefix::Master->owns($path) || ! $disk->exists($path)) {
            throw FrameProcessingException::because(FrameProcessingFailure::SourceMissing, 'Aucun fichier sous master_path.');
        }

        $bytes = $disk->get($path);

        if ($bytes === null || $bytes === '') {
            throw FrameProcessingException::because(FrameProcessingFailure::SourceMissing, 'Fichier master vide ou illisible.');
        }

        return [$path, $bytes];
    }

    /**
     * Vrai si les octets sont un master que CETTE chaîne a déjà produit : un
     * WebP de `MASTER_WIDTH` de large qui n'est pas l'original reçu.
     *
     * La seconde condition compte : les octets provisoires sont l'original, et
     * leur SHA-256 est `source_hash`. Un original qui serait déjà un WebP de
     * 1920 de large — la voie capture en enverra — ne saute donc jamais la
     * normalisation, qui porte le refus de l'animation et `stripImage()`, et
     * aucun original n'est conservé au-delà du premier traitement réussi.
     */
    private function isNormalizedMaster(Frame $frame, string $bytes): bool
    {
        if (self::mimeType($bytes) !== self::WEBP_MIME_TYPE) {
            return false;
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] !== FrameGeometry::MASTER_WIDTH) {
            return false;
        }

        return ! hash_equals($frame->source_hash, hash('sha256', $bytes));
    }

    /**
     * Étape 3 : l'original devient le master — dépouillé, sRGB, opaque, WebP
     * de largeur exactement `MASTER_WIDTH`.
     *
     * @throws FrameProcessingException
     */
    private function normalizeMaster(string $source): string
    {
        if (! in_array(self::mimeType($source), self::SOURCE_MIME_TYPES, true)) {
            throw FrameProcessingException::because(FrameProcessingFailure::SourceFormat, 'Format de source hors de la liste admise.');
        }

        // Une source absurde est refusée sur son en-tête, avant tout décodage.
        $size = @getimagesizefromstring($source);

        if ($size === false) {
            throw FrameProcessingException::because(FrameProcessingFailure::SourceUnreadable, 'En-tête d’image illisible.');
        }

        if ($size[0] > self::setting('imagick.width_px') || $size[1] > self::setting('imagick.height_px')) {
            throw FrameProcessingException::because(FrameProcessingFailure::ResourceLimit, sprintf(
                'Source de %d × %d au-delà des limites Imagick.',
                $size[0],
                $size[1],
            ));
        }

        $image = self::decode($source);

        try {
            if ($image->getNumberImages() > 1) {
                throw FrameProcessingException::because(FrameProcessingFailure::SourceAnimated, 'Source animée.');
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if ($width < FrameGeometry::GAME_WIDTH) {
                throw FrameProcessingException::because(FrameProcessingFailure::SourceTooSmall, sprintf(
                    'Source large de %d px, sous %d px.',
                    $width,
                    FrameGeometry::GAME_WIDTH,
                ));
            }

            if ($height > $width) {
                throw FrameProcessingException::because(FrameProcessingFailure::SourceAspect, 'Source en portrait.');
            }

            $masterHeight = FrameGeometry::masterHeightFor($width, $height);

            if (FrameGeometry::maxCropWidth($masterHeight, $this->limits) < $this->limits->frameCropMinWidthPx) {
                throw FrameProcessingException::because(FrameProcessingFailure::SourceAspect, 'Aucun cadre admis sur ce master.');
            }

            return self::imagick(FrameProcessingFailure::Unexpected, 'normalisation du master', static function () use ($image, $width, $masterHeight): string {
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);

                if ($image->getImageAlphaChannel()) {
                    $image->setImageBackgroundColor(self::FLATTEN_BACKGROUND);
                    $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                }

                // EXIF, XMP, IPTC, ICC et commentaires : aucune métadonnée ne
                // survit, et aucune n'est lue.
                $image->stripImage();

                if ($width !== FrameGeometry::MASTER_WIDTH) {
                    $image->resizeImage(FrameGeometry::MASTER_WIDTH, $masterHeight, Imagick::FILTER_LANCZOS, self::RESIZE_BLUR);
                }

                return self::encodeWebp($image, self::setting('webp.master_quality'));
            });
        } finally {
            $image->clear();
        }
    }

    /**
     * Étape 4 : le dérivé servi, paddé.
     *
     * @throws FrameProcessingException
     */
    private function deriveGame(Frame $frame, string $masterBytes): string
    {
        $image = self::decode($masterBytes);

        try {
            if ($image->getNumberImages() > 1 || $image->getImageWidth() !== FrameGeometry::MASTER_WIDTH) {
                throw FrameProcessingException::because(FrameProcessingFailure::Unexpected, 'Master hors format.');
            }

            $masterHeight = $image->getImageHeight();
            $crop = CropRect::fromFrame($frame);

            try {
                $violation = FrameGeometry::violation($crop, $masterHeight, $this->limits);
            } catch (InvalidArgumentException $exception) {
                throw FrameProcessingException::because(FrameProcessingFailure::CropInvalid, $exception->getMessage(), $exception);
            }

            if ($violation !== null) {
                throw FrameProcessingException::because(FrameProcessingFailure::CropInvalid, sprintf(
                    'Rectangle refusé sur le master réel : %s.',
                    $violation->value,
                ));
            }

            self::imagick(FrameProcessingFailure::Unexpected, 'découpe du dérivé', static function () use ($image, $crop): void {
                $image->cropImage($crop->width, $crop->height, $crop->x, $crop->y);
                $image->setImagePage(0, 0, 0, 0);

                if ($crop->width !== FrameGeometry::GAME_WIDTH) {
                    $image->resizeImage(FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT, Imagick::FILTER_LANCZOS, self::RESIZE_BLUR);
                }

                $image->stripImage();
            });

            $encoded = $this->encodeUnderCeiling($image);
        } finally {
            $image->clear();
        }

        $size = @getimagesizefromstring($encoded);

        if ($size === false
            || $size[0] !== FrameGeometry::GAME_WIDTH
            || $size[1] !== FrameGeometry::GAME_HEIGHT
            || self::mimeType($encoded) !== self::WEBP_MIME_TYPE) {
            throw FrameProcessingException::because(FrameProcessingFailure::Unexpected, 'Dérivé encodé hors format.');
        }

        try {
            return WebpPadding::pad($encoded);
        } catch (InvalidArgumentException $exception) {
            throw FrameProcessingException::because(FrameProcessingFailure::Unexpected, $exception->getMessage(), $exception);
        }
    }

    /**
     * Qualité descendante, de `game_quality_start` à `game_quality_min` par pas
     * de `game_quality_step`, jusqu'au premier encodage qui tient sous le
     * plafond d'encodage — dont le multiple paddé tient sous `GAME_MAX_BYTES`.
     *
     * @throws FrameProcessingException
     */
    private function encodeUnderCeiling(Imagick $image): string
    {
        $ceiling = FrameGeometry::gameEncodeCeilingBytes();

        foreach (self::gameQualities() as $quality) {
            $encoded = self::imagick(
                FrameProcessingFailure::Unexpected,
                'encodage du dérivé',
                static fn (): string => self::encodeWebp($image, $quality),
            );

            if (strlen($encoded) <= $ceiling) {
                return $encoded;
            }
        }

        throw FrameProcessingException::because(FrameProcessingFailure::TooHeavy, sprintf(
            'Dérivé au-delà de %d octets à la qualité minimale.',
            $ceiling,
        ));
    }

    /**
     * Les qualités essayées, la minimale toujours comprise, même quand l'écart
     * n'est pas un multiple du pas.
     *
     * @return list<int>
     */
    private static function gameQualities(): array
    {
        $start = self::setting('webp.game_quality_start');
        $minimum = self::setting('webp.game_quality_min');
        $step = max(1, self::setting('webp.game_quality_step'));

        $qualities = [];

        for ($quality = $start; $quality > $minimum; $quality -= $step) {
            $qualities[] = $quality;
        }

        $qualities[] = $minimum;

        return $qualities;
    }

    /**
     * Étape 5 : écriture sous un nouveau nom, bascule transactionnelle, puis
     * suppression de l'ancien dérivé.
     *
     * @throws FrameProcessingException
     */
    private function commit(Frame $frame, string $game, Filesystem $disk): void
    {
        $newPath = FrameStoragePrefix::Game->newPath();
        $disk->put($newPath, $game);

        $refusal = null;
        $oldPath = null;

        try {
            DB::transaction(function () use ($frame, $game, $newPath, &$refusal, &$oldPath): void {
                // Le film d'abord, puis la frame : l'ordre d'AddFrame et des
                // gestes sur une image (L20-8). Un geste concurrent sur une
                // autre image du film a donc commité avant que le recalcul de
                // la projection ne lise ses frames, et ne l'écrase jamais
                // d'un état périmé.
                $movie = Movie::query()->whereKey($frame->movie_id)->lockForUpdate()->first();
                $locked = Frame::query()->lockForUpdate()->find($frame->id);

                if ($movie === null || $locked === null) {
                    $refusal = FrameProcessingFailure::Unexpected;

                    return;
                }

                // La frame a pu être retirée ou publiée pendant le traitement.
                if ($locked->availability === ContentAvailability::Withdrawn) {
                    $refusal = FrameProcessingFailure::Withdrawn;

                    return;
                }

                if ($locked->availability === ContentAvailability::Published) {
                    $refusal = FrameProcessingFailure::Published;

                    return;
                }

                $oldPath = $locked->game_path;

                $locked->forceFill([
                    'game_path' => $newPath,
                    'game_bytes' => strlen($game),
                    'game_width' => FrameGeometry::GAME_WIDTH,
                    'game_height' => FrameGeometry::GAME_HEIGHT,
                    'published_hash' => hash('sha256', $game),
                    'processing_state' => FrameProcessingState::Ready,
                    'processing_error' => null,
                ])->save();

                $this->projector->recompute($movie);
            });
        } catch (Throwable $exception) {
            self::deleteQuietly($disk, $newPath);

            throw $exception;
        }

        if ($refusal instanceof FrameProcessingFailure) {
            self::deleteQuietly($disk, $newPath);

            throw FrameProcessingException::because($refusal, 'Bascule abandonnée : la frame a changé d’état pendant le traitement.');
        }

        if (is_string($oldPath) && $oldPath !== $newPath) {
            self::deleteQuietly($disk, $oldPath);
        }

        $frame->refresh();
    }

    /**
     * Décode des octets, une seule fois, sous les limites posées.
     *
     * @throws FrameProcessingException
     */
    private static function decode(string $bytes): Imagick
    {
        return self::imagick(FrameProcessingFailure::SourceUnreadable, 'décodage', static function () use ($bytes): Imagick {
            $image = new Imagick;
            $image->readImageBlob($bytes);

            return $image;
        });
    }

    /**
     * WebP avec perte, à la qualité donnée, sans métadonnée.
     */
    private static function encodeWebp(Imagick $image, int $quality): string
    {
        $image->setImageFormat(self::WEBP_FORMAT);
        $image->setOption('webp:lossless', 'false');
        $image->setImageCompressionQuality($quality);

        return $image->getImageBlob();
    }

    /**
     * Exécute une opération Imagick et traduit son échec : une limite de
     * ressources devient `resource_limit`, toute autre erreur la cause donnée.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     *
     * @throws FrameProcessingException
     */
    private static function imagick(FrameProcessingFailure $otherwise, string $step, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ImagickException $exception) {
            $failure = self::isResourceLimit($exception) ? FrameProcessingFailure::ResourceLimit : $otherwise;

            throw FrameProcessingException::because($failure, sprintf('Imagick, %s : %s', $step, $exception->getMessage()), $exception);
        }
    }

    private static function isResourceLimit(ImagickException $exception): bool
    {
        return in_array($exception->getCode(), self::RESOURCE_LIMIT_SEVERITIES, true)
            || preg_match(self::RESOURCE_LIMIT_PATTERN, $exception->getMessage()) === 1;
    }

    private static function mimeType(string $bytes): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mime) ? $mime : '';
    }

    /**
     * Un fichier qui ne se supprime pas ne fait jamais échouer un traitement
     * réussi : il est consigné, et l'orphelin se retrouve par son préfixe.
     */
    private static function deleteQuietly(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable $exception) {
            Log::warning('Traitement d’image : fichier non supprimé.', [
                'prefix' => FrameStoragePrefix::fromPath($path)?->value,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Une clé entière du bloc `catalog.curation` (spec 20 § 13.7).
     */
    private static function setting(string $key): int
    {
        return Config::integer('catalog.curation.'.$key);
    }
}
