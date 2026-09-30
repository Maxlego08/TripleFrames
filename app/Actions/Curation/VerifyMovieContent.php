<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentFlag;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Cocher « contenu vérifié, pas de classification restrictive » — spec 20
 * § 4.4, question 12, ligne 21 de la matrice des capacités
 * (`can:verifyContent,movie`).
 *
 * Seul un film `unrated_pending` s'y prête — aucune classification FR ni US
 * connue — et jamais un film retiré. Le curateur déclare, **motif
 * obligatoire**, qu'il a vérifié qu'aucune classification restrictive ne le
 * frappe : `content_flag = clear`, auteur et horodatage sur le film, ligne
 * `movie.content_verified` avec le motif, **dans la même transaction** — la
 * preuve s'écrit avec l'état qu'elle justifie, ou pas du tout.
 *
 * **Pas de décoche** : le remède est la dépublication (curateur) ou la
 * suspension (administrateur, J2). Et **`blocked` ne se lève par aucun
 * geste** (décision 12) : la garde le refuse avant toute écriture, et aucune
 * autre voie n'écrit `content_flag`.
 */
final class VerifyMovieContent
{
    public function __construct(
        private readonly AdminJournal $journal,
    ) {}

    /**
     * @throws AuthorizationException le film n'est plus `unrated_pending`, ou a été retiré, entre la garde et le verrou
     * @throws Throwable
     */
    public function handle(Movie $movie, User $curator, string $reason): void
    {
        DB::transaction(function () use ($movie, $curator, $reason): void {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('verifyContent', $locked);

            $locked->forceFill([
                'content_flag' => ContentFlag::Clear,
                'content_verified_by_id' => $curator->id,
                'content_verified_at' => Date::now()->toImmutable(),
            ])->save();

            $this->journal->record($curator, AdminActionType::MovieContentVerified, $locked->id, $reason);
        });

        $movie->refresh();
    }
}
