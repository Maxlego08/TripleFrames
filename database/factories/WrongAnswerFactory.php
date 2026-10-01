<?php

namespace Database\Factories;

use App\Enums\GuessSource;
use App\Models\Player;
use App\Models\Round;
use App\Models\WrongAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see WrongAnswer} — une réponse fausse comptée
 * (spec 10 § 7.6 bis, D46 du 01/10).
 *
 * @extends Factory<WrongAnswer>
 */
class WrongAnswerFactory extends Factory
{
    /**
     * Première tentative texte, reçue deux secondes après `T₁`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'player_id' => Player::factory(),
            'source' => GuessSource::Text,
            'submitted_text' => 'Xqzv Wkjb',
            'submitted_normalized' => 'xqzv wkjb',
            'attempt_number' => 1,
            'received_at' => now(),
            'answered_at_ms' => 2_000,
        ];
    }

    /**
     * Réponse fausse d'un couple (manche, siège) existant.
     */
    public function forRound(Round $round, Player $player): static
    {
        return $this->state(fn (array $attributes): array => [
            'round_id' => $round->id,
            'player_id' => $player->id,
        ]);
    }

    /**
     * Clic QCM faux : la chaîne cliquée, sans rang de tentative.
     */
    public function viaChoice(string $choice = 'Les Feux du port'): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => GuessSource::Choice,
            'submitted_text' => $choice,
            'submitted_normalized' => mb_strtolower($choice),
            'attempt_number' => null,
        ]);
    }
}
