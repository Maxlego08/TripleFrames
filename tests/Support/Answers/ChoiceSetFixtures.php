<?php

namespace Tests\Support\Answers;

use App\Actions\Game\ComposeChoiceSets;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Answers\ChoicesPresenter;
use App\ValueObjects\Answers\ChoicesPayload;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\RoundPlayerFactory;
use LogicException;
use Tests\Support\Draw\PoolFixtures;

/**
 * Fixtures de la composition du QCM (spec 70 § 10, lot L70-8) — une vraie
 * manche matérialisée : ses `N` paliers aux décalages de l'instantané, son
 * origine de temps posée pour que `$at` soit EXACTEMENT l'instant théorique
 * d'ouverture du QCM, que `ComposeChoiceSets` exige.
 *
 * Les films viennent de {@see PoolFixtures} : variantes publiées, projection
 * calculée par le projecteur, titres inventés — aucune image réelle.
 */
final class ChoiceSetFixtures
{
    /**
     * Graines fixes, au format de `game.draw_seed` : un tirage à graine
     * aléatoire ferait de chaque assertion d'ordre un pile ou face.
     *
     * @return list<string>
     */
    public static function seeds(): array
    {
        return array_map(
            static fn (int $index): string => hash('sha256', "choice-set-seed-{$index}"),
            range(1, 4),
        );
    }

    /**
     * Réglages valides aux seuls champs utiles : difficulté de saisie, `N`,
     * thèmes et non-répétition ; tout le reste au défaut, par
     * {@see RoomSettings::fromInput()}.
     *
     * @param  list<int>  $themeIds
     */
    public static function settings(
        InputDifficulty $difficulty = RoomSettingsBounds::DEFAULT_INPUT_DIFFICULTY,
        array $themeIds = [],
        int $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
        bool $noRepeatMovies = RoomSettingsBounds::DEFAULT_NO_REPEAT_MOVIES,
    ): RoomSettings {
        return RoomSettings::fromInput([
            'inputDifficulty' => $difficulty,
            'themeIds' => $themeIds,
            'framesPerRound' => $framesPerRound,
            'noRepeatMovies' => $noRepeatMovies,
        ]);
    }

    /**
     * Une partie à graine fixe : multijoueur dans `$room`, solo sans salon.
     */
    public static function game(?Room $room, RoomSettings $settings, string $seed): Game
    {
        $factory = $room instanceof Room ? Game::factory()->forRoom($room) : Game::factory()->solo();

        return $factory->withSettings($settings)->state(['draw_seed' => $seed])->create();
    }

    /**
     * Décalage, depuis `round.started_at`, de l'ouverture du palier
     * `choicesOpenTierIndex(N)`, lu dans l'instantané de la partie.
     */
    public static function choicesOffsetMs(Game $game): int
    {
        $tierIndex = $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round)
            ?? throw new LogicException('ChoiceSetFixtures : aucun QCM en Expert.');

        return $game->settings_snapshot->tierStartOffsetMs($tierIndex);
    }

    /**
     * La manche de `$target`, ses `N` paliers matérialisés, démarrée pour que
     * `$at` soit l'instant théorique d'ouverture de son QCM (`T₁` en Facile,
     * `T_N` en Normal). En Expert, démarrée à `$at`.
     */
    public static function round(
        Game $game,
        Movie $target,
        CarbonImmutable $at,
        RoundStatus $status = RoundStatus::Running,
    ): Round {
        $offsetMs = $game->input_difficulty->hasChoices() ? self::choicesOffsetMs($game) : 0;
        $round = PoolFixtures::round($game, $target, $at->subMilliseconds($offsetMs), $status);

        for ($tierIndex = 1; $tierIndex <= $game->frames_per_round; $tierIndex++) {
            RoundTier::factory()
                ->for($round)
                ->atTier($tierIndex, settings: $game->settings_snapshot)
                ->create();
        }

        return $round;
    }

    /**
     * Un siège de la partie, participant de la manche, dans la langue voulue ;
     * `$state` applique un état de fabrique à la ligne `round_player`
     * (`textExhausted()`, `locked()`…).
     *
     * @param  (Closure(RoundPlayerFactory): RoundPlayerFactory)|null  $state
     */
    public static function seat(
        Round $round,
        Locale $locale = Locale::English,
        ?Closure $state = null,
        ?string $publicId = null,
    ): RoundPlayer {
        $game = Game::query()->findOrFail($round->game_id);
        $attributes = ['room_id' => $game->room_id, 'locale' => $locale];

        if ($publicId !== null) {
            $attributes['public_id'] = $publicId;
        }

        $player = Player::factory()->state($attributes)->create();
        $factory = RoundPlayer::factory()->forRound($round, $player);

        if ($state instanceof Closure) {
            $factory = $state($factory);
        }

        return $factory->create();
    }

    /**
     * La composition, comme au premier appel d'un job : instance neuve de
     * l'action et de `PoolQuery` (liaison `scoped`), manche relue en base.
     */
    public static function compose(Round $round, CarbonImmutable $at): bool
    {
        app()->forgetScopedInstances();

        return app(ComposeChoiceSets::class)->handle(Round::query()->findOrFail($round->id), $at);
    }

    /**
     * La charge du siège, relue comme à une resynchronisation : ligne
     * `round_player` relue en base, présentateur neuf.
     */
    public static function present(RoundPlayer $seat): ?ChoicesPayload
    {
        return app(ChoicesPresenter::class)->forSeat(RoundPlayer::query()->findOrFail($seat->id));
    }
}
