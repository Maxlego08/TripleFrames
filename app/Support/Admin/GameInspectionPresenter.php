<?php

namespace App\Support\Admin;

use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameTrace;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\WrongAnswer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Le seul constructeur des props de l'inspection des parties et des sièges —
 * spec 20 § 12.2 (D46 du 01/10), administrateur seul.
 *
 * **Règle 3, tenue ici et nulle part ailleurs** : pour une partie non
 * terminale (`running`, `paused`), une manche qui n'a pas atteint `revealing`,
 * `completed` ou `cancelled` n'est « divulgable » que par son numéro, son
 * statut et ses horaires. Son film, ses titres, ses paliers, ses bonnes
 * réponses et ses réponses fausses ne sont **ni chargés ni sérialisés** : les
 * requêtes ne portent que sur les identifiants des manches divulgables —
 * l'administrateur peut être assis dans la partie qu'il inspecte, et le
 * tirage est figé au lancement.
 *
 * Les attributs `#[Hidden]` des modèles (film, frame, points, textes) sont lus
 * EXPLICITEMENT, champ par champ, jamais par `toArray()` : l'écran reçoit ce
 * que cette classe nomme, rien de plus. Les instants partent en ISO-8601,
 * jamais pré-formatés.
 *
 * @phpstan-type RoundFacts array{titles: array<int, array<string, string>>, originals: array<int, string>, tiers: array<int, list<RoundTier>>, participations: array<int, list<RoundPlayer>>, guesses: array<string, Guess>, wrong: array<string, list<WrongAnswer>>}
 */
final class GameInspectionPresenter
{
    /** Les statuts de manche dont le contenu est publiable, partie en cours ou non. */
    private const array DISCLOSED_ROUND_STATUSES = [
        RoundStatus::Revealing,
        RoundStatus::Completed,
        RoundStatus::Cancelled,
    ];

    /**
     * Une ligne de la liste des parties. `participants_count` vient d'un
     * `withCount('gamePlayers')`.
     *
     * @return array<string, mixed>
     */
    public static function gameRow(Game $game): array
    {
        $count = $game->getAttribute('game_players_count');

        return [
            'id' => $game->id,
            'mode' => $game->mode->value,
            'status' => $game->status->value,
            'input_difficulty' => $game->input_difficulty->value,
            'frames_per_round' => $game->frames_per_round,
            'rounds_count' => $game->rounds_count,
            'rounds_completed' => $game->rounds_completed,
            'room_code' => $game->room?->room_code,
            'participants_count' => is_numeric($count) ? (int) $count : 0,
            'started_at' => self::moment($game->started_at),
            'finished_at' => self::moment($game->ended_at),
        ];
    }

    /**
     * Une ligne de l'annuaire des sièges. `games_count` vient d'un
     * `withCount('gamePlayers')`.
     *
     * @return array<string, mixed>
     */
    public static function playerRow(Player $player): array
    {
        $count = $player->getAttribute('game_players_count');

        return [
            ...self::playerIdentity($player),
            'games_count' => is_numeric($count) ? (int) $count : 0,
        ];
    }

    /**
     * La fiche d'une partie : réglages figés, classement, et chaque manche
     * avec, si elle est divulgable, ses paliers et le détail de chaque
     * participant.
     *
     * @return array<string, mixed>
     */
    public static function gameDetail(Game $game): array
    {
        $game->loadMissing('room');

        /** @var Collection<int, GamePlayer> $seats */
        $seats = GamePlayer::query()
            ->with(['player.room', 'player.user'])
            ->where('game_id', $game->id)
            ->orderByRaw('final_rank is null')
            ->orderBy('final_rank')
            ->orderBy('id')
            ->get();

        $nicknames = [];
        $publicIds = [];

        foreach ($seats as $seat) {
            $nicknames[$seat->player_id] = $seat->display_nickname ?? $seat->player->nickname;
            $publicIds[$seat->player_id] = $seat->player->public_id;
        }

        $rounds = Round::query()
            ->where('game_id', $game->id)
            ->orderBy('sequence_index')
            ->get();

        $facts = self::roundFacts($game, $rounds, null);

        $lines = [];

        foreach ($rounds as $round) {
            $lines[] = self::roundLine($game, $round, $facts, $nicknames, $publicIds);
        }

        $settings = $game->settings_snapshot;

        return [
            'game' => [
                ...self::gameRow($game),
                'paused_at' => self::moment($game->paused_at),
                'terminal' => self::isTerminal($game),
                'settings' => [
                    'round_duration' => $settings->roundDuration(),
                    'tier_durations' => $settings->tierDurations,
                    'tier_points' => $settings->tierPoints,
                    'reveal_duration' => $settings->revealDuration,
                    'speed_bonus' => $settings->speedBonus,
                    'attempts_per_round' => $settings->attemptsPerRound,
                    'max_answer_length' => $settings->maxAnswerLength,
                    'capacity' => $settings->capacity,
                    'allow_late_join' => $settings->allowLateJoin,
                ],
                'versions' => [
                    'settings' => $game->settings_version,
                    'scoring' => $game->scoring_version,
                    'validation' => $game->validation_version,
                ],
            ],
            'leaderboard' => $seats->map(fn (GamePlayer $seat): array => [
                'player' => self::playerIdentity($seat->player, $seat->display_nickname),
                'status' => $seat->status->value,
                'first_round_number' => $seat->first_round_number,
                'played_rounds' => $seat->rounds_played,
                'correct_count' => $seat->correct_answers,
                'score' => $seat->final_score,
                'rank' => $seat->final_rank,
            ])->values()->all(),
            'rounds' => $lines,
            'trace' => self::trace($game, $rounds, $publicIds),
        ];
    }

    /**
     * La chronologie technique de la partie (D47 du 01/10, `game_trace`) :
     * transitions, diffusions et leur retard, jobs de frontière, soumissions
     * et resynchronisations. Aucune ligne ne porte de contenu de jeu ; un job
     * désigne sa manche par son identifiant interne, traduit ici en numéro de
     * tirage.
     *
     * @param  Collection<int, Round>  $rounds
     * @param  array<int, string>  $publicIds
     * @return list<array<string, mixed>>
     */
    private static function trace(Game $game, Collection $rounds, array $publicIds): array
    {
        $sequences = [];

        foreach ($rounds as $round) {
            $sequences[$round->id] = $round->sequence_index;
        }

        $lines = [];

        foreach (GameTrace::query()->where('game_id', $game->id)->orderBy('id')->get() as $line) {
            $details = $line->details ?? [];
            $roundId = $details['round'] ?? null;
            unset($details['round']);

            $lines[] = [
                'event' => $line->event,
                'sequence_index' => $line->sequence_index ?? (is_int($roundId) ? ($sequences[$roundId] ?? null) : null),
                'tier_index' => $line->tier_index,
                'player_id' => $line->player_id === null ? null : ($publicIds[$line->player_id] ?? null),
                'theoretical_at' => self::moment($line->theoretical_at),
                'recorded_at' => self::moment($line->recorded_at),
                'delay_ms' => $line->delay_ms,
                'duration_ms' => $line->duration_ms,
                'query_count' => $line->query_count,
                'details' => $details,
            ];
        }

        return $lines;
    }

    /**
     * La fiche d'un siège : identité, et chacune de ses parties, manche par
     * manche, réduite à ses propres réponses.
     *
     * @return array<string, mixed>
     */
    public static function playerDetail(Player $player): array
    {
        /** @var Collection<int, GamePlayer> $seats */
        $seats = GamePlayer::query()
            ->with('game.room')
            ->where('player_id', $player->id)
            ->orderByDesc('id')
            ->get();

        $games = [];

        foreach ($seats as $seat) {
            $game = $seat->game;

            $rounds = Round::query()
                ->where('game_id', $game->id)
                ->orderBy('sequence_index')
                ->get();

            $facts = self::roundFacts($game, $rounds, $player->id);
            $nickname = [$player->id => $seat->display_nickname ?? $player->nickname];
            $publicId = [$player->id => $player->public_id];

            $lines = [];

            foreach ($rounds as $round) {
                $line = self::roundLine($game, $round, $facts, $nickname, $publicId);
                $line['participation'] = $line['participants'][0] ?? null;
                unset($line['participants']);
                $lines[] = $line;
            }

            $games[] = [
                'game' => self::gameRow($game),
                'status' => $seat->status->value,
                'score' => $seat->final_score,
                'rank' => $seat->final_rank,
                'correct_count' => $seat->correct_answers,
                'rounds' => $lines,
            ];
        }

        $player->loadMissing(['room', 'user', 'visitor']);

        return [
            'player' => [
                ...self::playerIdentity($player),
                'locale' => $player->locale->value,
                'connection_state' => $player->connection_state->value,
                'left_at' => self::moment($player->left_at),
                'kicked_at' => self::moment($player->kicked_at),
                'nickname_masked_at' => self::moment($player->nickname_masked_at),
                // L'appareil grossier, pour un visiteur consentant seulement
                // (D62 du 06/10) ; nul sinon.
                'device' => $player->device_class === null ? null : [
                    'class' => $player->device_class,
                    'browser' => $player->browser_family,
                    'os' => $player->os_family,
                ],
            ],
            'visitor' => self::visitorOf($player),
            'games' => $games,
        ];
    }

    /**
     * Vrai si la partie est close : le gel a eu lieu, plus aucune manche ne
     * se jouera.
     */
    public static function isTerminal(Game $game): bool
    {
        return in_array($game->status, [GameStatus::Completed, GameStatus::Interrupted], true);
    }

    /**
     * Vrai si le contenu de la manche se montre : manche révélée, terminée ou
     * annulée ; ou, partie close, toute manche qui a commencé.
     */
    public static function isDisclosed(Game $game, Round $round): bool
    {
        if (in_array($round->status, self::DISCLOSED_ROUND_STATUSES, true)) {
            return true;
        }

        return self::isTerminal($game) && $round->started_at !== null;
    }

    /**
     * Les faits des seules manches divulgables, en une requête par table :
     * titres, paliers, participations, bonnes et fausses réponses, éventuellement
     * restreints à un siège.
     *
     * @param  Collection<int, Round>  $rounds
     * @return RoundFacts
     */
    private static function roundFacts(Game $game, Collection $rounds, ?int $playerId): array
    {
        $roundIds = [];
        $movieIds = [];

        foreach ($rounds as $round) {
            if (self::isDisclosed($game, $round)) {
                $roundIds[] = $round->id;
                $movieIds[$round->movie_id] = $round->movie_id;
            }
        }

        $movieIds = array_values($movieIds);

        $facts = [
            'titles' => [],
            'originals' => [],
            'tiers' => [],
            'participations' => [],
            'guesses' => [],
            'wrong' => [],
        ];

        foreach (MovieTitle::query()->whereIn('movie_id', $movieIds)->orderBy('locale')->get() as $title) {
            $facts['titles'][$title->movie_id][$title->locale] = $title->title;
        }

        foreach (Movie::query()->whereKey($movieIds)->get(['id', 'title_original']) as $movie) {
            $facts['originals'][$movie->id] = $movie->title_original;
        }

        foreach (RoundTier::query()->whereIn('round_id', $roundIds)->orderBy('tier_index')->get() as $tier) {
            $facts['tiers'][$tier->round_id][] = $tier;
        }

        $participations = RoundPlayer::query()->whereIn('round_id', $roundIds)->orderBy('id');
        $guesses = Guess::query()->whereIn('round_id', $roundIds);
        $wrong = WrongAnswer::query()->whereIn('round_id', $roundIds)->orderBy('id');

        if ($playerId !== null) {
            $participations->where('player_id', $playerId);
            $guesses->where('player_id', $playerId);
            $wrong->where('player_id', $playerId);
        }

        foreach ($participations->get() as $participation) {
            $facts['participations'][$participation->round_id][] = $participation;
        }

        foreach ($guesses->get() as $guess) {
            $facts['guesses'][$guess->round_id.':'.$guess->player_id] = $guess;
        }

        foreach ($wrong->get() as $answer) {
            $facts['wrong'][$answer->round_id.':'.$answer->player_id][] = $answer;
        }

        return $facts;
    }

    /**
     * Une manche : toujours son numéro, son statut et ses horaires ; le reste
     * seulement si elle est divulgable.
     *
     * @param  RoundFacts  $facts
     * @param  array<int, string|null>  $nicknames
     * @param  array<int, string>  $publicIds
     * @return array<string, mixed>
     */
    private static function roundLine(Game $game, Round $round, array $facts, array $nicknames, array $publicIds): array
    {
        $line = [
            'sequence_index' => $round->sequence_index,
            'round_number' => $round->round_number,
            'status' => $round->status->value,
            'started_at' => self::moment($round->started_at),
            'finished_at' => self::moment($round->ended_at),
            'cancel_reason' => $round->cancel_reason?->value,
            'disclosed' => false,
            'movie' => null,
            'found_count' => null,
            'tiers' => [],
            'participants' => [],
        ];

        if (! self::isDisclosed($game, $round)) {
            return $line;
        }

        $tiers = $facts['tiers'][$round->id] ?? [];

        $line['disclosed'] = true;
        $line['found_count'] = $round->found_count;
        $line['movie'] = [
            'id' => $round->movie_id,
            'title_original' => $facts['originals'][$round->movie_id] ?? null,
            'titles' => $facts['titles'][$round->movie_id] ?? [],
        ];
        $line['tiers'] = array_map(fn (RoundTier $tier): array => [
            'tier_index' => $tier->tier_index,
            'frame_level' => $tier->frame_level->value,
            'substitution' => $tier->substitution_reason?->value,
            'starts_at_offset_ms' => $tier->starts_at_offset_ms,
            'duration_ms' => $tier->duration_ms,
            'points' => $tier->points,
            'opened_at' => self::moment($tier->served_at),
        ], $tiers);

        foreach ($facts['participations'][$round->id] ?? [] as $participation) {
            $key = $round->id.':'.$participation->player_id;
            $guess = $facts['guesses'][$key] ?? null;
            $wrong = $facts['wrong'][$key] ?? [];

            $line['participants'][] = [
                'player_id' => $publicIds[$participation->player_id] ?? null,
                'nickname' => $nicknames[$participation->player_id] ?? null,
                'input_state' => $participation->input_state->value,
                'wrong_attempts' => $participation->wrong_attempts,
                'input_closed_at' => self::moment($participation->input_closed_at),
                'guess' => $guess instanceof Guess ? [
                    'received_at' => self::moment($guess->received_at),
                    'answered_at_ms' => $guess->answered_at_ms,
                    'tier_index' => $guess->tier_index,
                    'lock_rank' => $guess->lock_rank,
                    'source' => $guess->source->value,
                    'match_kind' => $guess->match_kind->value,
                    'answer_key_normalized' => $guess->answer_key_normalized,
                    'submitted_normalized' => $guess->submitted_normalized,
                    'edit_distance' => $guess->edit_distance,
                    'prefix_was_ambiguous' => $guess->prefix_was_ambiguous,
                    'tier_points' => $guess->points_tier,
                    'bonus_points' => $guess->points_bonus,
                    'total_points' => $guess->points_total,
                ] : null,
                'wrong_answers' => array_map(fn (WrongAnswer $answer): array => [
                    'source' => $answer->source->value,
                    'submitted_text' => $answer->submitted_text,
                    'submitted_normalized' => $answer->submitted_normalized,
                    'attempt_number' => $answer->attempt_number,
                    'received_at' => self::moment($answer->received_at),
                    'answered_at_ms' => $answer->answered_at_ms,
                    'tier_index' => self::tierAt($tiers, $answer->answered_at_ms),
                ], $wrong),
            ];
        }

        return $line;
    }

    /**
     * Le palier affiché à `$answeredAtMs` : le dernier dont l'ouverture le
     * précède. Une lecture d'affichage, jamais un score.
     *
     * @param  list<RoundTier>  $tiers
     */
    private static function tierAt(array $tiers, int $answeredAtMs): ?int
    {
        $index = null;

        foreach ($tiers as $tier) {
            if ($tier->starts_at_offset_ms <= $answeredAtMs) {
                $index = $tier->tier_index;
            }
        }

        return $index;
    }

    /**
     * L'identité d'un siège : identifiant public, pseudo ou effacement, origine
     * et compte lié.
     *
     * @return array<string, mixed>
     */
    private static function playerIdentity(Player $player, ?string $frozenNickname = null): array
    {
        $nickname = $frozenNickname ?? $player->nickname;
        $user = $player->user;

        return [
            'public_id' => $player->public_id,
            'nickname' => $nickname,
            'erased' => $nickname === null,
            'masked' => $player->nickname_masked_at !== null,
            'solo' => $player->room_id === null,
            'room_code' => $player->room?->room_code,
            'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name],
            'joined_at' => self::moment($player->joined_at),
            'last_seen_at' => self::moment($player->last_seen_at),
        ];
    }

    /**
     * Le visiteur consentant du siège et ses AUTRES sièges, du plus récent au
     * plus ancien (D62 du 06/10) : de quoi voir qu'un joueur revient, sous
     * quel pseudo et sur quel appareil. Nul sans visiteur.
     *
     * @return array{consented_at: string|null, first_seen_at: string|null, last_seen_at: string|null, seats: list<array<string, mixed>>}|null
     */
    private static function visitorOf(Player $player): ?array
    {
        $visitor = $player->visitor;

        if ($visitor === null) {
            return null;
        }

        $seats = array_values(Player::query()
            ->with(['room', 'user'])
            ->where('visitor_id', $visitor->id)
            ->whereKeyNot($player->id)
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Player $seat): array => [
                ...self::playerIdentity($seat),
                'device' => $seat->device_class === null ? null : [
                    'class' => $seat->device_class,
                    'browser' => $seat->browser_family,
                    'os' => $seat->os_family,
                ],
            ])
            ->all());

        return [
            'consented_at' => self::moment($visitor->consented_at),
            'first_seen_at' => self::moment($visitor->first_seen_at),
            'last_seen_at' => self::moment($visitor->last_seen_at),
            'seats' => $seats,
        ];
    }

    /** Un instant, en ISO-8601 — jamais une chaîne pré-formatée côté serveur. */
    private static function moment(?CarbonImmutable $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
