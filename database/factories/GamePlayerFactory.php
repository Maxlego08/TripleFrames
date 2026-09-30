<?php

namespace Database\Factories;

use App\Enums\AvatarKind;
use App\Enums\GamePlayerStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see GamePlayer} — la participation d'un siège à une
 * partie, et ses agrégats **figés** (§ 7.3).
 *
 * Les trois colonnes `display_*` sont le **gel** du siège : la fabrique les relit
 * donc sur le `player` qu'elle vient de rattacher plutôt que d'en inventer
 * d'autres. Un podium dont le pseudo gelé n'a jamais été celui du siège ne prouve
 * rien, et c'est précisément la propriété que ces colonnes existent pour porter.
 * `display_avatar_preset` n'est **jamais** un chemin de copie provider, pour qu'un
 * masquage postérieur fasse redescendre la chaîne de repli.
 *
 * Les cinq colonnes d'agrégat restent **nulles** par défaut : elles sont écrites
 * uniquement à `game.ended_at`, ce qui fait du gel un événement vérifiable plutôt
 * qu'un état qu'on oublie de déclencher. {@see self::finished()} est le seul
 * chemin qui les remplit.
 *
 * **Famille d'horodatage** : `timestamps()` de précision 0, contrairement aux six
 * autres tables de faits de partie — le modèle ne porte volontairement pas de
 * `#[DateFormat]` sub-seconde, et la fabrique n'écrit aucune colonne datée métier.
 *
 * @extends Factory<GamePlayer>
 */
class GamePlayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'player_id' => Player::factory(),
            'display_nickname' => static fn (array $attributes): ?string => self::seat($attributes)?->nickname,
            'display_avatar_kind' => static fn (array $attributes): ?AvatarKind => self::seat($attributes)?->avatar_kind,
            'display_avatar_preset' => static fn (array $attributes): ?string => self::seat($attributes)?->avatar_preset,
            'first_round_number' => null,
            'status' => GamePlayerStatus::Playing,
            'rounds_played' => null,
            'correct_answers' => null,
            'final_score' => null,
            'total_answer_time_ms' => null,
            'final_rank' => null,
        ];
    }

    /**
     * Gèle explicitement l'affichage depuis un siège donné — l'écriture du
     * lancement, et celle d'un retardataire admis en cours de partie.
     */
    public function frozenFrom(Player $player, ?int $firstRoundNumber = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'player_id' => $player->id,
            'display_nickname' => $player->nickname,
            'display_avatar_kind' => $player->avatar_kind,
            'display_avatar_preset' => $player->avatar_preset,
            'first_round_number' => $firstRoundNumber,
        ]);
    }

    /**
     * Retardataire : entré à la manche `first_round_number`, jamais à la manche 1.
     * `rounds_played` comptera ses manches à lui, jamais `M`.
     */
    public function lateJoiner(int $firstRoundNumber): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_round_number' => $firstRoundNumber,
        ]);
    }

    /**
     * Issue figée « parti » — la présence vive, elle, vit sur
     * `player.connection_state` et n'est jamais lue ici.
     */
    public function left(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GamePlayerStatus::Left,
        ]);
    }

    /**
     * Issue figée « expulsé par l'hôte ».
     */
    public function kicked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GamePlayerStatus::Kicked,
        ]);
    }

    /**
     * Les cinq agrégats, écrits ensemble comme à `game.ended_at`.
     *
     * `final_rank` reste **nul en solo**, où l'historique affiche « — », ou si
     * `$roundsPlayed` vaut 0 : passer `null` est donc un cas normal et non un
     * oubli. Il n'est pas borné à 255 : la colonne est un `unsignedSmallInteger`
     * (E10-04), et `game_player` n'a pas de plafond de sièges classés.
     */
    public function finished(
        int $roundsPlayed = 10,
        int $correctAnswers = 6,
        int $finalScore = 1_400,
        int $totalAnswerTimeMs = 42_000,
        ?int $finalRank = 1,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'rounds_played' => $roundsPlayed,
            'correct_answers' => $correctAnswers,
            'final_score' => $finalScore,
            'total_answer_time_ms' => $totalAnswerTimeMs,
            'final_rank' => $finalRank,
        ]);
    }

    /**
     * Le siège dont l'affichage est gelé, relu depuis les attributs déjà résolus.
     *
     * `expandAttributes()` remplace une `Factory` par la clé du modèle qu'elle
     * vient de créer AVANT d'évaluer les fermetures qui la suivent : `player_id`
     * est donc déjà un entier ici.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function seat(array $attributes): ?Player
    {
        $playerId = $attributes['player_id'] ?? null;

        if (! is_int($playerId) && ! is_string($playerId)) {
            return null;
        }

        return Player::query()->find($playerId);
    }
}
