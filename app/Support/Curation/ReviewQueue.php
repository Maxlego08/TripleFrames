<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Enums\ReviewDecision;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * La file de revue — spec 20 § 7.3, seul porteur de ses trois prédicats.
 *
 * - **À revoir** : image prête, ni elle ni son film suspendus ou retirés,
 *   `draft` ou dépubliée — jamais écartée (`unpublished` sans
 *   `first_published_at`, § 8.4) —, et sans AUCUNE revue qui la juge encore.
 * - **À re-revoir** : image publiée sous une version antérieure de la grille
 *   (`review_grid_version < CURRENT_VERSION`, § 7.7).
 * - **Rejetées** : image prête, ni écartée ni verrouillée par un
 *   administrateur, dont la revue qui la juge encore est rejetée.
 *
 * Une revue « juge encore » une image quand elle porte sur ses octets
 * courants (`reviewed_hash = published_hash`), à la version courante de la
 * grille, et qu'elle est postérieure ou égale à son dernier changement
 * d'état, `COALESCE(availability_changed_at, created_at)`. C'est ce qui
 * renvoie en revue, sans aucune colonne neuve (B12) : une image recadrée
 * (nouvelle empreinte), une image publiée changée de niveau (sortie du jeu
 * datée, octets et version inchangés), une image dépubliée — que seule une
 * revue remet en jeu. Le « ou égal » vient de la revue passante, qui écrit
 * `reviewed_at` et `availability_changed_at` au MÊME instant serveur.
 *
 * Deux usages, un seul jeu de prédicats :
 *
 * - l'écran de la file ({@see self::frames()}, {@see self::counts()}) : une
 *   pré-sélection SQL large et indexable — sans aucune comparaison de dates,
 *   qui ne s'écrit pas pareil en SQLite et en MySQL —, puis les prédicats
 *   exacts en PHP ;
 * - l'action de revue `App\Actions\Curation\ReviewFrame`, qui relit SOUS
 *   VERROU la revue qui juge l'image ({@see self::judgingReviewFor()}) et
 *   les listes auxquelles elle appartient ({@see self::listsOf()}) : un
 *   double envoi n'écrit jamais deux preuves.
 *
 * **Lecture seule**, et paresseuse : rien n'est lu avant la première
 * question, puis trois requêtes en tout, quel que soit le nombre d'images.
 */
final class ReviewQueue
{
    /** @var array<string, list<Frame>>|null les images de chaque liste, dans l'ordre de l'écran */
    private ?array $lists = null;

    /** @var array<int, FrameReview> la revue qui juge encore chaque image lue, par identifiant */
    private array $judging = [];

    /**
     * Les images d'une liste, regroupées par film (§ 7.3) : le contexte du
     * film aide à juger `no_identifying_text` et `no_lead_face`. Films dans
     * l'ordre de leur plus ancienne image de la liste — premier prêt, premier
     * revu —, images par niveau croissant puis par identifiant. Le film de
     * chaque image est chargé.
     *
     * @return list<Frame>
     */
    public function frames(ReviewList $list): array
    {
        return $this->lists()[$list->value];
    }

    /**
     * Le nombre d'images de chaque liste, par valeur de {@see ReviewList}.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return array_map(count(...), $this->lists());
    }

    /**
     * La revue qui juge encore une image de la file, déjà lue — ou `null`.
     */
    public function judgingReviewOf(Frame $frame): ?FrameReview
    {
        $this->lists();

        return $this->judging[$frame->id] ?? null;
    }

    /**
     * Les listes auxquelles appartient une image, sa revue qui la juge encore
     * lue ailleurs. Vide : l'image n'attend aucune revue — publiée et à jour,
     * écartée, en traitement, en échec, ou verrouillée. Une image publiée
     * rejetée en re-revue sous une ancienne grille appartient à deux listes.
     *
     * @return list<ReviewList>
     */
    public static function listsOf(Frame $frame, Movie $movie, ?ReviewDecision $judging): array
    {
        if (! self::isEligible($frame, $movie)) {
            return [];
        }

        $lists = [];

        if (self::isOutOfPlay($frame) && $judging === null) {
            $lists[] = ReviewList::ToReview;
        }

        if ($frame->availability === ContentAvailability::Published
            && $frame->review_grid_version !== null
            && $frame->review_grid_version < ExclusionGrid::CURRENT_VERSION) {
            $lists[] = ReviewList::ToReReview;
        }

        if ((self::isOutOfPlay($frame) || $frame->availability === ContentAvailability::Published)
            && $judging === ReviewDecision::Rejected) {
            $lists[] = ReviewList::Rejected;
        }

        return $lists;
    }

    /**
     * Vrai si la revue juge encore l'image : ses octets courants, la version
     * courante de la grille, et pas avant son dernier changement d'état.
     */
    public static function judges(FrameReview $review, Frame $frame): bool
    {
        if ($review->grid_version !== ExclusionGrid::CURRENT_VERSION
            || $frame->published_hash === null
            || $review->reviewed_hash !== $frame->published_hash) {
            return false;
        }

        $since = self::lastStateChange($frame);

        return $since === null || $review->reviewed_at->greaterThanOrEqualTo($since);
    }

    /**
     * La revue qui juge encore chaque image — la plus récente de celles qui
     * la jugent —, en une requête. Les images sans revue qui les juge n'ont
     * pas d'entrée.
     *
     * @param  Collection<int, Frame>  $frames
     * @return array<int, FrameReview>
     */
    public static function judgingReviews(Collection $frames): array
    {
        if ($frames->isEmpty()) {
            return [];
        }

        $byId = $frames->keyBy('id');

        $reviews = FrameReview::query()
            ->whereIn('frame_id', $frames->modelKeys())
            ->where('grid_version', ExclusionGrid::CURRENT_VERSION)
            ->orderBy('reviewed_at')
            ->orderBy('id')
            ->get(['id', 'frame_id', 'grid_version', 'decision', 'reviewed_hash', 'answers', 'reviewed_at']);

        $judging = [];

        foreach ($reviews as $review) {
            $frame = $byId->get($review->frame_id);

            if ($frame instanceof Frame && self::judges($review, $frame)) {
                $judging[$frame->id] = $review;
            }
        }

        return $judging;
    }

    /**
     * La revue qui juge encore UNE image, relue en base — sous le verrou de
     * l'appelant, qui a relu la frame avant.
     */
    public static function judgingReviewFor(Frame $frame): ?FrameReview
    {
        return self::judgingReviews(new Collection([$frame]))[$frame->id] ?? null;
    }

    /**
     * Les items en défaut d'une revue, dans l'ordre de la grille qu'elle a
     * appliquée : ceux auxquels elle n'a pas répondu `true`.
     *
     * @return list<string>
     */
    public static function failedItems(FrameReview $review): array
    {
        $failed = [];

        foreach (ExclusionGrid::slugs($review->grid_version) as $slug) {
            if (array_key_exists($slug, $review->answers) && $review->answers[$slug] !== true) {
                $failed[] = $slug;
            }
        }

        return $failed;
    }

    /**
     * La source déclarée d'une image, telle que la revue l'affiche en lecture
     * seule et que son envoi la confirme (§ 7.6, A7) : le chemin du visuel
     * TMDB, ou le timecode `h:mm:ss` d'une capture — un instant DANS
     * L'ŒUVRE, jamais l'outil ni la méthode d'extraction.
     *
     * @return array{kind: string, reference: string}
     */
    public static function declaredSource(Frame $frame): array
    {
        return [
            'kind' => $frame->source_kind->value,
            'reference' => match ($frame->source_kind) {
                FrameSourceKind::Tmdb => (string) $frame->tmdb_file_path,
                FrameSourceKind::Capture => $frame->source_timecode_ms === null
                    ? ''
                    : self::timecode($frame->source_timecode_ms),
            },
        ];
    }

    /**
     * Un timecode `h:mm:ss`, heures sans zéro de tête, millisecondes
     * tronquées : la forme de saisie d'une capture (§ 5.4).
     */
    public static function timecode(int $milliseconds): string
    {
        $seconds = intdiv(max(0, $milliseconds), 1_000);

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3_600), intdiv($seconds, 60) % 60, $seconds % 60);
    }

    /**
     * Prête, et ni elle ni son film sous un geste d'administrateur.
     */
    private static function isEligible(Frame $frame, Movie $movie): bool
    {
        $locked = [ContentAvailability::Suspended, ContentAvailability::Withdrawn];

        return $frame->processing_state === FrameProcessingState::Ready
            && ! in_array($frame->availability, $locked, true)
            && ! in_array($movie->availability, $locked, true);
    }

    /**
     * Hors du jeu et jugeable : brouillon, ou image DÉPUBLIÉE — jamais une
     * image écartée, qui a été jugée et n'y revient jamais (§ 8.4).
     */
    private static function isOutOfPlay(Frame $frame): bool
    {
        return $frame->availability === ContentAvailability::Draft
            || ($frame->availability === ContentAvailability::Unpublished && $frame->first_published_at !== null);
    }

    private static function lastStateChange(Frame $frame): ?CarbonInterface
    {
        return $frame->availability_changed_at ?? $frame->created_at;
    }

    /**
     * Les trois listes, lues une fois.
     *
     * @return array<string, list<Frame>>
     */
    private function lists(): array
    {
        if ($this->lists !== null) {
            return $this->lists;
        }

        $candidates = $this->candidates();
        $this->judging = self::judgingReviews($candidates);

        $lists = array_fill_keys(array_map(static fn (ReviewList $list): string => $list->value, ReviewList::cases()), []);

        foreach ($candidates as $frame) {
            foreach (self::listsOf($frame, $frame->movie, ($this->judging[$frame->id] ?? null)?->decision) as $list) {
                $lists[$list->value][] = $frame;
            }
        }

        return $this->lists = array_map(self::ordered(...), $lists);
    }

    /**
     * La pré-sélection SQL : large, indexable, sans comparaison de dates —
     * les prédicats exacts tranchent ensuite en PHP. Une image publiée n'y
     * entre que sous une ancienne grille ou avec une revue rejetée sur ses
     * octets : l'essentiel du catalogue en jeu n'est jamais lu.
     *
     * @return Collection<int, Frame>
     */
    private function candidates(): Collection
    {
        $locked = [ContentAvailability::Suspended->value, ContentAvailability::Withdrawn->value];

        return Frame::query()
            ->where('processing_state', FrameProcessingState::Ready->value)
            ->whereHas('movie', fn (Builder $movie) => $movie->whereNotIn('availability', $locked))
            ->where(function (Builder $query): void {
                $query->where('availability', ContentAvailability::Draft->value)
                    ->orWhere(fn (Builder $unpublished) => $unpublished
                        ->where('availability', ContentAvailability::Unpublished->value)
                        ->whereNotNull('first_published_at'))
                    ->orWhere(fn (Builder $published) => $published
                        ->where('availability', ContentAvailability::Published->value)
                        ->where(fn (Builder $flagged) => $flagged
                            ->where('review_grid_version', '<', ExclusionGrid::CURRENT_VERSION)
                            ->orWhereExists(function (QueryBuilder $review): void {
                                $review->selectRaw('1')
                                    ->from('frame_review')
                                    ->whereColumn('frame_review.frame_id', 'frame.id')
                                    ->whereColumn('frame_review.reviewed_hash', 'frame.published_hash')
                                    ->where('frame_review.grid_version', ExclusionGrid::CURRENT_VERSION)
                                    ->where('frame_review.decision', ReviewDecision::Rejected->value);
                            })));
            })
            ->with('movie')
            ->get();
    }

    /**
     * Regroupées par film, films dans l'ordre de leur plus ancienne image,
     * images par niveau puis identifiant.
     *
     * @param  list<Frame>  $frames
     * @return list<Frame>
     */
    private static function ordered(array $frames): array
    {
        /** @var array<int, list<Frame>> $byMovie */
        $byMovie = [];

        foreach ($frames as $frame) {
            $byMovie[$frame->movie_id][] = $frame;
        }

        foreach ($byMovie as $movieId => $movieFrames) {
            usort($movieFrames, static fn (Frame $a, Frame $b): int => [$a->frame_level->value, $a->id] <=> [$b->frame_level->value, $b->id]);
            $byMovie[$movieId] = $movieFrames;
        }

        uasort($byMovie, static fn (array $a, array $b): int => self::oldest($a) <=> self::oldest($b));

        return array_merge(...array_values($byMovie));
    }

    /**
     * La clé de tri d'un film : sa plus ancienne image de la liste.
     *
     * @param  list<Frame>  $frames
     * @return array{0: int, 1: int}
     */
    private static function oldest(array $frames): array
    {
        $keys = array_map(
            static fn (Frame $frame): array => [$frame->created_at?->getTimestamp() ?? 0, $frame->id],
            $frames,
        );

        sort($keys);

        return $keys[0];
    }
}
