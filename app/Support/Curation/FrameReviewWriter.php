<?php

namespace App\Support\Curation;

use App\Actions\Curation\ReviewFrame;
use App\Actions\Curation\ReviewMovieFrames;
use App\Enums\ContentAvailability;
use App\Enums\ReviewDecision;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * L'écriture d'UNE preuve de revue et de ses effets — spec 20 § 7.5, commune
 * à la revue unitaire ({@see ReviewFrame}) et à la validation en lot d'un
 * film ({@see ReviewMovieFrames}, D42 du 30/09) : le lot n'est qu'une suite
 * de revues image par image, chacune avec sa ligne `frame_review`, jamais une
 * preuve collective.
 *
 * Appelée SOUS LE VERROU de la frame, dans la transaction du geste, après ses
 * gardes : cette classe n'en porte aucune, hors du nom réel du relecteur. Le
 * recalcul de la projection du film reste à l'appelant — une fois par geste.
 */
final class FrameReviewWriter
{
    /**
     * La preuve, en ajout seul : instantanés du relecteur et de la source.
     *
     * @param  array<string, bool>  $answers
     */
    public static function record(
        Frame $frame,
        User $reviewer,
        int $gridVersion,
        ReviewDecision $decision,
        array $answers,
        CarbonImmutable $now,
    ): FrameReview {
        $realName = trim((string) $reviewer->real_name);

        if ($realName === '') {
            // Inatteignable : la garde `User::saving` interdit un rôle de
            // curation sans nom réel (D12 du 23/09). Une preuve anonyme ne
            // s'écrit jamais.
            throw new LogicException("Le compte #{$reviewer->id} n'a pas de nom réel : il ne peut pas signer une revue.");
        }

        $source = ReviewQueue::declaredSource($frame);

        $review = new FrameReview;
        $review->forceFill([
            'frame_id' => $frame->id,
            'reviewer_id' => $reviewer->id,
            'reviewer_name' => $realName,
            'reviewer_role' => $reviewer->role,
            'grid_version' => $gridVersion,
            'decision' => $decision,
            'reviewed_hash' => $frame->published_hash,
            'declared_source_kind' => $frame->source_kind,
            'declared_source_reference' => $source['reference'],
            'answers' => $answers,
            'reviewed_at' => $now,
        ])->save();

        return $review;
    }

    /**
     * Revue passante = publication de l'image, dans la transaction de la
     * preuve : `published_review_id`, disponibilité et ses horodatages au
     * même instant serveur que `reviewed_at`, copies de file de travail.
     */
    public static function publish(Frame $frame, FrameReview $review, CarbonImmutable $now): void
    {
        $attributes = [
            'published_review_id' => $review->id,
            'reviewed_at' => $now,
            'review_grid_version' => $review->grid_version,
        ];

        if ($frame->availability !== ContentAvailability::Published) {
            $attributes['availability'] = ContentAvailability::Published;
            $attributes['availability_changed_at'] = $now;
        }

        if ($frame->first_published_at === null) {
            $attributes['first_published_at'] = $now;
        }

        $frame->forceFill($attributes)->save();
    }
}
