<?php

namespace Tests\Support\Game;

use App\Actions\Game\MaterializeDraw;
use App\Enums\FrameLevel;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\DrawnRound;
use App\Support\Draw\DrawnTier;
use App\Support\Draw\DrawResult;
use App\ValueObjects\Catalog\FrameLevelCoverage;
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
