<?php

namespace App\Support\Curation;

use App\Actions\Curation\PublishMovie;
use App\Actions\Curation\PublishReadyMovies;
use App\Models\Movie;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AmbiguityReport;
use Illuminate\Database\Eloquent\Collection;
use JsonException;

/**
 * Le lot de « Publier les films prêts » — spec 20 § 8.1 bis, D59 du 06/10.
 *
 * **Prêt** = l'état dérivé {@see CurationStatus::ReadyToPublish} (brouillon,
 * contenu `clear`, niveaux 1, 3 et 5 couverts), le même prédicat que le
 * compteur du tableau de bord et le filtre du catalogue : le bouton publie
 * exactement ce que le compteur annonce. Un film dépublié ou écarté n'y entre
 * jamais — sa republication reste un geste à l'unité, depuis sa fiche.
 *
 * Parmi les prêts, la garde de devinabilité (que le prédicat SQL ne lit pas)
 * met de côté ceux dont aucun titre ne se tape : ils sont nommés, jamais
 * publiés ni omis en silence.
 *
 * **L'aperçu d'ambiguïté du lot** compte les films du lot comme déjà publiés
 * ({@see AmbiguityPreview::forPublication()}, `$alsoPublishing`) : deux
 * épisodes d'une saga publiés ensemble se rendent mutuellement un préfixe
 * ambigu, et le curateur le lit avant de confirmer. Son empreinte couvre les
 * identifiants ET les lignes de chaque film : un film devenu prêt, ou qui ne
 * l'est plus, entre l'aperçu et le clic change l'empreinte, et rien n'est
 * publié.
 *
 * Lecture seule, comme {@see AmbiguityPreview}.
 *
 * @phpstan-import-type AmbiguityLine from AmbiguityReport
 *
 * @phpstan-type ReadyEntry array{id: int, title_original: string, release_year: int|null, lines: list<AmbiguityLine>}
 * @phpstan-type SkippedEntry array{id: int, title_original: string, release_year: int|null, blockers: list<string>}
 */
final readonly class ReadyBatch
{
    public function __construct(private AmbiguityPreview $preview) {}

    /**
     * Les films prêts à publier, par identifiant croissant, projection
     * chargée.
     *
     * @return Collection<int, Movie>
     */
    public function readyMovies(): Collection
    {
        [$condition, $bindings] = CurationStatus::ReadyToPublish->condition();

        return Movie::query()
            ->select('movie.*')
            ->leftJoin('movie_projection', 'movie_projection.movie_id', '=', 'movie.id')
            ->whereRaw($condition, $bindings)
            ->orderBy('movie.id')
            ->with('projection')
            ->get();
    }

    /**
     * La prop de l'écran : les films publiables avec leurs lignes
     * d'ambiguïté, les films mis de côté avec leurs conditions manquantes, et
     * l'empreinte que la confirmation poste.
     *
     * @return array{movies: list<ReadyEntry>, skipped: list<SkippedEntry>, digest: string}
     *
     * @throws JsonException
     */
    public function preview(): array
    {
        /** @var Collection<int, Movie> $publishable */
        $publishable = new Collection;
        $skipped = [];

        foreach ($this->readyMovies() as $movie) {
            $blockers = PublishMovie::conditions($movie, $movie->projection)['blockers'];

            if ($blockers === []) {
                $publishable->push($movie);

                continue;
            }

            $skipped[] = [
                'id' => $movie->id,
                'title_original' => $movie->title_original,
                'release_year' => $movie->release_year,
                'blockers' => $blockers,
            ];
        }

        $entries = $this->entries($publishable);

        return [
            'movies' => $entries,
            'skipped' => $skipped,
            'digest' => self::digestOf($entries),
        ];
    }

    /**
     * L'empreinte du lot formé de ces films — recalculée sous verrou par
     * {@see PublishReadyMovies}.
     *
     * @param  Collection<int, Movie>  $movies
     *
     * @throws JsonException
     */
    public function digest(Collection $movies): string
    {
        return self::digestOf($this->entries($movies));
    }

    /**
     * Une entrée par film, par identifiant croissant, ses lignes
     * d'ambiguïté calculées avec tout le lot compté comme publié.
     *
     * @param  Collection<int, Movie>  $movies
     * @return list<ReadyEntry>
     */
    private function entries(Collection $movies): array
    {
        $sorted = $movies->sortBy('id')->values();

        /** @var list<int> $ids */
        $ids = $sorted->map(static fn (Movie $movie): int => $movie->id)->all();

        $entries = [];

        foreach ($sorted as $movie) {
            $entries[] = [
                'id' => $movie->id,
                'title_original' => $movie->title_original,
                'release_year' => $movie->release_year,
                'lines' => $this->preview->forPublication($movie, $ids)->lines,
            ];
        }

        return $entries;
    }

    /**
     * @param  list<ReadyEntry>  $entries
     *
     * @throws JsonException
     */
    private static function digestOf(array $entries): string
    {
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }
}
