<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see RoundChoiceSet} — les quatre chaînes du QCM, figées à
 * la composition (§ 7.8).
 *
 * **`choice_1` est la bonne réponse en clair**, et l'ordre stocké n'est jamais
 * l'ordre affiché : la permutation est dérivée de
 * `HMAC(game.draw_seed, round_id, player_id)` à l'envoi, rien n'est stocké par
 * joueur. Une fabrique qui « mélangerait » les quatre colonnes casserait donc la
 * règle de jugement — une soumission `source = 'choice'` est jugée par **égalité
 * stricte contre `choice_1`**, et ne consulte jamais `answer_key`.
 *
 * Au plus **une ligne par (manche, locale activée)**, jamais une ligne par joueur,
 * qui coûterait ~720 Mo sur douze mois : `round_choice_set_round_locale_uq` le
 * ferme, et c'est pourquoi la fabrique n'expose aucun état « par joueur ».
 *
 * Les quatre chaînes sont **inventées** : aucun extrait de base de production
 * n'entre en fixture (§ 13.3), et elles n'ont donc aucune chance de coïncider avec
 * un titre réel du catalogue.
 *
 * @extends Factory<RoundChoiceSet>
 */
class RoundChoiceSetFactory extends Factory
{
    /**
     * Vocabulaire de titres de fabrique — manifestement inventé, et volontairement
     * accentué : les quatre chaînes partent au client telles quelles.
     *
     * @var list<string>
     */
    private const array TITLE_WORDS = [
        'Ombre', 'Cité', 'Rivière', 'Orage', 'Serment', 'Horizon',
        'Chasseur', 'Silence', 'Aurore', 'Fracture', 'Vertige', 'Dernier',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'locale' => Locale::English,
            'choice_1' => self::title(),
            'choice_2' => self::title(),
            'choice_3' => self::title(),
            'choice_4' => self::title(),
            'composed_at' => now(),
        ];
    }

    /**
     * Le jeu d'une autre locale activée — une ligne par locale, jamais plus.
     */
    public function forLocale(Locale $locale): static
    {
        return $this->state(fn (array $attributes): array => [
            'locale' => $locale,
        ]);
    }

    /**
     * Quatre chaînes imposées. `$correct` est **`choice_1`** : c'est la seule
     * colonne contre laquelle un clic est jugé, et l'ordre de stockage n'est
     * jamais celui de l'affichage.
     */
    public function withChoices(string $correct, string $decoy1, string $decoy2, string $decoy3): static
    {
        return $this->state(fn (array $attributes): array => [
            'choice_1' => $correct,
            'choice_2' => $decoy1,
            'choice_3' => $decoy2,
            'choice_4' => $decoy3,
        ]);
    }

    /**
     * Manche existante.
     */
    public function forRound(Round $round): static
    {
        return $this->state(fn (array $attributes): array => [
            'round_id' => $round->id,
        ]);
    }

    /**
     * Titre inventé de deux mots.
     */
    private static function title(): string
    {
        $last = count(self::TITLE_WORDS) - 1;

        return self::TITLE_WORDS[random_int(0, $last)].' '.self::TITLE_WORDS[random_int(0, $last)];
    }
}
