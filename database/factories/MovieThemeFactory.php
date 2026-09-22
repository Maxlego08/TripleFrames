<?php

namespace Database\Factories;

use App\Enums\ThemeMembershipState;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * L'appartenance d'un film à un thème, avec la règle et l'exception SÉPARÉES (§ 3.7).
 *
 * `is_auto` est le résultat de la règle automatique, réécrit librement à chaque
 * recalcul ; `manual_state` est l'exception d'un curateur, et elle seule fait survivre
 * l'appartenance au réimport (§ 9.3) ; `is_active` est l'appartenance EFFECTIVE
 * dénormalisée, parce que le vivier a besoin d'un prédicat indexé et non d'un `OR` à
 * deux branches évalué à chaque frappe du lobby.
 *
 * **Les trois colonnes ne sont jamais posées indépendamment** : chaque état passe par
 * {@see MovieTheme::resolveIsActive()}. Une fixture qui écrirait `manual_state =
 * 'removed'` en laissant `is_active = true` produirait un vivier qui contient un film
 * que la fiche de curation affiche comme retiré — exactement la dérive que la colonne
 * dénormalisée existe pour rendre impossible.
 *
 * @extends Factory<MovieTheme>
 */
class MovieThemeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movie_id' => Movie::factory(),
            'theme_id' => Theme::factory(),
            'is_auto' => true,
            'manual_state' => null,
            'is_active' => MovieTheme::resolveIsActive(true, null),
            'assigned_by_id' => null,
            'assigned_at' => null,
        ];
    }

    /**
     * Appartenance purement automatique : la règle matche, aucun curateur n'est passé.
     */
    public function auto(): static
    {
        return $this->state([
            'is_auto' => true,
            'manual_state' => null,
            'is_active' => MovieTheme::resolveIsActive(true, null),
            'assigned_by_id' => null,
            'assigned_at' => null,
        ]);
    }

    /**
     * La règle ne matche pas et aucune exception n'existe : la ligne subsiste, inactive.
     */
    public function notAuto(): static
    {
        return $this->state([
            'is_auto' => false,
            'manual_state' => null,
            'is_active' => MovieTheme::resolveIsActive(false, null),
            'assigned_by_id' => null,
            'assigned_at' => null,
        ]);
    }

    /**
     * Ajout manuel par un curateur — prime sur `is_auto`, survit au réimport.
     */
    public function manualAdded(?User $curator = null): static
    {
        return $this->manual(ThemeMembershipState::Added, $curator);
    }

    /**
     * Retrait manuel par un curateur : la ligne est CONSERVÉE précisément pour qu'un
     * recalcul ne ressuscite pas l'appartenance.
     */
    public function manualRemoved(?User $curator = null): static
    {
        return $this->manual(ThemeMembershipState::Removed, $curator);
    }

    /**
     * L'exception manuelle, son auteur et sa date — les trois ensemble, jamais l'une
     * sans les autres : `assigned_at` trie la file du back-office par ancienneté.
     */
    private function manual(ThemeMembershipState $state, ?User $curator): static
    {
        return $this->state([
            'manual_state' => $state,
            'is_active' => MovieTheme::resolveIsActive(false, $state),
            'assigned_by_id' => $curator === null ? User::factory()->curator() : $curator->id,
            'assigned_at' => now(),
        ]);
    }
}
