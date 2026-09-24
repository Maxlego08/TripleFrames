<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\ReviewDecision;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Settings\RoomSettingsBounds;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * La banque d'images d'un film, lue UNE fois pour l'éditeur (spec 20 § 6) :
 * ses frames, l'état affiché de chacune, la couverture par niveau (§ 6.6), les
 * séquences que verrait un salon à chaque `N` (§ 6.7) et les niveaux tirés de
 * chaque visuel TMDB (§ 6.2).
 *
 * **Lecture seule**, et **paresseuse** : rien n'est lu avant la première
 * question, puis deux requêtes en tout — les frames du film, par le préfixe
 * `movie_id` de `frame_movie_level_idx`, et les revues de ces frames à la
 * version courante de la grille —, plus la projection. Un rechargement
 * partiel qui ne demande que `frames` ne lit donc ni plus ni moins. Aucun
 * nombre de requêtes ne dépend du nombre d'images.
 *
 * Les états se dérivent en PHP, jamais par une comparaison de dates en SQL
 * (SQLite en test, MySQL ailleurs) : quelques dizaines de lignes au plus par
 * film.
 */
final class FrameBankSnapshot
{
    /**
     * Cible de la passe 2 (§ 6.6) : deux variantes jouables sur chaque niveau
     * du masque de publication (1, 3 et 5), une sur chacun des autres — huit
     * images par film. **Objectif de curation, jamais condition de
     * publication** (`00` § Catalogue) : ni le vivier ni le tirage ne le
     * lisent, et ce n'est pas une valeur de jeu (règle 2).
     */
    public const int PASS_TWO_VARIANTS_ON_PUBLISHABLE_LEVELS = 2;

    public const int PASS_TWO_VARIANTS_ON_OTHER_LEVELS = 1;

    /** @var Collection<int, Frame>|null */
    private ?Collection $frames = null;

    /** @var array<int, FrameCurationState> état affiché, par identifiant de frame */
    private array $states = [];

    /** @var array<int, ReviewDecision> dernière décision de revue qui juge encore la frame, par identifiant */
    private array $lastDecisions = [];

    private bool $projectionLoaded = false;

    private ?MovieProjection $projection = null;

    public function __construct(public readonly Movie $movie) {}

    /**
     * Les frames du film, par niveau puis de la plus ancienne à la plus
     * récente — l'ordre de la banque affichée et celui du choix de la
     * variante en prévisualisation. Aucune n'est jamais écartée de la liste :
     * une image écartée ou retirée s'affiche avec son état.
     *
     * @return Collection<int, Frame>
     */
    public function frames(): Collection
    {
        if ($this->frames === null) {
            $this->load();
        }

        /** @var Collection<int, Frame> $frames */
        $frames = $this->frames;

        return $frames;
    }

    /**
     * L'état affiché d'une frame de ce film.
     */
    public function stateOf(Frame $frame): FrameCurationState
    {
        $this->frames();

        return $this->states[$frame->id] ?? FrameCurationState::of($frame, null);
    }

    /**
     * Vrai si la dernière revue qui juge encore cette frame — sur ses octets
     * courants, à la version courante de la grille, depuis son dernier
     * changement d'état — est rejetée, **quelle que soit sa disponibilité**.
     *
     * L'état affiché ne suffit pas : une image EN JEU rejetée en re-revue
     * reste en jeu jusqu'à décision (§ 7.5), donc `in_play`, alors que la
     * liste « Rejetées » du § 7.3 la compte. Ce drapeau la signale à
     * l'éditeur.
     */
    public function reviewRejected(Frame $frame): bool
    {
        $this->frames();

        return ($this->lastDecisions[$frame->id] ?? null) === ReviewDecision::Rejected;
    }

    /**
     * La projection du film, nullable pour de vrai : un film restauré avant
     * `catalog:reproject` n'en a pas, et l'éditeur affiche alors zéro.
     */
    public function projection(): ?MovieProjection
    {
        if (! $this->projectionLoaded) {
            $this->projection = MovieProjection::query()->find($this->movie->id);
            $this->projectionLoaded = true;
        }

        return $this->projection;
    }

    /**
     * L'indicateur de couverture (§ 6.6), un niveau par ligne, de 1 à 5.
     *
     * - `playable` : variantes JOUABLES, lues sur `movie_projection` — le
     *   prédicat unique de `10` § 3.2, jamais recompté ici ;
     * - `processing`, `awaiting_review`, `rejected`, `failed` : les autres
     *   images du niveau, lues sur `frame` par leur état affiché ;
     * - `target` : la cible de la passe 2 ; `single_variant` : un niveau du
     *   masque de publication qui ne tient qu'à une variante jouable — signal
     *   de back-office, jamais montré à un joueur.
     *
     * `pass` vaut 1 tant que les niveaux 1, 3 et 5 ne sont pas tous couverts,
     * 2 ensuite ; `incomplete` dit un film publié qui a perdu cette
     * couverture — il reste publié, jouable jusqu'à `playable_up_to` avec
     * repli de niveau (E10-23).
     *
     * @return array{
     *     levels: list<array{level: int, playable: int, processing: int, awaiting_review: int, rejected: int, failed: int, target: int, single_variant: bool}>,
     *     covers_publishable: bool,
     *     pass: int,
     *     target_reached: bool,
     *     incomplete: bool,
     *     playable_up_to: int|null,
     * }
     */
    public function coverage(): array
    {
        $projection = $this->projection();
        $publishable = MovieProjection::publishableLevelsMask();

        $counted = [
            FrameCurationState::Processing->value => 'processing',
            FrameCurationState::AwaitingReview->value => 'awaiting_review',
            FrameCurationState::Rejected->value => 'rejected',
            FrameCurationState::Failed->value => 'failed',
        ];

        $levels = [];
        $targetReached = true;

        foreach (FrameLevel::cases() as $level) {
            $onPublishable = ($level->bit() & $publishable) !== 0;
            $playable = $projection?->variantsForLevel($level) ?? 0;
            $target = $onPublishable
                ? self::PASS_TWO_VARIANTS_ON_PUBLISHABLE_LEVELS
                : self::PASS_TWO_VARIANTS_ON_OTHER_LEVELS;

            $row = [
                'level' => $level->value,
                'playable' => $playable,
                'processing' => 0,
                'awaiting_review' => 0,
                'rejected' => 0,
                'failed' => 0,
                'target' => $target,
                'single_variant' => $onPublishable && $playable === 1,
            ];

            foreach ($this->frames() as $frame) {
                $column = $counted[$this->stateOf($frame)->value] ?? null;

                if ($column !== null && $frame->frame_level === $level) {
                    $row[$column]++;
                }
            }

            $targetReached = $targetReached && $playable >= $target;
            $levels[] = $row;
        }

        $covers = $projection?->coversPublishableLevels() ?? false;

        return [
            'levels' => $levels,
            'covers_publishable' => $covers,
            'pass' => $covers ? 2 : 1,
            'target_reached' => $targetReached,
            'incomplete' => $this->movie->availability === ContentAvailability::Published && ! $covers,
            'playable_up_to' => CoverageLossPreview::playableUpTo($projection->levels_mask ?? 0),
        ];
    }

    /**
     * Ce que verrait un salon à chaque `N` permis par `RoomSettingsBounds`
     * (§ 6.7), sur deux masques : « en jeu » (frames servables) et « après
     * revue » (servables, plus les images prêtes en attente de revue). Les
     * niveaux sont ceux de `FrameLevelCoverage::select()` (C1), repli de
     * niveau compris, et chaque niveau montre sa variante la PLUS ANCIENNE ;
     * le tirage réel, lui, suit la mémoire du salon (`30`).
     *
     * @param  Closure(Frame): string  $gameUrl  l'URL d'aperçu du dérivé d'une frame
     * @return list<array{
     *     frames_per_round: int,
     *     in_play: array{playable: bool, levels: list<int>, usesFallback: bool, frames: list<array{level: int, game_url: string}>},
     *     after_review: array{playable: bool, levels: list<int>, usesFallback: bool, frames: list<array{level: int, game_url: string}>},
     * }>
     */
    public function sequences(Closure $gameUrl): array
    {
        $inPlay = [];
        $afterReview = [];

        foreach ($this->frames() as $frame) {
            $servable = $frame->isServable();

            if ($servable) {
                $inPlay[$frame->frame_level->value] ??= $frame;
            }

            if ($servable || $this->stateOf($frame) === FrameCurationState::AwaitingReview) {
                $afterReview[$frame->frame_level->value] ??= $frame;
            }
        }

        $sequences = [];

        for ($framesPerRound = RoomSettingsBounds::MIN_FRAMES_PER_ROUND; $framesPerRound <= RoomSettingsBounds::MAX_FRAMES_PER_ROUND; $framesPerRound++) {
            $sequences[] = [
                'frames_per_round' => $framesPerRound,
                'in_play' => self::sequence($framesPerRound, $inPlay, $gameUrl),
                'after_review' => self::sequence($framesPerRound, $afterReview, $gameUrl),
            ];
        }

        return $sequences;
    }

    /**
     * Les niveaux des images qui proviennent de chaque visuel TMDB, ni
     * retirées ni écartées (§ 6.2), croissants et sans doublon — le badge
     * « déjà utilisé » de la grille. Calculés ici pour que
     * `frame.tmdb_file_path` ne sorte jamais dans les props d'une image.
     *
     * @return array<string, list<int>>
     */
    public function usedLevelsByFilePath(): array
    {
        $used = [];

        foreach ($this->frames() as $frame) {
            if ($frame->tmdb_file_path === null
                || $frame->availability === ContentAvailability::Withdrawn
                || $this->stateOf($frame) === FrameCurationState::SetAside) {
                continue;
            }

            $used[$frame->tmdb_file_path][$frame->frame_level->value] = $frame->frame_level->value;
        }

        $levels = [];

        foreach ($used as $path => $byLevel) {
            ksort($byLevel);
            $levels[$path] = array_values($byLevel);
        }

        return $levels;
    }

    /**
     * Vrai si une image attend son traitement depuis plus de `$minutes`
     * minutes (§ 13.5) : le traitement d'arrière-plan ne répond pas.
     * `updated_at` date sa dernière mise en file — un ajout, un re-recadrage
     * ou une relance passent tous l'image en `pending`.
     */
    public function processingStalled(CarbonImmutable $now, int $minutes): bool
    {
        $threshold = $now->subMinutes($minutes);

        foreach ($this->frames() as $frame) {
            $queuedAt = $frame->updated_at ?? $frame->created_at;

            if ($frame->processing_state === FrameProcessingState::Pending
                && $queuedAt !== null
                && $queuedAt->lt($threshold)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vrai si une image du film attend son traitement : l'éditeur se
     * recharge alors partiellement, sans que le curateur n'attende le job.
     */
    public function hasPending(): bool
    {
        return $this->frames()->contains(
            fn (Frame $frame): bool => $frame->processing_state === FrameProcessingState::Pending,
        );
    }

    /**
     * Une séquence pour un `N` et un choix de variantes par niveau.
     *
     * @param  array<int, Frame>  $byLevel  la variante retenue, par niveau
     * @param  Closure(Frame): string  $gameUrl
     * @return array{playable: bool, levels: list<int>, usesFallback: bool, frames: list<array{level: int, game_url: string}>}
     */
    private static function sequence(int $framesPerRound, array $byLevel, Closure $gameUrl): array
    {
        $mask = FrameLevelCoverage::maskOf(array_map(
            static fn (int $level): FrameLevel => FrameLevel::from($level),
            array_keys($byLevel),
        ));

        $levels = FrameLevelCoverage::select($framesPerRound, $mask);

        $frames = [];

        foreach ($levels ?? [] as $level) {
            $frames[] = [
                'level' => $level->value,
                'game_url' => $gameUrl($byLevel[$level->value]),
            ];
        }

        return [
            'playable' => $levels !== null,
            'levels' => array_map(static fn (FrameLevel $level): int => $level->value, $levels ?? []),
            'usesFallback' => FrameLevelCoverage::usesFallback($framesPerRound, $mask),
            'frames' => $frames,
        ];
    }

    /**
     * Les frames du film et l'état affiché de chacune, en deux requêtes.
     *
     * La dernière décision de revue d'une frame est celle de la plus récente
     * ligne `frame_review` à la version courante, portant sur ses octets
     * courants (`reviewed_hash = published_hash`) et postérieure ou égale à
     * son dernier changement d'état (`COALESCE(availability_changed_at,
     * created_at)`) — le prédicat des listes « à revoir » et « rejetées »
     * (§ 7.3). Une revue antérieure à un recadrage ou à une dépublication ne
     * juge plus rien.
     */
    private function load(): void
    {
        $frames = Frame::query()
            ->where('movie_id', $this->movie->id)
            ->orderBy('frame_level')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($frames as $frame) {
            $frame->setRelation('movie', $this->movie);
        }

        $reviews = $frames->isEmpty()
            ? new Collection
            : FrameReview::query()
                ->whereIn('frame_id', $frames->modelKeys())
                ->where('grid_version', ExclusionGrid::CURRENT_VERSION)
                ->orderBy('reviewed_at')
                ->orderBy('id')
                ->get(['id', 'frame_id', 'decision', 'reviewed_hash', 'reviewed_at']);

        /** @var array<int, ReviewDecision> $lastDecisions */
        $lastDecisions = [];
        $byId = $frames->keyBy('id');

        foreach ($reviews as $review) {
            $frame = $byId->get($review->frame_id);

            if (! $frame instanceof Frame
                || $frame->published_hash === null
                || $review->reviewed_hash !== $frame->published_hash) {
                continue;
            }

            $since = $frame->availability_changed_at ?? $frame->created_at;

            if ($since !== null && $review->reviewed_at->lt($since)) {
                continue;
            }

            $lastDecisions[$frame->id] = $review->decision;
        }

        foreach ($frames as $frame) {
            $this->states[$frame->id] = FrameCurationState::of($frame, $lastDecisions[$frame->id] ?? null);
        }

        $this->lastDecisions = $lastDecisions;

        $this->frames = $frames;
    }
}
