<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\AnswerKeyProjector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Dépublier un film publié, ou **écarter** un brouillon — spec 20 § 8.3 et
 * § 4.2, ligne 20 de la matrice des capacités (`can:unpublish,movie`),
 * régime de curation du § 11.1.
 *
 * Un seul geste, deux lectures (B2) : depuis `published`, le film sort du
 * vivier des lancements suivants ; depuis `draft`, il est **écarté** —
 * `unpublished` avec `first_published_at` NULL (EN20-1) — et sort de la file
 * de curation sans état nouveau dans le schéma. Rien n'est perdu : curer puis
 * publier un film écarté est une PREMIÈRE publication (`movie.published`).
 *
 * **Motif obligatoire** (`movie.unpublished`, C14) : il est écrit sur
 * `availability_reason`, que la fiche affiche, et sur la ligne du journal.
 *
 * **Les images ne suivent pas** : elles restent publiées, et c'est le film
 * qui sort du vivier. La dépublication de curation est paresseuse au J1
 * (§ 8.5) : une partie en cours finit sur ses images, aucune manche n'est
 * réécrite.
 *
 * Une transaction, le film verrouillé : état, motif, ligne du journal, et le
 * recompte synchrone de l'ambiguïté de ses formes — sa sortie du catalogue
 * publié peut lever des ambiguïtés (spec 10 § 3.5). Ses clés sont conservées :
 * republier est un basculement de drapeau.
 */
final class UnpublishMovie
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly AnswerKeyProjector $answerKeys,
    ) {}

    /**
     * Dépublie ou écarte le film ; rend `true` s'il était publié.
     *
     * @throws AuthorizationException le film a quitté `draft` et `published` entre la garde et le verrou
     * @throws Throwable
     */
    public function handle(Movie $movie, User $curator, string $reason): bool
    {
        $wasPublished = DB::transaction(function () use ($movie, $curator, $reason): bool {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('unpublish', $locked);

            $wasPublished = $locked->availability === ContentAvailability::Published;

            $locked->forceFill([
                'availability' => ContentAvailability::Unpublished,
                'availability_changed_at' => Date::now()->toImmutable(),
                'availability_reason' => $reason,
            ])->save();

            $this->journal->record($curator, AdminActionType::MovieUnpublished, $locked->id, $reason);

            $this->answerKeys->recomputeAmbiguity(PublishMovie::formsOf($locked));

            return $wasPublished;
        });

        $movie->refresh();

        return $wasPublished;
    }
}
