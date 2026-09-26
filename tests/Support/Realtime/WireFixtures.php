<?php

namespace Tests\Support\Realtime;

use App\Enums\GamePlayerStatus;
use App\Enums\Locale;
use App\Enums\RoomStatus;
use App\Enums\RoundStatus;
use App\Events\Game\GameEnded;
use App\Events\Game\GameLaunched;
use App\Events\Game\GamePaused;
use App\Events\Game\GameResumed;
use App\Events\Game\HostChanged;
use App\Events\Game\PlayerLocked;
use App\Events\Game\RoomArchived;
use App\Events\Game\RoomBroadcast;
use App\Events\Game\RoomReplayed;
use App\Events\Game\RoundCancelled;
use App\Events\Game\RoundClosed;
use App\Events\Game\RoundRevealed;
use App\Events\Game\RoundScheduled;
use App\Events\Game\SeatBroadcast;
use App\Events\Game\SeatChoicesOffered;
use App\Events\Game\SeatJoined;
use App\Events\Game\SeatKicked;
use App\Events\Game\SeatSuperseded;
use App\Events\Game\SeatUpdated;
use App\Events\Game\SettingsChanged;
use App\Events\Game\TierOpened;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Settings\EngineConstants;
use App\Support\Answers\ChoicesPresenter;
use App\Support\Game\SeatViewPresenter;
use App\Support\Realtime\WireTime;
use App\Support\Room\RoomSettingsPresenter;
use App\Support\Scoring\Scoreboard;
use App\ValueObjects\Scoring\TierWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use LogicException;
use Tests\Support\Scoring\ScoringFixtures;

/**
 * Les dix-neuf événements de la liste close du J1 (spec 60 § 11.3), chacun
 * construit sur une partie réelle avec une charge de la forme que son
 * émetteur produira (§ 11.5), pour éprouver l'enveloppe, le transport et la
 * garde d'identifiants internes sur ce qui part vraiment.
 *
 * Les blocs dont le producteur existe déjà viennent de lui :
 * `SeatViewPresenter` (C7, sur `PlayerIdentity` de C5),
 * `TierWindow::fromRoundTier()` et `Scoreboard` (C13),
 * `ChoicesPresenter` (C11), `RoomSettingsPresenter` (C0). Les autres —
 * `RevealMovie` (L60-6), `Podium` (L80-5), l'URL signée d'image (L60-8) — sont
 * composés à la main à la forme de leur type client, en attendant leur lot.
 */
final class WireFixtures
{
    public static function scene(): WireScene
    {
        $game = ScoringFixtures::game();
        $room = Room::query()->findOrFail($game->room_id);

        $host = ScoringFixtures::seat($game);
        $guest = ScoringFixtures::seat($game);

        $room->host_player_id = $host->id;
        $room->status = RoomStatus::Playing;
        $room->save();

        $revealed = ScoringFixtures::round($game, 1, RoundStatus::Revealing);
        ScoringFixtures::find($revealed, $host, 4_200);
        ScoringFixtures::played($revealed, $guest);

        $scheduled = ScoringFixtures::round($game, 2, RoundStatus::Pending);
        $scheduled->started_at = $revealed->reveal_ends_at;
        $scheduled->save();

        $running = Round::factory()->forGame($game)->atSequence(3, 3)->running()->withDecoys()->create();

        foreach (range(1, $game->frames_per_round) as $tierIndex) {
            RoundTier::factory()->for($running)->atTier($tierIndex, settings: $game->settings_snapshot)->create();
        }

        RoundChoiceSet::factory()->forRound($running)->create();
        $guestChoices = RoundPlayer::factory()->forRound($running, $guest)->withChoices(Locale::English)->create();

        return new WireScene($room, $game, $host, $guest, $revealed, $scheduled, $running, $guestChoices);
    }

    /**
     * Un événement par classe de la liste close, dans l'ordre du § 11.3.
     *
     * Les événements qui peuvent partir au lobby sont construits hors partie
     * (`gameRef` nul), sauf `seat.updated`, construit en partie.
     *
     * @return array<class-string<RoomBroadcast|SeatBroadcast>, RoomBroadcast|SeatBroadcast>
     */
    public static function events(WireScene $scene): array
    {
        $game = $scene->game;
        $room = $scene->room;
        $now = Date::now()->toImmutable();
        $revealed = $scene->revealed;
        $scheduled = $scene->scheduled;
        $running = $scene->running;
        // `RoomSettingsState` par son producteur (spec 50 § 2.6), tel que
        // `BroadcastLobbyState` le relit au moment d'émettre.
        $settingsState = RoomSettingsPresenter::state($room, $now);
        $choices = app(ChoicesPresenter::class)->forSeat($scene->guestChoices)
            ?? throw new LogicException('Le QCM du second siège devrait être composé.');

        return [
            SeatJoined::class => new SeatJoined($room, null, ['seat' => self::lobbySeatView($room, $scene->guest)]),
            SeatUpdated::class => new SeatUpdated($room, $game, ['seat' => self::seatViews($game, $room)[1]]),
            HostChanged::class => new HostChanged($room, null, [
                'hostPublicId' => $scene->host->public_id,
                'previousHostPublicId' => $scene->guest->public_id,
            ]),
            SettingsChanged::class => new SettingsChanged($room, null, $settingsState),
            RoomReplayed::class => new RoomReplayed($room, null, $settingsState),
            GameLaunched::class => new GameLaunched($room, $game, [
                'mode' => $game->mode->value,
                'roundsCount' => $game->rounds_count,
                'framesPerRound' => $game->frames_per_round,
                'inputDifficulty' => $game->input_difficulty->value,
                'revealDurationMs' => $game->settings_snapshot->revealDuration * 1000,
                'speedBonus' => $game->settings_snapshot->speedBonus,
                'seats' => self::seatViews($game, $room),
            ]),
            RoomArchived::class => new RoomArchived($room, null, []),
            RoundScheduled::class => new RoundScheduled($room, $game, [
                'round' => self::timeline($game, $scheduled),
                'image' => self::image($game, $scheduled, 1),
            ]),
            TierOpened::class => new TierOpened($room, $game, [
                'sequenceIndex' => $running->sequence_index,
                'roundNumber' => (int) $running->round_number,
                'tierIndex' => 1,
                'opensAt' => WireTime::iso(self::startedAt($running)),
                'next' => self::image($game, $running, 2),
            ]),
            PlayerLocked::class => new PlayerLocked($room, $game, [
                'sequenceIndex' => $revealed->sequence_index,
                'publicId' => $scene->host->public_id,
                'lockRank' => 1,
            ]),
            RoundClosed::class => new RoundClosed($room, $game, [
                'sequenceIndex' => $revealed->sequence_index,
                'roundNumber' => (int) $revealed->round_number,
                'endedAt' => WireTime::iso(self::endedAt($revealed)),
                'revealStartsAt' => WireTime::iso(self::endedAt($revealed)->addMilliseconds($game->tier_grace_ms)),
                'revealEndsAt' => WireTime::iso($revealed->reveal_ends_at ?? throw new LogicException('Manche révélée sans fin de révélation.')),
            ]),
            RoundRevealed::class => new RoundRevealed($room, $game, [
                'sequenceIndex' => $revealed->sequence_index,
                'roundNumber' => (int) $revealed->round_number,
                'revealEndsAt' => WireTime::iso($revealed->reveal_ends_at),
                'movie' => self::revealMovie(Movie::query()->findOrFail($revealed->movie_id)),
                'images' => array_map(
                    static fn (int $tierIndex): array => self::image($game, $revealed, $tierIndex),
                    range(1, $game->frames_per_round),
                ),
                'finders' => Scoreboard::roundFinders($revealed),
                'leaderboard' => Scoreboard::leaderboard($game, $revealed),
            ]),
            RoundCancelled::class => new RoundCancelled($room, $game, [
                'sequenceIndex' => $scheduled->sequence_index,
                'roundNumber' => (int) $scheduled->round_number,
            ]),
            GamePaused::class => new GamePaused($room, $game, [
                'pausedAt' => WireTime::iso($now),
                'interruptsAt' => WireTime::iso($now->addMilliseconds(EngineConstants::pauseTimeoutMs())),
            ]),
            GameResumed::class => new GameResumed($room, $game, ['resumedAt' => WireTime::iso($now)]),
            GameEnded::class => new GameEnded($room, $game, ['podium' => self::podium($game, $room, $revealed)]),
            SeatChoicesOffered::class => new SeatChoicesOffered($scene->guest, $game, [
                'sequenceIndex' => $running->sequence_index,
                ...$choices->toArray(),
            ]),
            SeatSuperseded::class => new SeatSuperseded($scene->guest, null, []),
            SeatKicked::class => new SeatKicked($scene->guest, null, []),
        ];
    }

    /**
     * `SeatView` d'un siège au lobby, par son producteur
     * ({@see SeatViewPresenter::lobby()}) : identité courante (C5) et état de
     * siège, relus en base.
     *
     * @return array<string, mixed>
     */
    public static function lobbySeatView(Room $room, Player $seat): array
    {
        $room = Room::query()->findOrFail($room->id);

        return SeatViewPresenter::lobby(Player::query()->findOrFail($seat->id), $room->host_player_id);
    }

    /**
     * `SeatView` des sièges d'une partie, par `game_player.id` croissant, par
     * leur producteur ({@see SeatViewPresenter::gameSeats()}) : identité
     * GELÉE (C5) et état de siège.
     *
     * @return list<array<string, mixed>>
     */
    public static function seatViews(Game $game, Room $room): array
    {
        $room = Room::query()->findOrFail($room->id);

        return SeatViewPresenter::gameSeats($game, $room->host_player_id);
    }

    /**
     * `RoundTimeline` (60 § 11.5).
     *
     * @return array<string, mixed>
     */
    public static function timeline(Game $game, Round $round): array
    {
        return [
            'sequenceIndex' => $round->sequence_index,
            'roundNumber' => (int) $round->round_number,
            'roundsCount' => $game->rounds_count,
            'startsAt' => WireTime::iso(self::startedAt($round)),
            'durationMs' => $round->duration_ms,
            'tiers' => $round->tiers()->orderBy('tier_index')->get()
                ->map(static fn (RoundTier $tier): array => TierWindow::fromRoundTier($tier)->toArray())
                ->all(),
            'choicesAtTierIndex' => $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round),
        ];
    }

    /**
     * `TierImageRef` : l'URL est composée à la forme de `ServeUrl` (L60-8) —
     * jeton aléatoire, expiration et signature —, `fetchNotBefore` = `Tᵢ −
     * preload_lead_ms`.
     *
     * @return array{tierIndex: int, url: string, fetchNotBefore: string}
     */
    public static function image(Game $game, Round $round, int $tierIndex): array
    {
        $tier = $round->tiers()->where('tier_index', $tierIndex)->firstOrFail();
        $opensAt = self::startedAt($round)->addMilliseconds($tier->starts_at_offset_ms);

        return [
            'tierIndex' => $tierIndex,
            'url' => '/f/'.bin2hex(random_bytes(16)).'?expires=1790172346&signature='.bin2hex(random_bytes(32)),
            'fetchNotBefore' => WireTime::iso($opensAt->subMilliseconds($game->preload_lead_ms)),
        ];
    }

    /**
     * `RevealMovie` à la forme de son type client, en attendant
     * `RevealMovieBuilder` (L60-6) : le titre original pour chaque locale.
     *
     * @return array<string, mixed>
     */
    public static function revealMovie(Movie $movie): array
    {
        $titles = [];

        foreach (Locale::cases() as $locale) {
            $titles[$locale->value] = ['text' => $movie->title_original, 'lang' => $movie->original_language];
        }

        return [
            'titles' => $titles,
            'originalTitle' => $movie->title_original,
            'originalTitleLatin' => $movie->title_original_latin,
            'originalLanguage' => $movie->original_language,
            'year' => $movie->release_year,
        ];
    }

    /**
     * `Podium` à la forme de son type client (`types/scoring.ts`), en attendant
     * `Scoreboard::podium()` (L80-5).
     *
     * @return array<string, mixed>
     */
    public static function podium(Game $game, Room $room, Round $revealed): array
    {
        $standings = [];

        foreach (self::seatViews($game, $room) as $rank => $seat) {
            $standings[] = [
                'publicId' => $seat['publicId'],
                'nickname' => $seat['nickname'],
                'masked' => $seat['masked'],
                'avatar' => $seat['avatar'],
                'status' => GamePlayerStatus::Playing->value,
                'firstRoundNumber' => $seat['firstRoundNumber'],
                'rank' => $rank + 1,
                'rankShared' => false,
                'finalScore' => 0,
                'correctAnswers' => 0,
                'roundsPlayed' => 1,
                'totalAnswerTimeMs' => 0,
            ];
        }

        return [
            'gameStatus' => 'completed',
            'mode' => $game->mode->value,
            'roundsCompleted' => 1,
            'roundsCount' => $game->rounds_count,
            'framesPerRound' => $game->frames_per_round,
            'scoreless' => false,
            'endedAt' => WireTime::iso(Date::now()->toImmutable()),
            'standings' => $standings,
            'recap' => [[
                'roundNumber' => (int) $revealed->round_number,
                'outcome' => 'completed',
                'titles' => self::revealMovie(Movie::query()->findOrFail($revealed->movie_id)),
                'foundCount' => $revealed->found_count,
                'finders' => Scoreboard::roundFinders($revealed),
            ]],
            'highlights' => ['bestAnswer' => null, 'fastestFind' => null, 'unfoundRoundNumbers' => []],
        ];
    }

    private static function startedAt(Round $round): CarbonImmutable
    {
        return $round->started_at ?? throw new LogicException('Manche sans origine de temps.');
    }

    private static function endedAt(Round $round): CarbonImmutable
    {
        return $round->ended_at ?? throw new LogicException('Manche sans instant de clôture.');
    }
}
