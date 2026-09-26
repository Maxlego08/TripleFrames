<?php

namespace Tests\Support\Draw;

use App\Enums\FrameLevel;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\MovieTheme;
use App\Models\Room;
use App\Models\Round;
use App\Models\Theme;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Frames\FrameStoragePrefix;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\MovieFactory;
use Illuminate\Support\Facades\Storage;

/**
 * Fixtures du vivier (spec 30 § 3) — de vrais films, de vraies variantes, une
 * vraie projection.
 *
 * **Aucun compteur écrit à la main dans `movie_projection`** : chaque film
 * reçoit des `frame` publiées et servables, et le projecteur synchrone calcule
 * `levels_mask` / `levels_count` sur l'état réel de la base. Un vivier prouvé sur
 * une projection inventée prouverait un comportement sur un état que le code ne
 * produit jamais (même règle que `DashboardTest`).
 *
 * Les octets des variantes partent sur un disque `frames` SIMULÉ : chaque test
 * appelle {@see self::fakeFramesDisk()} avant toute fixture, sans quoi la
 * fabrique de `frame` lève plutôt que de polluer la racine réelle. Aucune image
 * réelle : l'aplat WebP de la fabrique, généré par Imagick.
 */
final class PoolFixtures
{
    /**
     * Le disque `frames` simulé — obligatoire avant toute fixture de `frame`.
     */
    public static function fakeFramesDisk(): void
    {
        Storage::fake(FrameStoragePrefix::DISK);
    }

    /**
     * Un film publié et `clear` dont la banque couvre EXACTEMENT `$levels`, une
     * variante publiée par niveau.
     *
     * `$levels` vaut par défaut la répartition nominale du `N` par défaut, lue
     * dans {@see FrameLevelCoverage} — jamais recopiée ici. `$state` applique un
     * état de fabrique APRÈS `playable()` (suspendu, retiré, bloqué…), pour
     * éprouver le vivier sur un film par ailleurs complet. `$titles` (locale de
     * catalogue => titre) remplace les titres par défaut de `playable()` — `en`
     * et `fr` — et fixe donc le profil de titre du film (leurres, spec 70
     * § 10.3) : `['fr' => …]` seul, ou une locale non activée seule pour un
     * masque nul.
     *
     * @param  list<FrameLevel>|null  $levels
     * @param  (Closure(MovieFactory): MovieFactory)|null  $state
     * @param  array<string, string>  $titles
     */
    public static function movie(
        ?array $levels = null,
        ?MovieGroup $group = null,
        ?Closure $state = null,
        array $titles = [],
    ): Movie {
        $levels ??= FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);

        $factory = Movie::factory()->playable(titles: $titles);

        if ($group instanceof MovieGroup) {
            $factory = $factory->inGroup($group);
        }

        if ($state instanceof Closure) {
            $factory = $state($factory);
        }

        if ($levels !== []) {
            $factory = $factory->has(
                Frame::factory()
                    ->count(count($levels))
                    ->published()
                    ->sequence(...array_map(
                        static fn (FrameLevel $level): array => ['frame_level' => $level],
                        $levels,
                    )),
                'frames',
            );
        }

        $movie = $factory->create();

        // La projection est recalculée sur l'état FINAL de la base : les
        // `afterCreating` des variantes (preuve de revue) jouent après leur
        // insertion.
        MovieFactory::recomputeProjection($movie);

        return $movie->refresh();
    }

    /**
     * `$count` films de vivier distincts, chacun une œuvre à lui seul.
     *
     * @param  list<FrameLevel>|null  $levels
     * @return list<Movie>
     */
    public static function movies(int $count, ?array $levels = null): array
    {
        $movies = [];

        for ($index = 0; $index < $count; $index++) {
            $movies[] = self::movie($levels);
        }

        return $movies;
    }

    /**
     * Réglages valides aux seuls champs du vivier : thèmes, `N`, `M` et
     * non-répétition. Tout le reste reste au défaut, par
     * {@see RoomSettings::fromInput()} — seul constructeur borné.
     *
     * @param  list<int>  $themeIds
     */
    public static function settings(
        array $themeIds = [],
        int $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
        bool $noRepeatMovies = RoomSettingsBounds::DEFAULT_NO_REPEAT_MOVIES,
        int $roundsCount = RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
    ): RoomSettings {
        return RoomSettings::fromInput([
            'themeIds' => $themeIds,
            'framesPerRound' => $framesPerRound,
            'noRepeatMovies' => $noRepeatMovies,
            'roundsCount' => $roundsCount,
        ]);
    }

    /**
     * Une partie multijoueur du salon, sur l'instantané `$settings` (défauts du
     * site sinon).
     */
    public static function game(Room $room, ?RoomSettings $settings = null): Game
    {
        return Game::factory()
            ->forRoom($room)
            ->withSettings($settings ?? RoomSettings::defaults())
            ->create();
    }

    /**
     * Une manche de la partie sur `$movie`, démarrée (ou programmée) à
     * `$startedAt` — nul pour une manche de réserve jamais démarrée.
     *
     * `sequence_index` suit les manches déjà écrites de la partie
     * (`round_game_sequence_uq`) ; `round_number` vaut `sequence_index`, sauf pour
     * une manche de réserve (`$reserve`), qui n'en a pas.
     */
    public static function round(
        Game $game,
        Movie $movie,
        ?CarbonImmutable $startedAt,
        RoundStatus $status = RoundStatus::Completed,
        bool $reserve = false,
    ): Round {
        $sequenceIndex = Round::query()->where('game_id', $game->id)->count() + 1;

        return Round::factory()
            ->forGame($game)
            ->forMovie($movie)
            ->atSequence($sequenceIndex)
            ->state([
                'round_number' => $reserve ? null : $sequenceIndex,
                'status' => $status,
                'started_at' => $startedAt,
                'cancel_reason' => $status === RoundStatus::Cancelled ? RoundIncidentReason::NoVariantAvailable : null,
                'cancelled_at' => $status === RoundStatus::Cancelled ? $startedAt : null,
            ])
            ->create();
    }

    /**
     * Appartenance ACTIVE du film au thème, telle que l'écrirait une règle qui
     * matche (`is_auto`, sans exception manuelle).
     */
    public static function member(Movie $movie, Theme $theme): MovieTheme
    {
        return MovieTheme::factory()->for($movie)->for($theme)->auto()->create();
    }
}
