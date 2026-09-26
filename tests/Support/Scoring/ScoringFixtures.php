<?php

namespace Tests\Support\Scoring;

use App\Enums\GamePlayerStatus;
use App\Enums\GuessSource;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Settings\RoomSettings;
use App\ValueObjects\Scoring\PlayerTally;

/**
 * Fixtures du côté lecture du score (spec 80 § 8 et § 9, lot L80-3) : une
 * partie figée, ses sièges gelés, des manches aux `N` paliers matérialisés
 * comme au lancement, et des bonnes réponses NOTÉES par la règle elle-même
 * (`GuessFactory::scoredAt()`, donc `ScoreCalculator::forGuess()`), jamais
 * recopiées à la main.
 *
 * Une bonne réponse suit la transaction de verrouillage : `lock_rank` vaut le
 * `found_count` de la manche augmenté de un, et le siège passe `locked`
 * (invariant « `locked` si et seulement si une ligne `guess` existe »).
 */
final class ScoringFixtures
{
    /**
     * Une partie figée sur ces réglages (défauts du site sinon) : multijoueur
     * dans un salon, ou solo sans salon.
     *
     * @param  array<string, mixed>  $state  Colonnes de `game` imposées (ex. `draw_seed`).
     */
    public static function game(?RoomSettings $settings = null, array $state = [], bool $solo = false): Game
    {
        $factory = Game::factory()->withSettings($settings ?? RoomSettings::defaults());

        if ($solo) {
            $factory = $factory->solo();
        }

        return $factory->create($state);
    }

    /**
     * Un siège de la partie : le `player` dans le salon de la partie (sans salon
     * en solo), et sa ligne `game_player` gelée depuis lui, à l'issue voulue.
     */
    public static function seat(
        Game $game,
        GamePlayerStatus $status = GamePlayerStatus::Playing,
        ?int $firstRoundNumber = null,
    ): Player {
        $player = Player::factory();

        $player = match ($status) {
            GamePlayerStatus::Playing => $player,
            GamePlayerStatus::Left => $player->left(),
            GamePlayerStatus::Kicked => $player->kicked(),
        };

        $seat = $player->create(['room_id' => $game->room_id]);

        GamePlayer::factory()
            ->for($game)
            ->frozenFrom($seat, $firstRoundNumber)
            ->create(['status' => $status]);

        return $seat;
    }

    /**
     * Une manche de la partie, au statut voulu, ses `N` paliers matérialisés
     * depuis l'instantané des réglages de la partie.
     */
    public static function round(
        Game $game,
        int $roundNumber,
        RoundStatus $status,
        ?int $sequenceIndex = null,
        ?Movie $movie = null,
    ): Round {
        $settings = $game->settings_snapshot;
        $factory = Round::factory()
            ->forGame($game)
            ->atSequence($sequenceIndex ?? $roundNumber, $roundNumber)
            ->state(['duration_ms' => $settings->roundDuration() * 1000]);

        if ($movie instanceof Movie) {
            $factory = $factory->forMovie($movie);
        }

        $round = match ($status) {
            RoundStatus::Pending => $factory->create(),
            RoundStatus::Running => $factory->running()->create(),
            RoundStatus::Revealing => $factory->revealing()->create(),
            RoundStatus::Completed => $factory->completed(0)->create(),
            RoundStatus::Cancelled => $factory->cancelled()->create(),
        };

        foreach (range(1, $settings->framesPerRound) as $tierIndex) {
            RoundTier::factory()->for($round)->atTier($tierIndex, settings: $settings)->create();
        }

        return $round;
    }

    /**
     * Le siège a pris part à la manche sans trouver.
     */
    public static function played(Round $round, Player $seat): RoundPlayer
    {
        return RoundPlayer::factory()->forRound($round, $seat)->create();
    }

    /**
     * Le siège a trouvé, reçu à `$answeredAtMs` de `round.started_at` : sa
     * participation passe `locked`, et la bonne réponse est notée comme la
     * saisie la note, au rang d'arrivée suivant.
     *
     * @param  array<string, mixed>  $state  Colonnes de `guess` imposées hors du score (ex. `match_kind`).
     */
    public static function find(
        Round $round,
        Player $seat,
        int $answeredAtMs,
        GuessSource $source = GuessSource::Text,
        array $state = [],
    ): Guess {
        RoundPlayer::factory()->forRound($round, $seat)->locked()->create();

        $round->refresh();
        $round->found_count++;
        $round->save();

        return Guess::factory()
            ->forRound($round, $seat)
            ->withRank($round->found_count)
            ->scoredAt($answeredAtMs, $source)
            ->create($state);
    }

    /**
     * Passe une manche existante à un autre statut, avec les instants que la
     * transition poserait.
     */
    public static function moveTo(Round $round, RoundStatus $status): Round
    {
        $round->status = $status;
        $round->ended_at ??= now();

        if ($status === RoundStatus::Revealing || $status === RoundStatus::Completed) {
            $round->reveal_ends_at ??= now();
        }

        if ($status === RoundStatus::Cancelled) {
            $round->cancelled_at = now();
        }

        $round->save();

        return $round;
    }

    /**
     * Des totaux posés à la main, pour la fonction pure `Ranking::rank()` :
     * `correctAnswers` est la somme des trouvailles, jamais écrite à part.
     *
     * @param  list<int>  $finds  Trouvailles aux paliers `1..N`, dans l'ordre.
     */
    public static function tally(
        int $gamePlayerId,
        int $score,
        array $finds,
        int $totalAnswerTimeMs,
        int $roundsPlayed = 10,
        GamePlayerStatus $status = GamePlayerStatus::Playing,
        ?int $firstRoundNumber = null,
    ): PlayerTally {
        return new PlayerTally(
            gamePlayerId: $gamePlayerId,
            publicId: sprintf('seat%08d', $gamePlayerId),
            status: $status,
            firstRoundNumber: $firstRoundNumber,
            roundsPlayed: $roundsPlayed,
            correctAnswers: array_sum($finds),
            score: $score,
            totalAnswerTimeMs: $totalAnswerTimeMs,
            findsByTier: array_combine(range(1, count($finds)), $finds),
        );
    }
}
