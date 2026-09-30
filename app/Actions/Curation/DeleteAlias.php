<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Models\Alias;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\AnswerKeyProjector;
use App\ValueObjects\Admin\AdminActionDetails;
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
 *
 * L'alias est relu sous le verrou du film : la ligne `movie.alias_removed`
 * (D41 du 30/09) n'est écrite que s'il existait encore — un double envoi n'en
 * écrit pas deux —, et `details` garde le texte supprimé physiquement.
 */
final class DeleteAlias
{
    public function __construct(
        private readonly AnswerKeyProjector $answerKeys,
        private readonly AdminJournal $journal,
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

            $row = Alias::query()
                ->where('movie_id', $locked->id)
                ->whereKey($alias->id)
                ->first();

            if ($row instanceof Alias) {
                $this->journal->record(
                    $curator,
                    AdminActionType::MovieAliasRemoved,
                    $locked->id,
                    details: AdminActionDetails::aliasRemoved($row->id, $row->locale, $row->alias, $row->origin),
                );

                $row->delete();
            }

            $this->answerKeys->project($locked);
        });
    }
}
