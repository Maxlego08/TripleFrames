<?php

namespace App\Support\Admin;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Models\Movie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Les agrégats de l'écran « Statistiques de jeu » — spec 20 § 12.6 (demande
 * du porteur du 08/10).
 *
 * Tout est lu dans les tables du jeu, sur les parties **lancées** dans la
 * fenêtre : aucun pseudo, aucun siège, aucune donnée personnelle n'en sort.
 * Les réponses et les films ne viennent que des manches **terminées**
 * (`round.status = completed`), donc révélées : une manche en cours ne
 * livre jamais son film, même au back-office (règle 3).
 *
 * Jours et heures sont ceux du serveur (UTC) ; l'écran décale les heures
 * dans le fuseau du navigateur.
 *
 * @phpstan-type MovieRow array{id: int, title: string, year: int|null, rounds: int, participations: int, finds: int, find_rate: float|null}
 */
final class GameStatsReport
{
    /** Au plus autant de films par classement. */
    public const int TOP_MOVIES = 10;

    /** Un film n'entre au classement « difficile » ou « facile » qu'avec autant de participations. */
    public const int RATED_MIN_PARTICIPATIONS = 5;

    /** Heures d'une journée. */
    private const int HOURS = 24;

    /**
     * @return array{
     *     since: string,
     *     totals: array{games: int, multiplayer: int, solo: int, completed: int, interrupted: int, running: int, players_per_game: float|null, average_duration_ms: int|null, rounds: int, participations: int, finds: int, find_rate: float|null, average_find_ms: int|null, wrong_per_participation: float|null},
     *     daily: list<array{day: string, multiplayer: int, solo: int, completed: int, interrupted: int}>,
     *     hours: list<array{hour: int, games: int}>,
     *     difficulty: list<array{name: string, total: int}>,
     *     frames: list<array{name: string, total: int}>,
     *     rounds_count: list<array{name: string, total: int}>,
     *     tiers: list<array{tier: int, finds: int}>,
     *     sources: list<array{name: string, total: int}>,
     *     movies: array{played: list<MovieRow>, hardest: list<MovieRow>, easiest: list<MovieRow>},
     *     rated_min_participations: int
     * }
     */
    public static function build(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $daily = [];

        for ($cursor = $since->startOfDay(); $cursor->lessThanOrEqualTo($now); $cursor = $cursor->addDay()) {
            $daily[$cursor->toDateString()] = ['day' => $cursor->toDateString(), 'multiplayer' => 0, 'solo' => 0, 'completed' => 0, 'interrupted' => 0];
        }

        $hours = array_fill(0, self::HOURS, 0);
        $difficulty = [];
        $frames = [];
        $roundsCount = [];
        $totals = ['games' => 0, 'multiplayer' => 0, 'solo' => 0, 'completed' => 0, 'interrupted' => 0, 'running' => 0];
        $durationSum = 0;
        $durationCount = 0;

        $games = DB::table('game')
            ->where('started_at', '>=', $since->format('Y-m-d H:i:s'))
            ->select(['id', 'mode', 'status', 'input_difficulty', 'rounds_count', 'frames_per_round', 'started_at', 'ended_at', 'total_paused_ms'])
            ->lazyById(500, 'id');

        foreach ($games as $game) {
            $startedAt = CarbonImmutable::parse((string) $game->started_at);
            $day = $startedAt->toDateString();
            $mode = (string) $game->mode;
            $status = (string) $game->status;

            $totals['games']++;
            $hours[$startedAt->hour]++;

            if (isset($daily[$day])) {
                $daily[$day][$mode === GameMode::Solo->value ? 'solo' : 'multiplayer']++;
            }

            $totals[$mode === GameMode::Solo->value ? 'solo' : 'multiplayer']++;

            match ($status) {
                GameStatus::Completed->value => $totals['completed']++,
                GameStatus::Interrupted->value => $totals['interrupted']++,
                default => $totals['running']++,
            };

            if (isset($daily[$day]) && in_array($status, [GameStatus::Completed->value, GameStatus::Interrupted->value], true)) {
                $daily[$day][$status === GameStatus::Completed->value ? 'completed' : 'interrupted']++;
            }

            if ($status === GameStatus::Completed->value && $game->ended_at !== null) {
                $durationSum += max(0, (int) $startedAt->diffInMilliseconds(CarbonImmutable::parse((string) $game->ended_at)) - (int) $game->total_paused_ms);
                $durationCount++;
            }

            $difficulty[(string) $game->input_difficulty] = ($difficulty[(string) $game->input_difficulty] ?? 0) + 1;
            $frames[(string) $game->frames_per_round] = ($frames[(string) $game->frames_per_round] ?? 0) + 1;
            $roundsCount[(string) $game->rounds_count] = ($roundsCount[(string) $game->rounds_count] ?? 0) + 1;
        }

        $seats = DB::table('game_player')
            ->join('game', 'game.id', '=', 'game_player.game_id')
            ->where('game.started_at', '>=', $since->format('Y-m-d H:i:s'))
            ->where('game.mode', GameMode::Multiplayer->value)
            ->count();

        $rounds = self::completedRounds($since);
        $roundCount = (clone $rounds)->count();

        $participation = DB::table('round_player')
            ->whereIn('round_id', (clone $rounds)->select('round.id'))
            ->selectRaw('count(*) as participations, coalesce(sum(wrong_attempts), 0) as wrong')
            ->first();

        $participations = (int) ($participation->participations ?? 0);

        $guesses = DB::table('guess')->whereIn('round_id', (clone $rounds)->select('round.id'));
        $finds = (clone $guesses)->count();
        $averageFind = (clone $guesses)->avg('answered_at_ms');

        $tiers = [];

        foreach ((clone $guesses)->groupBy('tier_index')->orderBy('tier_index')->select('tier_index')->selectRaw('count(*) as total')->get() as $row) {
            $tiers[] = ['tier' => (int) $row->tier_index, 'finds' => (int) $row->total];
        }

        $sources = [];

        foreach ((clone $guesses)->groupBy('source')->orderBy('source')->select('source')->selectRaw('count(*) as total')->get() as $row) {
            $sources[] = ['name' => (string) $row->source, 'total' => (int) $row->total];
        }

        return [
            'since' => $since->toIso8601String(),
            'totals' => [
                ...$totals,
                'players_per_game' => $totals['multiplayer'] === 0 ? null : round($seats / $totals['multiplayer'], 1),
                'average_duration_ms' => $durationCount === 0 ? null : intdiv($durationSum, $durationCount),
                'rounds' => $roundCount,
                'participations' => $participations,
                'finds' => $finds,
                'find_rate' => $participations === 0 ? null : round($finds / $participations, 3),
                'average_find_ms' => $averageFind === null ? null : (int) round((float) $averageFind),
                'wrong_per_participation' => $participations === 0 ? null : round((int) ($participation->wrong ?? 0) / $participations, 2),
            ],
            'daily' => array_values($daily),
            'hours' => array_map(static fn (int $hour, int $games): array => ['hour' => $hour, 'games' => $games], array_keys($hours), array_values($hours)),
            'difficulty' => self::ranked($difficulty, byName: false),
            'frames' => self::ranked($frames, byName: true),
            'rounds_count' => self::ranked($roundsCount, byName: true),
            'tiers' => $tiers,
            'sources' => $sources,
            'movies' => self::movies($rounds),
            'rated_min_participations' => self::RATED_MIN_PARTICIPATIONS,
        ];
    }

    /** Les manches terminées — donc révélées — des parties lancées dans la fenêtre. */
    private static function completedRounds(CarbonImmutable $since): Builder
    {
        return DB::table('round')
            ->join('game', 'game.id', '=', 'round.game_id')
            ->where('game.started_at', '>=', $since->format('Y-m-d H:i:s'))
            ->where('round.status', RoundStatus::Completed->value);
    }

    /**
     * Les films les plus joués, et, parmi ceux qui ont assez de
     * participations, les plus durs et les plus faciles à trouver.
     *
     * @return array{played: list<MovieRow>, hardest: list<MovieRow>, easiest: list<MovieRow>}
     */
    private static function movies(Builder $rounds): array
    {
        /** @var array<int, array{rounds: int, participations: int, finds: int}> $stats */
        $stats = [];

        foreach ((clone $rounds)->groupBy('round.movie_id')->select('round.movie_id')->selectRaw('count(*) as total')->get() as $row) {
            $stats[(int) $row->movie_id] = ['rounds' => (int) $row->total, 'participations' => 0, 'finds' => 0];
        }

        foreach ((clone $rounds)->join('round_player', 'round_player.round_id', '=', 'round.id')->groupBy('round.movie_id')->select('round.movie_id')->selectRaw('count(*) as total')->get() as $row) {
            if (isset($stats[(int) $row->movie_id])) {
                $stats[(int) $row->movie_id]['participations'] = (int) $row->total;
            }
        }

        foreach ((clone $rounds)->join('guess', 'guess.round_id', '=', 'round.id')->groupBy('round.movie_id')->select('round.movie_id')->selectRaw('count(*) as total')->get() as $row) {
            if (isset($stats[(int) $row->movie_id])) {
                $stats[(int) $row->movie_id]['finds'] = (int) $row->total;
            }
        }

        $rows = [];

        foreach ($stats as $id => $line) {
            $rows[] = [
                'id' => $id,
                'title' => '',
                'year' => null,
                ...$line,
                'find_rate' => $line['participations'] === 0 ? null : round($line['finds'] / $line['participations'], 3),
            ];
        }

        $played = $rows;
        usort($played, static fn (array $a, array $b): int => [$b['rounds'], $b['participations'], $a['id']] <=> [$a['rounds'], $a['participations'], $b['id']]);

        $rated = array_values(array_filter($rows, static fn (array $row): bool => $row['participations'] >= self::RATED_MIN_PARTICIPATIONS));

        $hardest = $rated;
        usort($hardest, static fn (array $a, array $b): int => [$a['find_rate'], $b['participations'], $a['id']] <=> [$b['find_rate'], $a['participations'], $b['id']]);

        $easiest = $rated;
        usort($easiest, static fn (array $a, array $b): int => [$b['find_rate'], $b['participations'], $a['id']] <=> [$a['find_rate'], $a['participations'], $b['id']]);

        $lists = [
            'played' => array_slice($played, 0, self::TOP_MOVIES),
            'hardest' => array_slice($hardest, 0, self::TOP_MOVIES),
            'easiest' => array_slice($easiest, 0, self::TOP_MOVIES),
        ];

        $ids = array_unique(array_merge(...array_map(static fn (array $list): array => array_column($list, 'id'), array_values($lists))));
        $movies = Movie::query()->whereIn('id', $ids)->get(['id', 'title_original', 'release_year'])->keyBy('id');

        $named = [];

        foreach ($lists as $key => $list) {
            $named[$key] = [];

            foreach ($list as $row) {
                $named[$key][] = self::withTitle($row, $movies);
            }
        }

        return $named;
    }

    /**
     * Le titre original et l'année d'un film classé.
     *
     * @param  MovieRow  $row
     * @param  Collection<int, Movie>  $movies
     * @return MovieRow
     */
    private static function withTitle(array $row, Collection $movies): array
    {
        $movie = $movies->get($row['id']);

        return [
            ...$row,
            'title' => $movie instanceof Movie ? $movie->title_original : '',
            'year' => $movie instanceof Movie ? $movie->release_year : null,
        ];
    }

    /**
     * Un décompte en liste, par total décroissant ou par nom numérique.
     *
     * @param  array<string, int>  $counts
     * @return list<array{name: string, total: int}>
     */
    private static function ranked(array $counts, bool $byName): array
    {
        $rows = [];

        foreach ($counts as $name => $total) {
            $rows[] = ['name' => (string) $name, 'total' => $total];
        }

        usort($rows, $byName
            ? static fn (array $a, array $b): int => (int) $a['name'] <=> (int) $b['name']
            : static fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return $rows;
    }
}
