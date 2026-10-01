<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingState;
use App\Enums\ReviewDecision;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\MovieProjector;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\FrameReviewWriter;
use App\Support\Curation\ReviewList;
use App\Support\Curation\ReviewQueue;
use App\ValueObjects\Admin\AdminActionDetails;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Passer une revue d'image — spec 20 § 7.5, contrat C14-bis § 4, ligne 17 de
 * la matrice des capacités.
 *
 * **La décision est dérivée ici, jamais reçue** : le client envoie ses
 * réponses item par item, et {@see ExclusionGrid::decisionFor()} tranche —
 * `passed` si toutes les réponses aux items applicables valent `true`,
 * `rejected` dès qu'une vaut `false`.
 *
 * **Tout se relit sous le verrou de la frame** — le film d'abord, la frame
 * ensuite, dans l'ordre des autres gestes de curation —, et chaque refus
 * n'écrit AUCUNE ligne `frame_review` :
 *
 * 1. `admin.review.locked` : la frame ou son film est suspendu ou retiré, ou
 *    la frame n'est pas prête (en traitement, en échec) ;
 * 2. `admin.review.stale` : les octets ont changé depuis l'affichage — un
 *    re-recadrage est passé entre-temps ;
 * 3. `admin.review.level_changed` : les réponses ne portent pas EXACTEMENT
 *    sur les items du niveau relu sous le verrou — le niveau a changé depuis
 *    l'affichage, et une revue qui ne répond pas aux items du niveau courant
 *    ne prouve rien ;
 * 4. `admin.review.source_mismatch` : la source confirmée n'est pas celle de
 *    l'image ;
 * 5. `admin.review.already_reviewed` : la frame n'appartient à aucune des
 *    trois listes de la file ({@see ReviewQueue}), ou la décision est un
 *    rejet alors qu'elle est déjà dans « Rejetées ». **Un double envoi
 *    n'écrit donc jamais deux preuves.**
 *
 * **Une transaction** : la ligne `frame_review` — nom réel du relecteur
 * (D12 du 23/09) et rôle courant, instantanés ; réponses telles quelles ;
 * source déclarée confirmée (§ 7.6) — puis, si elle passe, la PUBLICATION :
 * `published_review_id` pointe la ligne, `availability = published`,
 * `first_published_at` si nul, `availability_changed_at` si l'état change —
 * au même instant serveur que `reviewed_at`, dont le prédicat de la file
 * dépend —, copies de file de travail `reviewed_at` et `review_grid_version`,
 * projection du film recalculée. Toute transition d'une frame vers
 * `published` insère ainsi sa propre ligne (E10-21). Une re-revue passante
 * d'une image en jeu insère une ligne et repointe `published_review_id` :
 * l'image ne quitte jamais le jeu. Une revue rejetée laisse l'état
 * inchangé ; sur une image en jeu, l'écran enchaîne sur la confirmation de
 * dépublication, geste distinct et attribué (§ 8.4).
 *
 * La publication d'une image est prouvée par sa propre revue passante (§ 2.7,
 * point 9) : `frame_review` reste la SEULE preuve opposable. Depuis D41 du
 * 30/09, toute revue, passante ou rejetée, écrit en plus sa ligne
 * `frame.reviewed` dans la même transaction — un index dans le journal
 * unifié, qui pointe la preuve (`details.review_id`) sans la dupliquer.
 *
 * L'écriture de la preuve et la publication sont partagées avec la
 * validation en lot d'un film ({@see FrameReviewWriter}, D42 du 30/09) ; la
 * revue unitaire garde ses gardes, sa ligne `frame.reviewed` et ses refus.
 */
final class ReviewFrame
{
    public function __construct(
        private readonly MovieProjector $projector,
        private readonly AdminJournal $journal,
    ) {}

    /**
     * Écrit la revue, et publie l'image si elle passe.
     *
     * @param  array<string, bool>  $answers  réponses clées par slug ; `true` = conforme
     *
     * @throws ValidationException un refus relu sous le verrou, sans aucune écriture
     * @throws Throwable
     */
    public function handle(
        Frame $frame,
        User $reviewer,
        int $gridVersion,
        string $reviewedHash,
        array $answers,
        string $declaredSourceReference,
    ): FrameReview {
        $review = DB::transaction(function () use ($frame, $reviewer, $gridVersion, $reviewedHash, $answers, $declaredSourceReference): FrameReview {
            $movie = Movie::query()->whereKey($frame->movie_id)->lockForUpdate()->firstOrFail();
            $locked = Frame::query()->whereKey($frame->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($reviewer)->authorize('create', FrameReview::class);

            $lists = $this->guard($locked, $movie, $gridVersion, $reviewedHash, $answers, $declaredSourceReference);

            $decision = ExclusionGrid::decisionFor($answers, $locked->frame_level, $gridVersion);

            if ($decision === ReviewDecision::Rejected && in_array(ReviewList::Rejected, $lists, true)) {
                throw ValidationException::withMessages(['frame' => __('admin.review.already_reviewed')]);
            }

            $now = Date::now()->toImmutable()->startOfSecond();

            $review = FrameReviewWriter::record($locked, $reviewer, $gridVersion, $decision, $answers, $now);

            $this->journal->record(
                $reviewer,
                AdminActionType::FrameReviewed,
                $locked->id,
                details: AdminActionDetails::reviewed($review),
            );

            if ($decision === ReviewDecision::Passed) {
                $this->publish($locked, $movie, $review, $now);
            }

            return $review;
        });

        $frame->refresh();

        return $review;
    }

    /**
     * Les refus du § 7.5, dans l'ordre, relus sous le verrou ; rend les
     * listes de la file auxquelles la frame appartient.
     *
     * @param  array<string, bool>  $answers
     * @return list<ReviewList>
     *
     * @throws ValidationException
     */
    private function guard(
        Frame $frame,
        Movie $movie,
        int $gridVersion,
        string $reviewedHash,
        array $answers,
        string $declaredSourceReference,
    ): array {
        // Doublé par la requête ; relu ici pour que l'action ne publie jamais
        // sous une grille que l'écran n'a pas montrée.
        if ($gridVersion !== ExclusionGrid::CURRENT_VERSION) {
            throw ValidationException::withMessages(['grid_version' => __('admin.review.grid_version_outdated')]);
        }

        $adminStates = [ContentAvailability::Suspended, ContentAvailability::Withdrawn];

        if (in_array($frame->availability, $adminStates, true)
            || in_array($movie->availability, $adminStates, true)
            || $frame->processing_state !== FrameProcessingState::Ready) {
            throw ValidationException::withMessages(['frame' => __('admin.review.locked')]);
        }

        if ($frame->published_hash === null || ! hash_equals($frame->published_hash, $reviewedHash)) {
            throw ValidationException::withMessages(['reviewed_hash' => __('admin.review.stale')]);
        }

        $expected = ExclusionGrid::slugsFor($frame->frame_level, $gridVersion);
        $given = array_keys($answers);

        sort($expected);
        sort($given);

        if ($given !== $expected) {
            throw ValidationException::withMessages(['answers' => __('admin.review.level_changed')]);
        }

        if (ReviewQueue::declaredSource($frame)['reference'] !== $declaredSourceReference) {
            throw ValidationException::withMessages(['declared_source_reference' => __('admin.review.source_mismatch')]);
        }

        $judging = ReviewQueue::judgingReviewFor($frame);
        $lists = ReviewQueue::listsOf($frame, $movie, $judging?->decision);

        if ($lists === []) {
            throw ValidationException::withMessages(['frame' => __('admin.review.already_reviewed')]);
        }

        return $lists;
    }

    /**
     * Revue passante = publication, dans la transaction de la preuve.
     */
    private function publish(Frame $frame, Movie $movie, FrameReview $review, CarbonImmutable $now): void
    {
        FrameReviewWriter::publish($frame, $review, $now);

        // Synchrone, dans la transaction du geste (spec 10 § 3.2, règle 2).
        $this->projector->recompute($movie);
    }
}
