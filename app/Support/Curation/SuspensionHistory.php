<?php

namespace App\Support\Curation;

use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\ReviewDecision;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;

/**
 * Ce que la levée d'une suspension restaure — spec 20 § 11.2, spec 10 § 4.2
 * et § 8.3 (A2), invariants 9 et 10 du § 2.7.
 *
 * **L'état antérieur d'un film se reconstitue depuis le journal**, jamais
 * depuis une colonne : la **dernière** ligne parmi `movie.published`,
 * `movie.republished` et `movie.unpublished` **antérieure à la suspension
 * levée** (la dernière ligne `movie.suspended` du film) — `published` ou
 * `republished` → `published`, `unpublished` → `unpublished`, aucune →
 * `draft`. Les lignes `movie.suspended` et `movie.unsuspended` sont
 * ignorées. L'ordre est celui des identifiants : le journal est en ajout
 * seul, et deux lignes d'une même seconde y restent ordonnées.
 *
 * **Une image est suspendue « par la cascade »** si sa dernière ligne de
 * sujet `frame` n'est pas `frame.suspended`, ou si elle n'en a aucune : la
 * cascade d'une suspension de film n'écrit aucune ligne par image
 * (invariant 9). Une image suspendue individuellement ne se lève que par son
 * propre geste.
 *
 * **Restauration d'une image** (spec 10 § 4.2, n° 18) : `published` sans
 * nouvelle revue **si et seulement si** sa dernière revue passante porte sur
 * son `published_hash` **et** la version courante de la grille ; sinon
 * `unpublished`, ou `draft` si elle n'a jamais été publiée.
 *
 * Lecture seule ; l'appelant tient les verrous.
 */
final class SuspensionHistory
{
    /** Les transitions de disponibilité d'un film qui fixent son état antérieur. */
    private const array MOVIE_STATE_ACTIONS = [
        AdminActionType::MoviePublished,
        AdminActionType::MovieRepublished,
        AdminActionType::MovieUnpublished,
    ];

    /**
     * L'état antérieur du film à la suspension en cours, et le motif qui
     * l'accompagnait (celui de la dernière dépublication, pour un film qui
     * revient `unpublished`).
     *
     * @return array{state: ContentAvailability, reason: string|null}
     */
    public static function priorMovieState(Movie $movie): array
    {
        $suspension = AdminAction::query()
            ->where('subject_type', AdminActionSubject::Movie->value)
            ->where('subject_id', $movie->id)
            ->where('action', AdminActionType::MovieSuspended->value)
            ->orderByDesc('id')
            ->first(['id']);

        $line = AdminAction::query()
            ->where('subject_type', AdminActionSubject::Movie->value)
            ->where('subject_id', $movie->id)
            ->whereIn('action', array_map(
                static fn (AdminActionType $action): string => $action->value,
                self::MOVIE_STATE_ACTIONS,
            ))
            ->when(
                $suspension instanceof AdminAction,
                static fn ($query) => $query->where('id', '<', $suspension?->id),
            )
            ->orderByDesc('id')
            ->first(['id', 'action', 'reason']);

        if (! $line instanceof AdminAction) {
            return ['state' => ContentAvailability::Draft, 'reason' => null];
        }

        return $line->action === AdminActionType::MovieUnpublished
            ? ['state' => ContentAvailability::Unpublished, 'reason' => $line->reason]
            : ['state' => ContentAvailability::Published, 'reason' => null];
    }

    /**
     * Vrai si l'image suspendue l'a été par la cascade de son film, et non
     * par son propre geste.
     */
    public static function suspendedByCascade(Frame $frame): bool
    {
        $last = AdminAction::query()
            ->where('subject_type', AdminActionSubject::Frame->value)
            ->where('subject_id', $frame->id)
            ->orderByDesc('id')
            ->first(['id', 'action']);

        return ! $last instanceof AdminAction || $last->action !== AdminActionType::FrameSuspended;
    }

    /**
     * L'état où revient une image dont la suspension se lève (spec 10 § 4.2).
     */
    public static function restoredFrameState(Frame $frame): ContentAvailability
    {
        if (self::reviewStillValid($frame)) {
            return ContentAvailability::Published;
        }

        return $frame->first_published_at === null
            ? ContentAvailability::Draft
            : ContentAvailability::Unpublished;
    }

    /**
     * La dernière revue passante porte-t-elle sur les octets et la version
     * de grille courants ?
     */
    private static function reviewStillValid(Frame $frame): bool
    {
        if ($frame->published_hash === null) {
            return false;
        }

        $review = FrameReview::query()
            ->where('frame_id', $frame->id)
            ->where('decision', ReviewDecision::Passed->value)
            ->orderByDesc('id')
            ->first(['id', 'reviewed_hash', 'grid_version']);

        return $review instanceof FrameReview
            && hash_equals($frame->published_hash, $review->reviewed_hash)
            && $review->grid_version === ExclusionGrid::CURRENT_VERSION;
    }
}
