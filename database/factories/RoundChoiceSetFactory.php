<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\ValueObjects\Answers\ChoicesPayload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see RoundChoiceSet} — les quatre chaînes du QCM, figées à
 * la composition (§ 7.8).
 *
 * **`choice_1` est la bonne réponse en clair**, et l'ordre stocké n'est jamais
 * l'ordre affiché : la permutation est dérivée à l'envoi par
 * `SeededPrf::forGame(game)->permutation(DrawContext::qcmOrder(sequence_index,
 * player.public_id), 4)` (E10-54), rien n'est stocké par joueur. Une fabrique
 * qui « mélangerait » les quatre colonnes casserait donc la règle de jugement —
 * une soumission `source = 'choice'` est jugée par **égalité stricte contre
 * `choice_1`**, et ne consulte jamais `answer_key`.
 *
 * `rendered_locale` (E10-03) suit par défaut la locale de la ligne — le rang 1
 * de la chaîne de repli, cas nominal ; {@see self::renderedIn()} pose une autre
 * locale atteinte (rang 2) ou NULL (titres originaux).
 *
 * Les quatre chaînes sont **deux à deux distinctes**, comme la composition les
 * garantit : {@see ChoicesPayload} refuse un doublon, qui rendrait un clic ambigu.
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
        [$correct, $decoy1, $decoy2, $decoy3] = self::distinctTitles();

        return [
            'round_id' => Round::factory(),
            'locale' => Locale::English,
            'rendered_locale' => static fn (array $attributes): mixed => $attributes['locale'],
            'choice_1' => $correct,
            'choice_2' => $decoy1,
            'choice_3' => $decoy2,
            'choice_4' => $decoy3,
            'composed_at' => now(),
        ];
    }

    /**
     * Le jeu d'une autre locale activée — une ligne par locale, jamais plus.
     * La locale atteinte suit, sauf {@see self::renderedIn()}.
     */
    public function forLocale(Locale $locale): static
    {
        return $this->state(fn (array $attributes): array => [
            'locale' => $locale,
        ]);
    }

    /**
     * Locale EFFECTIVE atteinte par les quatre chaînes : une autre locale
     * activée (rang 2 de la chaîne de repli), ou NULL quand elles sortent de
     * `title_original` (rang 3, mode dégradé).
     */
    public function renderedIn(?Locale $locale): static
    {
        return $this->state(fn (array $attributes): array => [
            'rendered_locale' => $locale,
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
     * Quatre titres inventés de deux mots, deux à deux distincts.
     *
     * @return array{string, string, string, string}
     */
    private static function distinctTitles(): array
    {
        $titles = [];

        while (count($titles) < ChoicesPayload::COUNT) {
            $titles[self::title()] = true;
        }

        [$correct, $decoy1, $decoy2, $decoy3] = array_keys($titles);

        return [(string) $correct, (string) $decoy1, (string) $decoy2, (string) $decoy3];
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
