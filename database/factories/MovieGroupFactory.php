<?php

namespace Database\Factories;

use App\Models\MovieGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Le groupe manuel d'homonymes et de remakes (§ 3.3) — « même œuvre », jamais
 * « même saga ». Sans lui, Old Boy 2003 et Old Boy 2013 tombent ensemble et la
 * révélation devient incompréhensible.
 *
 * `label` est un libellé **interne** de back-office, jamais affiché à un joueur
 * et donc jamais localisé : c'est ce qui le distingue d'un thème de saga.
 * Jamais alimentée automatiquement ni par TMDB — le back-office signale des
 * candidats (formes normalisées proches lues dans `answer_key`, ou
 * `collection_id` identique) et un curateur tranche. C'est pourquoi
 * {@see self::createdBy()} existe : un groupe sans auteur est un groupe dont
 * personne n'a pris la décision.
 *
 * @extends Factory<MovieGroup>
 */
class MovieGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $words */
        $words = fake()->unique()->words(2, true);

        $title = Str::title($words);
        $first = fake()->numberBetween(1960, 1999);

        return [
            'label' => Str::limit(sprintf('%s %d / %d', $title, $first, $first + 15), 120, ''),
            'note' => 'Deux œuvres homonymes regroupées à la main : un remake, jamais une saga.',
            'created_by_id' => null,
        ];
    }

    /**
     * Le libellé interne, tel quel — « Old Boy 2003 / 2013 ».
     */
    public function labelled(string $label): static
    {
        return $this->state(['label' => Str::limit($label, 120, '')]);
    }

    /**
     * Le curateur qui a tranché. `nullOnDelete` : la traçabilité ne dépend pas
     * de la survie d'un compte.
     */
    public function createdBy(User $curator): static
    {
        return $this->state(['created_by_id' => $curator->id]);
    }

    /**
     * Un groupe sans justification écrite — toléré par le schéma, découragé par
     * le back-office.
     */
    public function withoutNote(): static
    {
        return $this->state(['note' => null]);
    }
}
