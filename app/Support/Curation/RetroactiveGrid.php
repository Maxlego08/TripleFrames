<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Models\Frame;
use App\Models\MovieProjection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le périmètre du **geste rétroactif de grille** (spec 20 § 7.2 et § 7.7,
 * D13 du 23/09, L20-25) : la version courante de la grille d'exclusion, son
 * drapeau `retroactive` et les niveaux que touchent les items qu'elle ajoute.
 *
 * Lu par défaut sur {@see ExclusionGrid} — `CURRENT_VERSION`,
 * `isRetroactive()`, `retroactiveLevels()` —, et **injectable** : la table des
 * versions publiées est figée et n'admet aucune version de test (§ 7.2
 * point 1), si bien que la preuve du geste lie au conteneur une instance
 * construite sur une version fictive. Aucune autre voie n'écrit ces valeurs.
 *
 * Frames visées : `published`, revue à une version **antérieure** (ou jamais
 * revues), et d'un niveau de {@see self::$levels}. Une image re-revue à la
 * version courante n'est jamais visée : le geste est idempotent.
 */
final readonly class RetroactiveGrid
{
    public int $version;

    public bool $retroactive;

    /** @var list<FrameLevel> */
    public array $levels;

    /**
     * @param  list<FrameLevel>|null  $levels
     */
    public function __construct(?int $version = null, ?bool $retroactive = null, ?array $levels = null)
    {
        $this->version = $version ?? ExclusionGrid::CURRENT_VERSION;
        $this->retroactive = $retroactive ?? ExclusionGrid::isRetroactive($this->version);
        $this->levels = $levels ?? ExclusionGrid::retroactiveLevels($this->version);
    }

    /** Le geste n'est offert que sous une version rétroactive qui touche au moins un niveau. */
    public function isAvailable(): bool
    {
        return $this->retroactive && $this->levels !== [];
    }

    /**
     * Les frames que le geste dépublierait, toutes ou celles d'un film.
     *
     * @return Builder<Frame>
     */
    public function eligibleFrames(?int $movieId = null): Builder
    {
        $levels = array_map(static fn (FrameLevel $level): int => $level->value, $this->levels);

        return Frame::query()
            ->where('availability', ContentAvailability::Published->value)
            ->whereIn('frame_level', $levels === [] ? [0] : $levels)
            ->where(function (Builder $query): void {
                $query->whereNull('review_grid_version')
                    ->orWhere('review_grid_version', '<', $this->version);
            })
            ->when($movieId !== null, static fn (Builder $query): Builder => $query->where('movie_id', $movieId));
    }

    /**
     * L'écran de confirmation (§ 7.7) : par film, le nombre d'images visées
     * et, pour un film **publié** que le geste rendrait incomplet, le plus
     * grand `N` encore jouable après le geste (calcul du § 8.4,
     * {@see CoverageLossPreview::playableUpTo()}), `null` s'il n'y en a plus.
     * Lecture seule.
     *
     * @return list<array{movie_id: int, frames: int, becomes_incomplete: bool, playable_up_to: int|null}>
     */
    public function preview(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        /** @var array<int, array<int, int>> $perMovie movie_id → frame_level → images visées */
        $perMovie = [];

        $counts = $this->eligibleFrames()
            ->toBase()
            ->selectRaw('movie_id, frame_level, COUNT(*) AS aggregate')
            ->groupBy('movie_id', 'frame_level')
            ->orderBy('movie_id')
            ->get();

        foreach ($counts as $row) {
            $perMovie[(int) $row->movie_id][(int) $row->frame_level] = (int) $row->aggregate;
        }

        if ($perMovie === []) {
            return [];
        }

        $projections = MovieProjection::query()
            ->with('movie:id,availability')
            ->whereIn('movie_id', array_keys($perMovie))
            ->get()
            ->keyBy('movie_id');

        $rows = [];

        foreach ($perMovie as $movieId => $levels) {
            $projection = $projections->get($movieId);
            $becomesIncomplete = false;
            $playableUpTo = null;

            if ($projection instanceof MovieProjection) {
                $maskAfter = $projection->levels_mask;

                foreach ($levels as $level => $count) {
                    $frameLevel = FrameLevel::from($level);

                    if ($projection->variantsForLevel($frameLevel) <= $count) {
                        $maskAfter &= ~$frameLevel->bit();
                    }
                }

                $publishable = MovieProjection::publishableLevelsMask();
                $becomesIncomplete = $projection->movie->availability === ContentAvailability::Published
                    && $projection->coversPublishableLevels()
                    && ($maskAfter & $publishable) !== $publishable;
                $playableUpTo = CoverageLossPreview::playableUpTo($maskAfter);
            }

            $rows[] = [
                'movie_id' => $movieId,
                'frames' => array_sum($levels),
                'becomes_incomplete' => $becomesIncomplete,
                'playable_up_to' => $playableUpTo,
            ];
        }

        return $rows;
    }
}
