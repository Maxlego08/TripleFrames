<?php

namespace App\Actions\Curation;

use App\Models\Alias;
use App\Models\Movie;
use App\Models\User;
use App\Support\Catalog\AnswerKeyProjector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Retirer un alias d'un film — spec 20 § 9.2, ligne 22 de la matrice des
 * capacités (`can:curate,movie`).
 *
 * **Tout alias, TMDB compris** : c'est le geste qui corrige une forme exacte
 * partagée par deux films (C12 § 3), et aucun geste de curation ne dépend
 * d'une commande (D10 du 23/09). Un alias TMDB retiré revient à la
 * resynchronisation suivante, qui le signale « réapparu » (§ 3.7, J2).
 *
 * La route lie l'alias à son film (`scopeBindings`) : l'alias d'un autre film
 * répond 404 avant la garde.
 *
 * Une transaction, le film verrouillé : la ligne, puis les clés reprojetées
 * par différence — la forme de l'alias disparaît d'`answer_key` sauf si un
 * titre ou un autre alias du film la porte encore —, avec le recompte
 * synchrone de leur ambiguïté.
 */
final class DeleteAlias
{
    public function __construct(
        private readonly AnswerKeyProjector $answerKeys,
    ) {}

    /**
     * @throws AuthorizationException le film a été retiré entre la garde et le verrou
     * @throws Throwable
     */
    public function handle(Movie $movie, User $curator, Alias $alias): void
    {
        DB::transaction(function () use ($movie, $curator, $alias): void {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('curate', $locked);

            Alias::query()
                ->where('movie_id', $locked->id)
                ->whereKey($alias->id)
                ->delete();

            $this->answerKeys->project($locked);
        });
    }
}
