<?php

namespace Tests\Support\Game;

use App\Actions\Game\CloseRound;
use App\Actions\Game\EndReveal;
use App\Actions\Game\MaterializeDraw;
use App\Actions\Game\OpenTier;
use App\Actions\Game\RevealRound;
use App\Actions\Game\ScheduleRound;
use App\Enums\FrameLevel;
use App\Enums\GamePlayerStatus;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\DrawnRound;
use App\Support\Draw\DrawnTier;
use App\Support\Draw\DrawResult;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\Draw\PoolFixtures;

/**
 * Fixtures du moteur (spec 60, lot L60-5) : une partie, un tirage CONSTRUIT
 * À LA MAIN sur de vrais films et de vraies variantes, et sa matérialisation
 * par l'action réelle (`MaterializeDraw`).
 *
 * « `MaterializeDraw` et `ScheduleRound` se testent sur `Game::factory()` et
 * un `DrawResult` construit à la main » (60 § 23, L60-5) : le tirage de 30
 * (`GameDrawer`) n'entre pas ici, ses propriétés sont prouvées chez lui. Les
 * films viennent de {@see PoolFixtures::movie()} : une variante publiée par
 * niveau, fichier réel sur le disque `frames` simulé — chaque test appelle
 * {@see PoolFixtures::fakeFramesDisk()} d'abord.
 *
 * Les étapes d'une manche (lot L60-6) s'exécutent par les actions réelles,
 * horloge figée À L'INSTANT DE L'ÉTAPE, comme un job de frontière à l'heure :
 * `serverNow` des diffusions et instant de frappe compris.
 */
final class EngineFixtures
{
    /**
     * Une partie en cours sur ces réglages (défauts du site sinon) :
     * multijoueur dans un salon, ou solo sans salon.
     */
    public static function game(?RoomSettings $settings = null, bool $solo = false, ?Room $room = null): Game
    {
        $factory = Game::factory()->withSettings($settings ?? RoomSettings::defaults());

        if ($solo) {
            $factory = $factory->solo();
        } elseif ($room instanceof Room) {
            $factory = $factory->forRoom($room);
        }

        return $factory->create();
    }

    /**
     * Des films de vivier dont la banque couvre la répartition nominale du `N`
     * de la partie, une variante publiée par niveau.
     *
     * @return list<Movie>
     */
    public static function movies(Game $game, int $count): array
    {
        return PoolFixtures::movies($count, FrameLevelCoverage::nominal($game->frames_per_round));
    }

    /**
     * Le tirage d'une partie sur ces films, dans leur ordre : position `s` =
     * rang du film, numéro `s` pour les `M` premières manches et nul pour la
     * réserve, palier `i` sur la variante publiée du niveau nominal `i` du
     * film (la plus petite si le film en a plusieurs).
     *
     * @param  list<Movie>  $movies  `M` films au moins ; au-delà, la réserve.
     */
    public static function draw(Game $game, array $movies): DrawResult
    {
        if (count($movies) < $game->rounds_count) {
            throw new LogicException('EngineFixtures : un tirage compte au moins M manches.');
        }

        $rounds = [];

        foreach (array_values($movies) as $position => $movie) {
            $sequenceIndex = $position + 1;
            $tiers = [];

            foreach (FrameLevelCoverage::nominal($game->frames_per_round) as $index => $level) {
                $tiers[] = new DrawnTier($index + 1, self::variant($movie, $level)->id, $level);
            }

            $rounds[] = new DrawnRound(
                $sequenceIndex,
                $sequenceIndex <= $game->rounds_count ? $sequenceIndex : null,
                $movie->id,
                $tiers,
            );
        }

        return new DrawResult($rounds, count($movies), $game->rounds_count);
    }

    /**
     * La partie, matérialisée par l'action réelle sur `M + $reserve` films
     * neufs, dans la transaction qu'ouvrirait le lancement.
     *
     * @return list<Movie> les films, dans l'ordre du tirage
     */
    public static function materialize(Game $game, int $reserve = 0): array
    {
        $movies = self::movies($game, $game->rounds_count + $reserve);

        DB::transaction(static fn () => app(MaterializeDraw::class)->handle($game, self::draw($game, $movies)));

        return $movies;
    }

    /**
     * La manche de la partie à la position `$sequenceIndex`, relue en base.
     */
    public static function round(Game $game, int $sequenceIndex): Round
    {
        return Round::query()
            ->where('game_id', $game->id)
            ->where('sequence_index', $sequenceIndex)
            ->firstOrFail();
    }

    /**
     * Le palier `$tierIndex` d'une manche, relu en base.
     */
    public static function tier(Round $round, int $tierIndex): RoundTier
    {
        return RoundTier::query()
            ->where('round_id', $round->id)
            ->where('tier_index', $tierIndex)
            ->firstOrFail();
    }

    /**
     * Un siège de la partie : le `player` dans le salon de la partie (sans
     * salon en solo), connecté par défaut, et sa ligne `game_player` gelée
     * depuis lui — l'écriture du lancement, ou d'un retardataire admis.
     *
     * @param  array<string, mixed>  $player  Colonnes du siège imposées (état de connexion…).
     */
    public static function seat(
        Game $game,
        array $player = [],
        ?int $firstRoundNumber = null,
        GamePlayerStatus $status = GamePlayerStatus::Playing,
    ): Player {
        $seat = Player::factory()->create(['room_id' => $game->room_id, ...$player]);

        GamePlayer::factory()->for($game)->frozenFrom($seat, $firstRoundNumber)->create(['status' => $status]);

        return $seat;
    }

    /**
     * `Tᵢ` : l'instant théorique d'ouverture du palier, relu en base.
     */
    public static function opensAt(Round $round, int $tierIndex): CarbonImmutable
    {
        $round = $round->fresh() ?? throw new LogicException('EngineFixtures : manche disparue.');
        $startedAt = $round->started_at ?? throw new LogicException('EngineFixtures : manche non programmée.');

        return $startedAt->addMilliseconds(self::tier($round, $tierIndex)->starts_at_offset_ms);
    }

    /**
     * `started_at + D` : la clôture à `D`, relue en base.
     */
    public static function durationEnd(Round $round): CarbonImmutable
    {
        $round = $round->fresh() ?? throw new LogicException('EngineFixtures : manche disparue.');
        $startedAt = $round->started_at ?? throw new LogicException('EngineFixtures : manche non programmée.');

        return $startedAt->addMilliseconds($round->duration_ms);
    }

    /**
     * `ScheduleRound` à `$startsAt`, horloge figée à `$now`.
     */
    public static function schedule(Round $round, CarbonImmutable $startsAt, ?CarbonImmutable $now = null): void
    {
        self::at($now ?? Date::now()->toImmutable(), static fn () => app(ScheduleRound::class)->handle($round, $startsAt));
    }

    /**
     * `OpenTier(i)` à `$now` (son `Tᵢ` par défaut) ; le palier, relu en base.
     */
    public static function openTier(Round $round, int $tierIndex, ?CarbonImmutable $now = null): RoundTier
    {
        $now ??= self::opensAt($round, $tierIndex);

        self::at($now, static fn () => app(OpenTier::class)->handle(self::tier($round, $tierIndex), $now));

        return self::tier($round, $tierIndex);
    }

    /**
     * `CloseRound` à `$endedAt` (`D` par défaut), horloge figée à cet instant.
     */
    public static function close(Round $round, ?CarbonImmutable $endedAt = null): void
    {
        $endedAt ??= self::durationEnd($round);

        self::at($endedAt, static fn () => app(CloseRound::class)->handle($round, $endedAt));
    }

    /**
     * `RevealRound` à `$now` (`ended_at + tier_grace_ms` par défaut).
     */
    public static function reveal(Round $round, ?CarbonImmutable $now = null): void
    {
        if (! $now instanceof CarbonImmutable) {
            $round = $round->fresh() ?? throw new LogicException('EngineFixtures : manche disparue.');
            $endedAt = $round->ended_at ?? throw new LogicException('EngineFixtures : manche non close.');
            $now = $endedAt->addMilliseconds($round->game->tier_grace_ms);
        }

        self::at($now, static fn () => app(RevealRound::class)->handle($round, $now));
    }

    /**
     * `EndReveal` à `$now` (`reveal_ends_at` par défaut).
     */
    public static function endReveal(Round $round, ?CarbonImmutable $now = null): void
    {
        if (! $now instanceof CarbonImmutable) {
            $round = $round->fresh() ?? throw new LogicException('EngineFixtures : manche disparue.');
            $now = $round->reveal_ends_at ?? throw new LogicException('EngineFixtures : manche sans fin de révélation.');
        }

        self::at($now, static fn () => app(EndReveal::class)->handle($round, $now));
    }

    /**
     * Joue une manche programmée à ses instants théoriques : ouverture de ses
     * `N` paliers, clôture à `D`, révélation, fin de révélation.
     */
    public static function play(Round $round): void
    {
        $framesPerRound = $round->game->frames_per_round;

        foreach (range(1, $framesPerRound) as $tierIndex) {
            self::openTier($round, $tierIndex);
        }

        self::close($round);
        self::reveal($round);
        self::endReveal($round);
    }

    /**
     * Exécute `$step` horloge figée à `$now`.
     */
    private static function at(CarbonImmutable $now, callable $step): void
    {
        Date::setTestNow($now);

        $step();
    }

    /**
     * La variante publiée du film à ce niveau (la plus petite s'il y en a
     * plusieurs).
     */
    public static function variant(Movie $movie, FrameLevel $level): Frame
    {
        return Frame::query()
            ->where('movie_id', $movie->id)
            ->where('frame_level', $level->value)
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * Des réglages valides au `N` et au `M` voulus — `M` au plus petit admis
     * par défaut, pour des fixtures légères —, tout le reste au défaut, par
     * {@see RoomSettings::fromInput()}, seul constructeur borné.
     */
    public static function settings(
        int $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
        int $roundsCount = RoomSettingsBounds::MIN_ROUNDS_COUNT,
    ): RoomSettings {
        return RoomSettings::fromInput([
            'framesPerRound' => $framesPerRound,
            'roundsCount' => $roundsCount,
        ]);
    }
}
