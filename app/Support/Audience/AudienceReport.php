<?php

namespace App\Support\Audience;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Les agrégats de l'écran « Audience » — spec 20 § 12.4, spec 100 § 10.12
 * (D48 du 01/10).
 *
 * Tout est lu dans des compteurs quotidiens (`audience_daily`), la présence
 * des dix dernières minutes (`audience_presence`) et les tables du jeu pour
 * l'entonnoir : aucune donnée personnelle n'en sort. « Visiteurs » sur une
 * fenêtre est la **somme des visiteurs de chaque jour** : sans identifiant
 * durable, un visiteur revenu deux jours compte deux fois, et l'écran le dit.
 */
final class AudienceReport
{
    /** Au plus autant de lignes par classement. */
    public const int TOP = 20;

    /** Fenêtre du temps réel, en minutes. */
    public const int LIVE_MINUTES = 5;

    /**
     * @return array{since: string, totals: array{visitors: int, visits: int, pageviews: int}, daily: list<array{day: string, visitors: int, visits: int, pageviews: int}>, pages: list<array{name: string, total: int}>, entries: list<array{name: string, total: int}>, exits: list<array{name: string, total: int}>, referrers: list<array{name: string, total: int}>, locales: list<array{name: string, total: int}>, devices: list<array{name: string, total: int}>, live: array{visitors: int, pages: list<array{name: string, total: int}>, open_rooms: int, running_games: int, running_solo: int}, funnel: array{rooms_created: int, games_multiplayer: int, games_solo: int, games_completed: int, players_per_game: float|null}}
     */
    public static function build(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $sinceDay = $since->toDateString();

        $daily = [];

        $rows = DB::table('audience_daily')
            ->where('day', '>=', $sinceDay)
            ->whereIn('metric', [AudienceRecorder::METRIC_VISITORS, AudienceRecorder::METRIC_VISITS, AudienceRecorder::METRIC_PAGEVIEWS])
            ->groupBy('day', 'metric')
            ->orderBy('day')
            ->select(['day', 'metric'])
            ->selectRaw('sum(total) as total')
            ->get();

        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            $line = $daily[$day] ?? ['day' => $day, 'visitors' => 0, 'visits' => 0, 'pageviews' => 0];
            $total = (int) $row->total;

            match ((string) $row->metric) {
                AudienceRecorder::METRIC_VISITORS => $line['visitors'] = $total,
                AudienceRecorder::METRIC_VISITS => $line['visits'] = $total,
                default => $line['pageviews'] = $total,
            };

            $daily[$day] = $line;
        }

        $daily = array_values($daily);

        return [
            'since' => $since->toIso8601String(),
            'totals' => [
                'visitors' => array_sum(array_column($daily, 'visitors')),
                'visits' => array_sum(array_column($daily, 'visits')),
                'pageviews' => array_sum(array_column($daily, 'pageviews')),
            ],
            'daily' => $daily,
            'pages' => self::top($sinceDay, AudienceRecorder::METRIC_PAGEVIEWS),
            'entries' => self::top($sinceDay, AudienceRecorder::METRIC_ENTRIES),
            'exits' => self::top($sinceDay, AudienceRecorder::METRIC_EXITS),
            'referrers' => self::top($sinceDay, AudienceRecorder::METRIC_REFERRERS),
            'locales' => self::top($sinceDay, AudienceRecorder::METRIC_LOCALES),
            'devices' => self::top($sinceDay, AudienceRecorder::METRIC_DEVICES),
            'live' => self::live($now),
            'funnel' => self::funnel($since),
        ];
    }

    /**
     * Le classement d'une mesure sur la fenêtre, par total décroissant.
     *
     * @return list<array{name: string, total: int}>
     */
    private static function top(string $sinceDay, string $metric): array
    {
        $rows = DB::table('audience_daily')
            ->where('day', '>=', $sinceDay)
            ->where('metric', $metric)
            ->groupBy('dimension')
            ->select('dimension')
            ->selectRaw('sum(total) as total')
            ->orderByDesc('total')
            ->orderBy('dimension')
            ->limit(self::TOP)
            ->get();

        $top = [];

        foreach ($rows as $row) {
            if ((int) $row->total > 0) {
                $top[] = ['name' => (string) $row->dimension, 'total' => (int) $row->total];
            }
        }

        return $top;
    }

    /**
     * Le temps réel : visiteurs des cinq dernières minutes et leurs pages,
     * salons ouverts et parties en cours.
     *
     * @return array{visitors: int, pages: list<array{name: string, total: int}>, open_rooms: int, running_games: int, running_solo: int}
     */
    private static function live(CarbonImmutable $now): array
    {
        $cutoff = $now->subMinutes(self::LIVE_MINUTES)->format('Y-m-d H:i:s.v');

        $pages = [];

        $rows = DB::table('audience_presence')
            ->where('last_seen_at', '>=', $cutoff)
            ->groupBy('route')
            ->select('route')
            ->selectRaw('count(*) as total')
            ->orderByDesc('total')
            ->orderBy('route')
            ->get();

        foreach ($rows as $row) {
            $pages[] = ['name' => (string) $row->route, 'total' => (int) $row->total];
        }

        $running = Game::query()
            ->whereNull('ended_at')
            ->whereIn('status', [GameStatus::Running, GameStatus::Paused]);

        return [
            'visitors' => array_sum(array_column($pages, 'total')),
            'pages' => $pages,
            'open_rooms' => Room::query()->whereNull('archived_at')->where('status', '!=', RoomStatus::Archived)->count(),
            'running_games' => (clone $running)->where('mode', GameMode::Multiplayer)->count(),
            'running_solo' => (clone $running)->where('mode', GameMode::Solo)->count(),
        ];
    }

    /**
     * L'entonnoir de jeu sur la fenêtre, lu dans les tables du jeu.
     *
     * @return array{rooms_created: int, games_multiplayer: int, games_solo: int, games_completed: int, players_per_game: float|null}
     */
    private static function funnel(CarbonImmutable $since): array
    {
        $games = Game::query()->where('started_at', '>=', $since);
        $gameCount = (clone $games)->count();
        $seats = GamePlayer::query()->whereIn('game_id', (clone $games)->select('id'))->count();

        return [
            'rooms_created' => Room::query()->where('created_at', '>=', $since)->count(),
            'games_multiplayer' => (clone $games)->where('mode', GameMode::Multiplayer)->count(),
            'games_solo' => (clone $games)->where('mode', GameMode::Solo)->count(),
            'games_completed' => (clone $games)->where('status', GameStatus::Completed)->count(),
            'players_per_game' => $gameCount === 0 ? null : round($seats / $gameCount, 1),
        ];
    }
}
