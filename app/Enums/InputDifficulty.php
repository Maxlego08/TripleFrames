<?php

namespace App\Enums;

/** Difficulté de saisie réglée par l'hôte, à ne confondre ni avec `movie_difficulty`, ni avec `frame_level`, ni avec `frames_per_round` : cast de `room.input_difficulty` et `game.input_difficulty`. */
enum InputDifficulty: string
{
    case Easy = 'easy';

    case Normal = 'normal';

    case Expert = 'expert';

    /** Faux en Expert : `round.decoy_movie_id_1..3` y sont NULL et aucun QCM n'est composé. */
    public function hasChoices(): bool
    {
        return $this !== self::Expert;
    }

    /** Index de palier à l'ouverture duquel les quatre propositions sont poussées, NULL si la partie n'en a pas. */
    public function choicesOpenTierIndex(int $n): ?int
    {
        return match ($this) {
            self::Easy => 1,
            self::Normal => $n,
            self::Expert => null,
        };
    }
}
