<?php

namespace App\Support\Curation;

use App\Actions\Curation\SetMovieGroup;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Support\Answers\AnswerRules;
use App\Support\Catalog\AnswerKeyNormalizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Les candidats `movie_group` **par proximité** (spec 20 § 9.4 [J2], contrat
 * C12, L20-27) : le back-office **suggère**, il ne regroupe jamais seul — le
 * regroupement reste le geste manuel du J1 (`SetMovieGroup`).
 *
 * Deux raisons, cumulables :
 *
 * - **titre proche** (`title_distance`) : une forme de titre (`answer_key`,
 *   natures `title_original`, `title_latin`, `title`) à une distance
 *   d'édition `AnswerKeyNormalizer::distance()` au plus
 *   `AnswerRules::tolerance()` d'une forme de titre du film, **à suite de
 *   chiffres identique** (« Alien » n'est jamais proche d'« Alien 3 », la
 *   règle des chiffres stricts de `70` § 6.3). La tolérance est celle de la
 *   plus courte des deux formes : la suggestion n'est jamais plus large que
 *   la validation d'une réponse ;
 * - **même collection** (`same_collection`) : le même `collection_id`. Une
 *   saga n'est pas une même œuvre — elle doit pouvoir tomber ensemble
 *   (spec 30) — mais un remake s'y range souvent ; la suggestion le montre,
 *   le curateur tranche.
 *
 * Exclus : le film lui-même, les films retirés, et les **candidats exacts**
 * du J1 (`group_exact_candidates`), déjà proposés à part.
 *
 * Calcul en PHP sur les formes de titre du catalogue, lues par tranches :
 * le coût est payé sur clic seulement, la fiche servant ces candidats en
 * prop facultative chargée par rechargement partiel.
 */
final class MovieGroupCandidates
{
    public const string REASON_TITLE = 'title_distance';

    public const string REASON_COLLECTION = 'same_collection';

    /** Formes de titre lues par tranche. */
    private const int CHUNK = 1000;

    /**
     * @return list<array{movie: Movie, reasons: list<string>}>
     */
    public function for(Movie $movie): array
    {
        $excluded = SetMovieGroup::exactCandidates($movie)->modelKeys();
        $excluded[] = $movie->id;

        /** @var array<int, list<string>> $reasons movie_id → raisons */
        $reasons = [];

        foreach ($this->closeTitleMovieIds($movie) as $id) {
            $reasons[$id][] = self::REASON_TITLE;
        }

        if ($movie->collection_id !== null) {
            /** @var list<int> $sameCollection */
            $sameCollection = Movie::query()
                ->where('collection_id', $movie->collection_id)
                ->whereKeyNot($movie->id)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($sameCollection as $id) {
                $reasons[$id][] = self::REASON_COLLECTION;
            }
        }

        foreach ($excluded as $id) {
            unset($reasons[(int) $id]);
        }

        if ($reasons === []) {
            return [];
        }

        /** @var EloquentCollection<int, Movie> $movies */
        $movies = Movie::query()
            ->with('group:id,label')
            ->whereKey(array_keys($reasons))
            ->where('availability', '!=', ContentAvailability::Withdrawn->value)
            ->orderBy('release_year')
            ->orderBy('id')
            ->get();

        $candidates = [];

        foreach ($movies as $candidate) {
            $candidates[] = ['movie' => $candidate, 'reasons' => $reasons[$candidate->id]];
        }

        return $candidates;
    }

    /**
     * Les films dont une forme de titre est proche d'une forme du film.
     *
     * @return list<int>
     */
    private function closeTitleMovieIds(Movie $movie): array
    {
        $kinds = [
            AnswerKeyKind::TitleOriginal->value,
            AnswerKeyKind::TitleLatin->value,
            AnswerKeyKind::Title->value,
        ];

        /** @var list<string> $own */
        $own = AnswerKey::query()
            ->where('movie_id', $movie->id)
            ->whereIn('key_kind', $kinds)
            ->where('normalized', '!=', '')
            ->pluck('normalized')
            ->map(static fn (mixed $form): string => (string) $form)
            ->unique()
            ->values()
            ->all();

        if ($own === []) {
            return [];
        }

        $measured = array_map(static fn (string $form): array => [
            'form' => $form,
            'length' => strlen(AnswerKeyNormalizer::compact($form)),
            'full' => strlen($form),
            'digits' => AnswerKeyNormalizer::digits($form),
        ], $own);

        /** @var array<int, true> $found */
        $found = [];

        AnswerKey::query()
            ->select(['id', 'movie_id', 'normalized'])
            ->whereIn('key_kind', $kinds)
            ->where('movie_id', '!=', $movie->id)
            ->where('normalized', '!=', '')
            ->chunkById(self::CHUNK, static function (EloquentCollection $keys) use ($measured, &$found): void {
                /** @var EloquentCollection<int, AnswerKey> $keys */
                foreach ($keys as $key) {
                    if (isset($found[$key->movie_id])) {
                        continue;
                    }

                    $length = strlen(AnswerKeyNormalizer::compact($key->normalized));
                    $full = strlen($key->normalized);

                    foreach ($measured as $form) {
                        $tolerance = AnswerRules::tolerance(min($length, $form['length']));

                        // Filtre bon marché avant Levenshtein : l'écart de
                        // longueur minore chacune des deux distances, donc
                        // le plus petit des deux écarts minore leur minimum.
                        if (min(abs($length - $form['length']), abs($full - $form['full'])) > $tolerance) {
                            continue;
                        }

                        if (AnswerKeyNormalizer::digits($key->normalized) !== $form['digits']) {
                            continue;
                        }

                        if (AnswerKeyNormalizer::distance($form['form'], $key->normalized) <= $tolerance) {
                            $found[$key->movie_id] = true;

                            break;
                        }
                    }
                }
            });

        return array_keys($found);
    }
}
