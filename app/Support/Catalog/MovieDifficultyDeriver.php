<?php

namespace App\Support\Catalog;

use App\Enums\ContentAvailability;
use App\Enums\ImportSource;
use App\Enums\MovieDifficulty;
use App\Enums\ThemeKind;
use App\Models\Movie;
use App\Models\Theme;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * La difficulté intrinsèque dérivée d'un film (spec 30 § 14, lot L30-10).
 *
 * Notoriété normalisée **par décile de `original_language`**, pondérée par
 * l'appartenance à une saga retenue, corrigeable en curation : sans la
 * normalisation par langue, le filtre d'import et la voie d'exception
 * classeraient La Vie est belle ou Le Labyrinthe de Pan en « très difficile ».
 *
 * 1. **Population** : films hors démonstration et non retirés.
 * 2. **Seaux** : un par langue originale ; une langue sous
 *    `catalog.difficulty.min_language_sample` films rejoint le seau commun.
 * 3. **Décile** : `r` = films du seau de `vote_count` strictement inférieur,
 *    `d = intdiv(10 × r, n) + 1` ∈ 1..10 — entier, sans flottant ; deux films
 *    à égalité de votes ont le même décile.
 * 4. **Saga** : collection désignée par un thème `saga` (publié ou non) →
 *    `d = min(10, d + catalog.difficulty.saga_decile_bonus)`.
 * 5. **Classe** : deux déciles par cas nommé ({@see self::classFor()}).
 * 6. **Écriture** film par film, seulement si `movie_difficulty_derived`
 *    change ; la valeur effective est réécrite par une instruction qui
 *    relit l'override EN BASE (`COALESCE(movie_difficulty_override,
 *    movie_difficulty_derived)`), jamais une valeur lue plus tôt : une correction manuelle concurrente n'est jamais écrasée. Puis,
 *    dans la même transaction, la valeur effective relue et, si elle a
 *    changé, les thèmes de difficulté du film réévalués.
 *
 * `movie_difficulty_override` n'est jamais écrite ici. Lecture en une seule
 * requête triée par `(original_language, vote_count, id)`, servie par
 * `movie_decile_idx`, calcul en PHP sans `GROUP BY` (E10-N2).
 *
 * Seul lecteur de `config/catalog.php › difficulty` ; hors bornes, lève
 * `InvalidArgumentException` au démarrage, sans rien écrire.
 */
final readonly class MovieDifficultyDeriver
{
    /** Nombre de déciles : conséquence de la définition, pas un réglage. */
    private const int DECILES = 10;

    /** Bornes de la configuration (spec 30 § 14.2). */
    private const int MIN_LANGUAGE_SAMPLE_FLOOR = 1;

    private const int SAGA_BONUS_MAX = 9;

    /** Clé du seau commun : jamais une langue, qui a deux lettres. */
    private const string COMMON_BUCKET = '*';

    public function __construct(
        private ThemeEvaluator $themes,
    ) {}

    /**
     * Dérive la difficulté de toute la population ; rend le nombre de films
     * dont la valeur dérivée a changé.
     *
     * @throws InvalidArgumentException configuration hors bornes
     */
    public function derive(): int
    {
        $minSample = self::minLanguageSample();
        $sagaBonus = self::sagaDecileBonus();

        $sagaCollections = $this->sagaCollectionIds();

        /** @var list<object{id: int, original_language: string, vote_count: int, collection_id: int|null, movie_difficulty_derived: string|null}> $rows */
        $rows = DB::table('movie')
            ->where('import_source', '!=', ImportSource::Demo->value)
            ->where('availability', '!=', ContentAvailability::Withdrawn->value)
            ->orderBy('original_language')
            ->orderBy('vote_count')
            ->orderBy('id')
            ->get(['id', 'original_language', 'vote_count', 'collection_id', 'movie_difficulty_derived'])
            ->all();

        $targets = self::classify($rows, $minSample, $sagaBonus, $sagaCollections);

        $changed = 0;

        foreach ($rows as $row) {
            $target = $targets[(int) $row->id];

            if ($row->movie_difficulty_derived === $target->value) {
                continue;
            }

            if ($this->write((int) $row->id, $target)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * La classe de chaque film de la population, par identifiant.
     *
     * @param  list<object{id: int, original_language: string, vote_count: int, collection_id: int|null, movie_difficulty_derived: string|null}>  $rows  triées par langue, votes, id
     * @param  array<int, true>  $sagaCollections
     * @return array<int, MovieDifficulty>
     */
    public static function classify(array $rows, int $minSample, int $sagaBonus, array $sagaCollections): array
    {
        $perLanguage = [];

        foreach ($rows as $row) {
            $perLanguage[$row->original_language] = ($perLanguage[$row->original_language] ?? 0) + 1;
        }

        /** @var array<string, list<int>> $buckets votes de chaque seau */
        $buckets = [];

        foreach ($rows as $row) {
            $buckets[self::bucketOf($row->original_language, $perLanguage, $minSample)][] = (int) $row->vote_count;
        }

        foreach ($buckets as $key => $votes) {
            sort($votes);
            $buckets[$key] = $votes;
        }

        $classes = [];

        foreach ($rows as $row) {
            $votes = $buckets[self::bucketOf($row->original_language, $perLanguage, $minSample)];
            $decile = self::decile(self::countBelow($votes, (int) $row->vote_count), count($votes));

            if ($row->collection_id !== null && isset($sagaCollections[(int) $row->collection_id])) {
                $decile = min(self::DECILES, $decile + $sagaBonus);
            }

            $classes[(int) $row->id] = self::classFor($decile);
        }

        return $classes;
    }

    /**
     * `d = intdiv(10 × r, n) + 1` (spec 30 § 14.1 point 3).
     */
    public static function decile(int $below, int $size): int
    {
        return intdiv(self::DECILES * $below, $size) + 1;
    }

    /**
     * Deux déciles par cas nommé : 9-10 très facile … 1-2 très difficile.
     */
    public static function classFor(int $decile): MovieDifficulty
    {
        return match (true) {
            $decile >= 9 => MovieDifficulty::VeryEasy,
            $decile >= 7 => MovieDifficulty::Easy,
            $decile >= 5 => MovieDifficulty::Medium,
            $decile >= 3 => MovieDifficulty::Hard,
            default => MovieDifficulty::VeryHard,
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function minLanguageSample(): int
    {
        $value = config('catalog.difficulty.min_language_sample');

        if (! is_int($value) || $value < self::MIN_LANGUAGE_SAMPLE_FLOOR) {
            throw new InvalidArgumentException('catalog.difficulty.min_language_sample doit être un entier ≥ '.self::MIN_LANGUAGE_SAMPLE_FLOOR.'.');
        }

        return $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function sagaDecileBonus(): int
    {
        $value = config('catalog.difficulty.saga_decile_bonus');

        if (! is_int($value) || $value < 0 || $value > self::SAGA_BONUS_MAX) {
            throw new InvalidArgumentException('catalog.difficulty.saga_decile_bonus doit être un entier de 0 à '.self::SAGA_BONUS_MAX.'.');
        }

        return $value;
    }

    /**
     * Les collections désignées par un thème de saga, publié ou non : une
     * saga du § 12.4 est « retenue » dès qu'elle existe.
     *
     * @return array<int, true>
     */
    private function sagaCollectionIds(): array
    {
        $ids = [];

        foreach (Theme::query()->where('theme_kind', ThemeKind::Saga)->whereNotNull('rule_value')->pluck('rule_value') as $value) {
            if (is_string($value) && ctype_digit($value)) {
                $ids[(int) $value] = true;
            }
        }

        return $ids;
    }

    /**
     * Écrit la valeur dérivée d'un film, sous son verrou ; rend `true` si elle
     * a changé (une dérivation concurrente peut l'avoir déjà écrite).
     */
    private function write(int $movieId, MovieDifficulty $target): bool
    {
        return DB::transaction(function () use ($movieId, $target): bool {
            $movie = Movie::query()->whereKey($movieId)->lockForUpdate()->first();

            if (! $movie instanceof Movie || $movie->movie_difficulty_derived === $target) {
                return false;
            }

            $effectiveBefore = $movie->movie_difficulty;

            // Deux instructions sous le verrou du film, la seconde relisant
            // l'override ET la dérivée EN BASE : une correction manuelle
            // concurrente survit, et l'ordre d'évaluation d'un `SET` multiple
            // — anciennes valeurs en SQLite, de gauche à droite en MySQL —
            // n'entre pas en jeu.
            Movie::query()->whereKey($movieId)->update([
                'movie_difficulty_derived' => $target->value,
            ]);
            Movie::query()->whereKey($movieId)->update([
                'movie_difficulty' => DB::raw('COALESCE(movie_difficulty_override, movie_difficulty_derived)'),
            ]);

            $movie->refresh();

            if ($movie->movie_difficulty !== $effectiveBefore) {
                $this->themes->syncMovie($movie, ThemeKind::Difficulty);
            }

            return true;
        });
    }

    /**
     * @param  array<string, int>  $perLanguage
     */
    private static function bucketOf(string $language, array $perLanguage, int $minSample): string
    {
        return ($perLanguage[$language] ?? 0) >= $minSample ? $language : self::COMMON_BUCKET;
    }

    /**
     * Nombre de valeurs strictement inférieures à `$value` dans une liste
     * triée, par dichotomie.
     *
     * @param  list<int>  $sorted
     */
    private static function countBelow(array $sorted, int $value): int
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
