<?php

namespace App\Support\Curation;

use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Models\Movie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Films jamais trouvés et incidents (spec 20 § 12.1, lot L20-29, question 22).
 *
 * **Agrégat par `movie_id`** sur `catalog.curation.incidents_window_days`
 * jours glissants :
 *
 * - manches `completed` terminées dans la fenêtre (`ended_at`) et, parmi
 *   elles, celles que personne n'a trouvées (`found_count = 0`) ;
 * - manches annulées dans la fenêtre (`cancelled_at`), par `cancel_reason` ;
 * - paliers substitués des manches démarrées dans la fenêtre
 *   (`round.started_at`), par `substitution_reason`.
 *
 * Lecture de `round` par son préfixe `movie_id` (`round_movie_found_idx`) et de
 * `round_tier` joint à sa seule manche ; **jamais `round_player`, `guess` ni
 * `player`** : aucune identité de joueur n'entre dans cette file (`10` § 7.4).
 *
 * Seul lecteur de `catalog.curation.incidents_window_days` ; hors bornes
 * (`< 1` ou non entier), lève `InvalidArgumentException`.
 */
final class IncidentReport
{
    /**
     * Les films qui ont au moins une manche jamais trouvée, annulée ou un
     * palier substitué dans la fenêtre, du plus touché au moins touché.
     *
     * @return list<array{
     *     movie: Movie,
     *     completed: int,
     *     never_found: int,
     *     cancelled: array<string, int>,
     *     substituted: array<string, int>,
     * }>
     */
    public function rows(?CarbonImmutable $now = null): array
    {
        $since = ($now ?? CarbonImmutable::now())->subDays(self::windowDays());

        /** @var array<int, array{completed: int, never_found: int, cancelled: array<string, int>, substituted: array<string, int>}> $byMovie */
        $byMovie = [];

        $empty = static fn (): array => ['completed' => 0, 'never_found' => 0, 'cancelled' => [], 'substituted' => []];

        $rounds = DB::table('round')
            ->selectRaw('movie_id, COUNT(*) AS completed, SUM(CASE WHEN found_count = 0 THEN 1 ELSE 0 END) AS never_found')
            ->where('status', RoundStatus::Completed->value)
            ->where('ended_at', '>=', $since)
            ->groupBy('movie_id')
            ->get();

        foreach ($rounds as $row) {
            $movieId = (int) $row->movie_id;
            $byMovie[$movieId] ??= $empty();
            $byMovie[$movieId]['completed'] = (int) $row->completed;
            $byMovie[$movieId]['never_found'] = (int) $row->never_found;
        }

        $cancellations = DB::table('round')
            ->selectRaw('movie_id, cancel_reason, COUNT(*) AS total')
            ->whereNotNull('cancel_reason')
            ->where('cancelled_at', '>=', $since)
            ->groupBy('movie_id', 'cancel_reason')
            ->get();

        foreach ($cancellations as $row) {
            $movieId = (int) $row->movie_id;
            $byMovie[$movieId] ??= $empty();
            $byMovie[$movieId]['cancelled'][(string) $row->cancel_reason] = (int) $row->total;
        }

        $substitutions = DB::table('round_tier')
            ->join('round', 'round.id', '=', 'round_tier.round_id')
            ->selectRaw('round.movie_id AS movie_id, round_tier.substitution_reason AS reason, COUNT(*) AS total')
            ->whereNotNull('round_tier.substitution_reason')
            ->where('round.started_at', '>=', $since)
            ->groupBy('round.movie_id', 'round_tier.substitution_reason')
            ->get();

        foreach ($substitutions as $row) {
            $movieId = (int) $row->movie_id;
            $byMovie[$movieId] ??= $empty();
            $byMovie[$movieId]['substituted'][(string) $row->reason] = (int) $row->total;
        }

        // Un film trouvé à chaque manche, sans annulation ni substitution,
        // n'est pas un incident.
        $byMovie = array_filter(
            $byMovie,
            static fn (array $row): bool => $row['never_found'] > 0 || $row['cancelled'] !== [] || $row['substituted'] !== [],
        );

        if ($byMovie === []) {
            return [];
        }

        $movies = Movie::query()
            ->whereKey(array_keys($byMovie))
            ->get(['id', 'title_original', 'release_year', 'availability'])
            ->keyBy('id');

        $result = [];

        foreach ($byMovie as $movieId => $row) {
            $movie = $movies->get($movieId);

            if (! $movie instanceof Movie) {
                continue;
            }

            ksort($row['cancelled']);
            ksort($row['substituted']);

            $result[] = ['movie' => $movie] + $row;
        }

        usort($result, static fn (array $a, array $b): int => [
            $b['never_found'],
            array_sum($b['cancelled']),
            array_sum($b['substituted']),
            $a['movie']->id,
        ] <=> [
            $a['never_found'],
            array_sum($a['cancelled']),
            array_sum($a['substituted']),
            $b['movie']->id,
        ]);

        return $result;
    }

    /**
     * Les motifs d'incident, dans l'ordre de l'énumération : l'écran les
     * nomme sans jamais composer une clé.
     *
     * @return list<string>
     */
    public static function reasons(): array
    {
        return array_map(static fn (RoundIncidentReason $reason): string => $reason->value, RoundIncidentReason::cases());
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function windowDays(): int
    {
        $days = config('catalog.curation.incidents_window_days');

        if (! is_int($days) || $days < 1) {
            throw new InvalidArgumentException('catalog.curation.incidents_window_days doit être un entier ≥ 1.');
        }

        return $days;
    }
}
