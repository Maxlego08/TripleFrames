<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see RoundPlayer} — **qui était là, et n'a pas trouvé**
 * (§ 7.6).
 *
 * C'est la ligne qui manque au journal si l'on se contente de `guess` et
 * `round_tier` : `guess` n'a que des gagnants, donc sans elle on ne distingue pas
 * « Bob était présent et a échoué » de « Bob n'était pas dans la manche ». Une
 * fabrique de test qui ne créerait des `guess` que pour les gagnants produirait
 * exactement ce trou.
 *
 * **Les tentatives fausses sont comptées, jamais stockées** : `wrong_attempts` et
 * rien d'autre, ni texte ni ligne. Aucun état de cette fabrique n'écrit une
 * tentative.
 *
 * Deux invariants que la fabrique ne peut pas tenir seule, et qui appartiennent à
 * l'appelant :
 *
 * - `input_state = 'locked'` **si et seulement si** une ligne `guess` existe pour
 *   le même couple. {@see self::locked()} pose l'état ; c'est au test d'écrire la
 *   ligne `guess` correspondante ({@see GuessFactory}).
 * - `revealed` et `skipped` sont **inatteignables hors `game.mode = 'solo'`**.
 *   Leurs deux états le rappellent, mais aucune colonne de cette table ne porte le
 *   mode.
 *
 * @extends Factory<RoundPlayer>
 */
class RoundPlayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Un participant dont la saisie est encore ouverte — le seul état qui bloque
     * la fin anticipée (§ 7.7).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'player_id' => Player::factory(),
            'input_state' => RoundPlayerInputState::Open,
            'input_closed_at' => null,
            'wrong_attempts' => 0,
            'choices_locale' => null,
            'choices_composed_at' => null,
        ];
    }

    /**
     * Participant rattaché à une manche et à un siège existants.
     */
    public function forRound(Round $round, Player $player): static
    {
        return $this->state(fn (array $attributes): array => [
            'round_id' => $round->id,
            'player_id' => $player->id,
        ]);
    }

    /**
     * Joueur VERROUILLÉ — score figé, statut « a trouvé » visible des autres sans
     * révéler le titre. À apparier avec une ligne {@see Guess} sur le
     * même couple, faute de quoi l'invariant du § 7.6 est faux.
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_state' => RoundPlayerInputState::Locked,
            'input_closed_at' => now(),
        ]);
    }

    /**
     * QCM à essai unique et définitif : le clic faux ferme la saisie, et **en
     * Normal il ferme AUSSI le texte libre** — c'est pourquoi l'instant doit être
     * opposable. Cet état n'est jamais diffusé pour un autre joueur : il
     * révélerait une mauvaise réponse.
     */
    public function qcmWrong(): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_state' => RoundPlayerInputState::QcmWrong,
            'input_closed_at' => now(),
            'wrong_attempts' => 1,
        ]);
    }

    /**
     * Budget de tentatives en texte libre épuisé — `attemptsPerRound` du salon.
     */
    public function attemptsExhausted(int $wrongAttempts = 15): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_state' => RoundPlayerInputState::AttemptsExhausted,
            'input_closed_at' => now(),
            'wrong_attempts' => $wrongAttempts,
        ]);
    }

    /**
     * « Voir la réponse » — **solo uniquement** ({@see RoundPlayerInputState::isSoloOnly()}).
     */
    public function revealed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_state' => RoundPlayerInputState::Revealed,
            'input_closed_at' => now(),
        ]);
    }

    /**
     * « Passer la manche » — **solo uniquement**.
     */
    public function skipped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_state' => RoundPlayerInputState::Skipped,
            'input_closed_at' => now(),
        ]);
    }

    /**
     * QCM composé : la langue de COMPOSITION est figée à la première composition,
     * et un changement de langue en cours de manche ne recompose jamais.
     */
    public function withChoices(Locale $locale = Locale::English): static
    {
        return $this->state(fn (array $attributes): array => [
            'choices_locale' => $locale,
            'choices_composed_at' => now(),
        ]);
    }

    /**
     * Tentatives fausses comptées, et rien d'autre — aucune ligne, aucun texte.
     */
    public function withWrongAttempts(int $wrongAttempts): static
    {
        return $this->state(fn (array $attributes): array => [
            'wrong_attempts' => $wrongAttempts,
        ]);
    }
}
