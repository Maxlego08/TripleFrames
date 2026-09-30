<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\MovieProjector;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Corriger — ou saisir — le titre affichable d'un film dans une locale
 * **activée** : spec 20 § 9.1, ligne 22 de la matrice des capacités
 * (`can:curate,movie`).
 *
 * La ligne `(movie_id, locale)` est créée ou réécrite en **`origin =
 * curator`**, `edited_by_id` = le curateur : c'est ce qui la protège de toute
 * resynchronisation (spec 10 § 9.3) — un réimport ne réécrit que les lignes
 * `tmdb`, et ne crée jamais une ligne pour une locale déjà pourvue.
 *
 * **Une ligne `movie.title_saved`** (D41 du 30/09), dans la transaction,
 * quand la ligne est créée ou change réellement : la réécriture est en
 * place, et `details` garde l'ancien texte et son origine, que
 * `edited_by_id` seul perdrait.
 *
 * **Une transaction, le film verrouillé**, et dans la même : le masque de
 * couverture des titres (`MovieProjector::recompute`, `title_locale_mask`),
 * puis les clés de réponse, reprojetées **par différence**
 * (`AnswerKeyProjector::project`) — le titre, son préfixe et son sous-titre,
 * et le recompte synchrone de leur ambiguïté (spec 10 § 3.2, § 3.5 : jamais
 * un job de fond).
 *
 * **Aucun titre n'est jamais recopié d'une langue à l'autre** (§ 3.4) : seule
 * la ligne de la locale visée est écrite ; l'absence d'une ligne dans une
 * autre langue reste l'information.
 *
 * `title_original` et sa translittération ne se corrigent pas ici : ce sont
 * des métadonnées TMDB, écrasables par une resynchronisation.
 */
final class SaveMovieTitle
{
    public function __construct(
        private readonly MovieProjector $projector,
        private readonly AnswerKeyProjector $answerKeys,
        private readonly AdminJournal $journal,
    ) {}

    /**
     * Écrit le titre ; rend la ligne, créée ou réécrite.
     *
     * @param  string  $title  déjà rogné et borné par la requête
     *
     * @throws AuthorizationException le film a été retiré entre la garde et le verrou
     * @throws Throwable
     */
    public function handle(Movie $movie, User $curator, Locale $locale, string $title): MovieTitle
    {
        $row = DB::transaction(function () use ($movie, $curator, $locale, $title): MovieTitle {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('curate', $locked);

            $row = MovieTitle::query()
                ->where('movie_id', $locked->id)
                ->where('locale', $locale->value)
                ->first() ?? new MovieTitle;

            $before = $row->exists ? $row->title : null;
            $beforeOrigin = $row->exists ? $row->origin : null;

            $row->movie_id = $locked->id;
            $row->locale = $locale->value;
            $row->title = $title;
            $row->origin = ContentOrigin::Curator;
            $row->edited_by_id = $curator->id;
            $row->save();

            if ($row->wasRecentlyCreated || $row->wasChanged(['title', 'origin'])) {
                $this->journal->record(
                    $curator,
                    AdminActionType::MovieTitleSaved,
                    $locked->id,
                    details: AdminActionDetails::titleSaved($locale->value, $before, $beforeOrigin, $title),
                );
            }

            $this->projector->recompute($locked);
            $this->answerKeys->project($locked);

            return $row;
        });

        $movie->refresh();

        return $row;
    }
}
