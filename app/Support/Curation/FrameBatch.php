<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Models\Frame;
use App\Models\Movie;
use App\Support\Frames\CropRect;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use JsonException;

/**
 * Un lot d'images proposées, transportable d'une base à l'autre — spec 20
 * § 5.10, D57 du 05/10.
 *
 * Le lot ne porte **que des références** : pour chaque film, son
 * identifiant TMDB, et pour chaque image, le chemin du backdrop TMDB, le
 * niveau et le cadre dans l'espace du master. Aucun octet, aucun chemin de
 * disque, aucun identifiant de base, aucune revue : la base qui l'importe
 * retélécharge l'original elle-même et rejoue toutes les gardes de la voie
 * TMDB ({@see TmdbFrameIntake}), et le curateur y passe la revue et la
 * publication comme pour une image ajoutée à la main. Le master étant dérivé
 * de l'original de façon déterministe (largeur fixe), un cadre exprimé sur
 * le master d'une base vaut sur celui de l'autre.
 *
 * Un même format sert les deux sens : la proposition locale (préparée hors
 * de l'application, cadre facultatif) et l'export (cadre toujours posé).
 *
 * @phpstan-type BatchFrame array{tmdb_file_path: string, level: FrameLevel, crop: CropRect|null}
 * @phpstan-type BatchMovie array{tmdb_id: int, title: string|null, frames: list<BatchFrame>}
 */
final readonly class FrameBatch
{
    /** Le nom du format, écrit en tête de tout lot. */
    public const string FORMAT = 'tripleframes.frame-batch';

    /** La seule version lue aujourd'hui. */
    public const int VERSION = 1;

    /** Plafond du nombre de films d'un lot. */
    public const int MAX_MOVIES = 200;

    /** Plafond du nombre d'images d'un film dans un lot. */
    public const int MAX_FRAMES_PER_MOVIE = 40;

    /**
     * Forme d'un chemin de visuel TMDB. Contrôle de FORME seulement : l'import
     * revérifie l'appartenance du chemin aux backdrops du film.
     */
    private const string FILE_PATH_PATTERN = '/^\/[A-Za-z0-9_-]+\.(?:jpg|png)$/D';

    /**
     * @param  list<BatchMovie>  $movies
     */
    public function __construct(public array $movies) {}

    /**
     * Lit un lot depuis son texte JSON.
     *
     * @throws FrameBatchException un texte illisible ou hors format, avec la clé de son motif
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FrameBatchException('admin.frame_batch.invalid.json');
        }

        if (! is_array($data)) {
            throw new FrameBatchException('admin.frame_batch.invalid.json');
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws FrameBatchException
     */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION) {
            throw new FrameBatchException('admin.frame_batch.invalid.format');
        }

        $rawMovies = $data['movies'] ?? null;

        if (! is_array($rawMovies) || ! array_is_list($rawMovies) || $rawMovies === []) {
            throw new FrameBatchException('admin.frame_batch.invalid.empty');
        }

        if (count($rawMovies) > self::MAX_MOVIES) {
            throw new FrameBatchException('admin.frame_batch.invalid.too_many_movies', ['max' => self::MAX_MOVIES]);
        }

        $movies = [];
        $seen = [];

        foreach ($rawMovies as $index => $rawMovie) {
            $movie = self::movie($rawMovie, $index + 1);

            if (isset($seen[$movie['tmdb_id']])) {
                throw new FrameBatchException('admin.frame_batch.invalid.duplicate_movie', ['tmdb_id' => $movie['tmdb_id']]);
            }

            $seen[$movie['tmdb_id']] = true;
            $movies[] = $movie;
        }

        return new self($movies);
    }

    /**
     * Le lot des images d'un ensemble de films, pour l'export.
     *
     * Entrent les images de **source TMDB**, au traitement réussi, ni
     * dépubliées ni écartées, ni suspendues ni retirées : `draft` ou
     * `published`. Une capture n'entre jamais — ses octets ne se
     * retéléchargent pas ; un film sans image retenue n'entre pas non plus.
     *
     * @param  Collection<int, Movie>  $movies  avec la relation `frames` chargée
     */
    public static function fromMovies(Collection $movies): self
    {
        $rows = [];

        foreach ($movies as $movie) {
            if ($movie->tmdb_id === null) {
                continue;
            }

            $frames = [];

            foreach ($movie->frames as $frame) {
                if (! self::exportable($frame) || $frame->tmdb_file_path === null) {
                    continue;
                }

                $frames[] = [
                    'tmdb_file_path' => $frame->tmdb_file_path,
                    'level' => $frame->frame_level,
                    'crop' => CropRect::fromFrame($frame),
                ];
            }

            if ($frames !== []) {
                $rows[] = [
                    'tmdb_id' => $movie->tmdb_id,
                    'title' => $movie->title_original,
                    'frames' => $frames,
                ];
            }
        }

        return new self($rows);
    }

    /** Vrai si l'image entre dans un export (voir {@see self::fromMovies()}). */
    public static function exportable(Frame $frame): bool
    {
        return $frame->source_kind === FrameSourceKind::Tmdb
            && $frame->processing_state === FrameProcessingState::Ready
            && in_array($frame->availability, [ContentAvailability::Draft, ContentAvailability::Published], true);
    }

    /**
     * Le lot découpé en lots qui tiennent chacun sous les plafonds de
     * l'import : au plus {@see self::MAX_MOVIES} films, et un texte JSON d'au
     * plus `$maxBytes` octets (amendé le 06/10). Découpage glouton, ordre des
     * films conservé ; un film seul au-delà de `$maxBytes` forme son propre
     * lot, que l'import refusera avec son motif.
     *
     * @return list<string> le texte JSON de chaque lot, prêt à écrire
     *
     * @throws JsonException
     */
    public function encodedChunks(int $maxBytes, ?CarbonImmutable $exportedAt = null): array
    {
        $exportedAt ??= CarbonImmutable::now();
        $chunks = [];
        $current = [];

        foreach ($this->movies as $movie) {
            $candidate = [...$current, $movie];

            if ($current !== [] && (count($candidate) > self::MAX_MOVIES || strlen((new self($candidate))->encode($exportedAt)) > $maxBytes)) {
                $chunks[] = (new self($current))->encode($exportedAt);
                $candidate = [$movie];
            }

            $current = $candidate;
        }

        if ($current !== []) {
            $chunks[] = (new self($current))->encode($exportedAt);
        }

        return $chunks;
    }

    /**
     * Le texte JSON du lot, tel que l'export l'écrit.
     *
     * @throws JsonException
     */
    public function encode(?CarbonImmutable $exportedAt = null): string
    {
        return json_encode($this->toArray($exportedAt), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).'
';
    }

    /** Le nombre total d'images du lot. */
    public function framesCount(): int
    {
        return array_sum(array_map(static fn (array $movie): int => count($movie['frames']), $this->movies));
    }

    /**
     * Le lot en données JSON, prêt à écrire.
     *
     * @return array{format: string, version: int, exported_at: string, movies: list<array{tmdb_id: int, title: string|null, frames: list<array{tmdb_file_path: string, level: int, crop: array{x: int, y: int, width: int, height: int}|null}>}>}
     */
    public function toArray(?CarbonImmutable $exportedAt = null): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => ($exportedAt ?? CarbonImmutable::now())->toIso8601String(),
            'movies' => array_map(static fn (array $movie): array => [
                'tmdb_id' => $movie['tmdb_id'],
                'title' => $movie['title'],
                'frames' => array_map(static fn (array $frame): array => [
                    'tmdb_file_path' => $frame['tmdb_file_path'],
                    'level' => $frame['level']->value,
                    'crop' => $frame['crop']?->toArray(),
                ], $movie['frames']),
            ], $this->movies),
        ];
    }

    /**
     * @return BatchMovie
     *
     * @throws FrameBatchException
     */
    private static function movie(mixed $raw, int $position): array
    {
        if (! is_array($raw)) {
            throw new FrameBatchException('admin.frame_batch.invalid.movie', ['position' => $position]);
        }

        $tmdbId = $raw['tmdb_id'] ?? null;
        $title = $raw['title'] ?? null;
        $rawFrames = $raw['frames'] ?? null;

        if (! is_int($tmdbId) || $tmdbId < 1 || ($title !== null && ! is_string($title))) {
            throw new FrameBatchException('admin.frame_batch.invalid.movie', ['position' => $position]);
        }

        if (! is_array($rawFrames) || ! array_is_list($rawFrames) || $rawFrames === []) {
            throw new FrameBatchException('admin.frame_batch.invalid.no_frames', ['tmdb_id' => $tmdbId]);
        }

        if (count($rawFrames) > self::MAX_FRAMES_PER_MOVIE) {
            throw new FrameBatchException('admin.frame_batch.invalid.too_many_frames', [
                'tmdb_id' => $tmdbId,
                'max' => self::MAX_FRAMES_PER_MOVIE,
            ]);
        }

        $frames = [];

        foreach ($rawFrames as $rawFrame) {
            $frames[] = self::frame($rawFrame, $tmdbId);
        }

        return [
            'tmdb_id' => $tmdbId,
            'title' => $title === null ? null : mb_substr($title, 0, 255),
            'frames' => $frames,
        ];
    }

    /**
     * @return BatchFrame
     *
     * @throws FrameBatchException
     */
    private static function frame(mixed $raw, int $tmdbId): array
    {
        $invalid = new FrameBatchException('admin.frame_batch.invalid.frame', ['tmdb_id' => $tmdbId]);

        if (! is_array($raw)) {
            throw $invalid;
        }

        $path = $raw['tmdb_file_path'] ?? null;
        $level = is_int($raw['level'] ?? null) ? FrameLevel::tryFrom($raw['level']) : null;
        $rawCrop = $raw['crop'] ?? null;

        if (! is_string($path) || preg_match(self::FILE_PATH_PATTERN, $path) !== 1 || $level === null) {
            throw $invalid;
        }

        $crop = null;

        if ($rawCrop !== null) {
            if (! is_array($rawCrop)) {
                throw $invalid;
            }

            foreach (['x', 'y', 'width', 'height'] as $key) {
                if (! is_int($rawCrop[$key] ?? null) || $rawCrop[$key] < 0) {
                    throw $invalid;
                }
            }

            $crop = CropRect::fromArray($rawCrop);
        }

        return ['tmdb_file_path' => $path, 'level' => $level, 'crop' => $crop];
    }
}
