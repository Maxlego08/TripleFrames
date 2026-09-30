<?php

namespace App\Support\Admin;

use App\Enums\AdminActionSubject;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La forme EXACTE des lignes de l'écran « Journal » (spec 20 § 2.2, ligne 41 ;
 * D41 du 30/09), en **snake_case**, miroir de `AdminJournalLine` dans
 * `resources/js/types/admin.ts`.
 *
 * **Un nombre de requêtes constant par page**, jamais un N+1 : les sujets
 * d'une page sont résolus par type, une requête par type présent
 * ({@see self::resolveSubjects()}), puis chaque ligne lit son libellé dans la
 * table ainsi chargée.
 *
 * **L'auteur est rendu par son instantané** `actor_name` — le nom sous lequel
 * le geste a été signé, jamais le nom réel courant. `actor_id` n'accompagne
 * la ligne que pour ouvrir la fiche du compte, écran de l'administrateur qui
 * adresse déjà un compte par son identifiant ; il reste NULL pour les deux
 * acteurs réservés.
 *
 * Le libellé d'un sujet est, lui, COURANT : le titre original d'un film, le
 * pseudo d'un compte. Un joueur, une demande de retrait, le site et
 * l'ensemble des comptes n'ont pas de libellé ici — l'écran montre leur
 * type et, s'il existe, leur numéro. Le pseudo d'un joueur, effacé à
 * l'archivage de son salon, ne ressuscite jamais par le journal.
 *
 * Toute date part en ISO-8601, jamais en texte pré-formaté (règle 4).
 */
final class AdminJournalPresenter
{
    /**
     * `$extra` : le sujet du filtre courant, résolu dans les mêmes requêtes
     * que ceux de la page.
     *
     * @param  Collection<int, AdminAction>  $lines
     * @param  array{0: AdminActionSubject, 1: int}|null  $extra
     * @return array{movies: array<int, string>, frames: array<int, array{movie_id: int, movie_title: string|null}>, users: array<int, string>}
     */
    public static function resolveSubjects(Collection $lines, ?array $extra = null): array
    {
        $idsOf = static function (AdminActionSubject $type) use ($lines, $extra): array {
            $ids = [];

            foreach ($lines as $line) {
                if ($line->subject_type === $type && $line->subject_id !== null) {
                    $ids[] = $line->subject_id;
                }
            }

            if ($extra !== null && $extra[0] === $type) {
                $ids[] = $extra[1];
            }

            return array_values(array_unique($ids));
        };

        $frames = [];
        $frameIds = $idsOf(AdminActionSubject::Frame);

        if ($frameIds !== []) {
            foreach (Frame::query()->whereIn('id', $frameIds)->get(['id', 'movie_id']) as $frame) {
                $frames[$frame->id] = $frame->movie_id;
            }
        }

        $movieIds = array_values(array_unique([...$idsOf(AdminActionSubject::Movie), ...array_values($frames)]));
        $movies = [];

        if ($movieIds !== []) {
            foreach (Movie::query()->whereIn('id', $movieIds)->get(['id', 'title_original']) as $movie) {
                $movies[$movie->id] = $movie->title_original;
            }
        }

        $users = [];
        $userIds = $idsOf(AdminActionSubject::User);

        if ($userIds !== []) {
            foreach (User::query()->whereIn('id', $userIds)->get(['id', 'name']) as $user) {
                $users[$user->id] = $user->name;
            }
        }

        $resolvedFrames = [];

        foreach ($frames as $frameId => $movieId) {
            $resolvedFrames[$frameId] = [
                'movie_id' => $movieId,
                'movie_title' => $movies[$movieId] ?? null,
            ];
        }

        return [
            'movies' => $movies,
            'frames' => $resolvedFrames,
            'users' => $users,
        ];
    }

    /**
     * Une ligne du journal.
     *
     * @param  array{movies: array<int, string>, frames: array<int, array{movie_id: int, movie_title: string|null}>, users: array<int, string>}  $subjects
     * @return array{
     *     id: int,
     *     action: string,
     *     actor_id: int|null,
     *     actor_name: string,
     *     subject: array{type: string, id: int|null, label: string|null, movie_id: int|null, exists: bool},
     *     reason: string|null,
     *     role_before: string|null,
     *     role_after: string|null,
     *     reports_count: int|null,
     *     details: array<string, mixed>|null,
     *     retention_class: string,
     *     created_at: string|null,
     * }
     */
    public static function line(AdminAction $line, array $subjects): array
    {
        return [
            'id' => $line->id,
            'action' => $line->action->value,
            'actor_id' => $line->actor_id,
            'actor_name' => $line->actor_name,
            'subject' => self::subject($line->subject_type, $line->subject_id, $subjects),
            'reason' => $line->reason,
            'role_before' => $line->role_before?->value,
            'role_after' => $line->role_after?->value,
            'reports_count' => $line->reports_count,
            'details' => $line->details?->jsonSerialize(),
            'retention_class' => $line->retention_class->value,
            'created_at' => self::moment($line->created_at),
        ];
    }

    /**
     * Le sujet d'une ligne — ou celui du filtre courant, résolu de la même
     * façon pour que le bandeau « filtré sur » dise la même chose que le
     * tableau.
     *
     * `exists` : le sujet identifié est encore en base. Un film, une image ou
     * un compte ne sont jamais détruits, mais un filtre posé à la main peut
     * viser un numéro qui n'a jamais existé.
     *
     * @param  array{movies: array<int, string>, frames: array<int, array{movie_id: int, movie_title: string|null}>, users: array<int, string>}  $subjects
     * @return array{type: string, id: int|null, label: string|null, movie_id: int|null, exists: bool}
     */
    public static function subject(AdminActionSubject $type, ?int $id, array $subjects): array
    {
        [$label, $movieId, $exists] = match (true) {
            $id === null => [null, null, true],
            $type === AdminActionSubject::Movie => [
                $subjects['movies'][$id] ?? null,
                isset($subjects['movies'][$id]) ? $id : null,
                isset($subjects['movies'][$id]),
            ],
            $type === AdminActionSubject::Frame => [
                $subjects['frames'][$id]['movie_title'] ?? null,
                $subjects['frames'][$id]['movie_id'] ?? null,
                isset($subjects['frames'][$id]),
            ],
            $type === AdminActionSubject::User => [
                $subjects['users'][$id] ?? null,
                null,
                isset($subjects['users'][$id]),
            ],
            // Joueur, demande de retrait, balayage : aucun libellé à
            // résoudre, le numéro suffit.
            default => [null, null, true],
        };

        return [
            'type' => $type->value,
            'id' => $id,
            'label' => $label,
            'movie_id' => $movieId,
            'exists' => $exists,
        ];
    }

    /** Un instant, en ISO-8601 — jamais une chaîne pré-formatée côté serveur. */
    private static function moment(?CarbonImmutable $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
