<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\MovieDifficulty;
use App\Enums\ThemeKind;
use App\Models\Theme;
use App\Models\ThemeLabel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * Un thème combinable par l'hôte (§ 3.7).
 *
 * **La fabrique pose D'OFFICE un libellé dans CHAQUE locale activée**, et c'est le
 * point le plus important du fichier : un thème n'est publiable que s'il porte un
 * `theme_label` dans chacune des locales de {@see Locale} (spec `05`). Sans ce
 * `afterCreating`, un catalogue de démonstration complet produirait un sélecteur de
 * thèmes vide, donc un vivier vide — un échec silencieux qui ne se lit nulle part
 * dans les données.
 *
 * L'état par défaut est **publié** pour la même raison : un thème de fixture non
 * publié n'a aucun usage, et {@see self::unpublished()} nomme explicitement le cas
 * contraire.
 *
 * `key` est l'identifiant technique stable que les seeders et les tests nomment ;
 * elle porte `theme_key_uq`, d'où le compteur plutôt qu'un tirage aléatoire.
 *
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory
{
    /**
     * Compteur de clés : `theme_key_uq` est un UNIQUE, et un `fake()->word()` finit
     * toujours par se répéter au milieu d'un seeder de démonstration.
     */
    private static int $keySequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = ++self::$keySequence;

        return [
            'key' => ThemeKind::Genre->value.'.demo-'.$sequence,
            'theme_kind' => ThemeKind::Genre,
            // Pour `genre` et `studio`, `rule_value` porte un `tmdb_tag_id` : la règle
            // s'évalue localement sur `movie_tmdb_tag`, sans aucun appel réseau. La
            // valeur par défaut ne désigne aucune étiquette réelle — un test de règle
            // passe l'identifiant qu'il a lui-même semé, via {@see self::genre()}.
            'rule_value' => (string) (10_000 + $sequence),
            'rule_negated' => false,
            'is_published' => true,
            'sort_order' => $sequence % 1_000,
        ];
    }

    /**
     * Le libellé de chaque locale activée, créé APRÈS la ligne et seulement pour les
     * locales encore absentes — de sorte qu'un `->has(ThemeLabel::factory()->french())`
     * explicite ne heurte jamais `theme_label_theme_locale_uq`.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Theme $theme): void {
            foreach (Locale::cases() as $locale) {
                $exists = ThemeLabel::query()
                    ->where('theme_id', $theme->id)
                    ->where('locale', $locale->value)
                    ->exists();

                if ($exists) {
                    continue;
                }

                ThemeLabel::factory()->for($theme)->locale($locale)->create();
            }
        });
    }

    /**
     * Le thème SANS aucun libellé — utilisé par {@see ThemeLabelFactory} pour se
     * fabriquer un parent sans occuper d'avance la locale qu'elle s'apprête à écrire.
     *
     * Vider `afterCreating` est le seul retrait supporté par `Factory` : les rappels
     * sont portés par le constructeur et recopiés par `newInstance()`, donc l'effet
     * survit à tout `->state()` ajouté ensuite.
     */
    public function withoutLabels(): static
    {
        return $this->newInstance(['afterCreating' => new Collection]);
    }

    public function published(): static
    {
        return $this->state(['is_published' => true]);
    }

    public function unpublished(): static
    {
        return $this->state(['is_published' => false]);
    }

    /**
     * Thème de genre — `rule_value` est un `tmdb_tag_id` de `tag_kind = genre`.
     */
    public function genre(int $tmdbTagId, ?string $slug = null): static
    {
        return $this->rule(ThemeKind::Genre, (string) $tmdbTagId, $slug ?? 'tag-'.$tmdbTagId);
    }

    /**
     * Thème de studio — `rule_value` est un `tmdb_tag_id` de `tag_kind = company`.
     */
    public function studio(int $tmdbTagId, ?string $slug = null): static
    {
        return $this->rule(ThemeKind::Studio, (string) $tmdbTagId, $slug ?? 'tag-'.$tmdbTagId);
    }

    /**
     * Thème de décennie — `rule_value` est l'année de DÉBUT (`decade.1990`).
     */
    public function decade(int $startYear): static
    {
        return $this->rule(ThemeKind::Decade, (string) $startYear, (string) $startYear);
    }

    /**
     * Thème de saga — `rule_value` est un `collection.id` local, jamais un identifiant
     * TMDB : la saga est une entité du catalogue.
     */
    public function saga(int $collectionId, ?string $slug = null): static
    {
        return $this->rule(ThemeKind::Saga, (string) $collectionId, $slug ?? 'collection-'.$collectionId);
    }

    /**
     * Thème de langue — combiné à {@see self::negated()}, il donne « cinéma
     * international » sans introduire ni liste ni expression à analyser.
     */
    public function language(string $code): static
    {
        return $this->rule(ThemeKind::Language, $code, $code);
    }

    /**
     * Thème de difficulté — projection de `movie.movie_difficulty` dans `movie_theme`.
     */
    public function difficulty(MovieDifficulty $difficulty): static
    {
        return $this->rule(ThemeKind::Difficulty, $difficulty->value, $difficulty->value);
    }

    public function negated(): static
    {
        return $this->state(['rule_negated' => true]);
    }

    /**
     * Impose la clé technique — celle que le seeder de démonstration et les tests
     * nomment (`genre.animation`, `studio.ghibli`).
     */
    public function withKey(string $key): static
    {
        return $this->state(['key' => $key]);
    }

    public function sortedAt(int $order): static
    {
        return $this->state(['sort_order' => $order]);
    }

    /**
     * La nature, sa règle et une clé cohérente, toujours ensemble : `theme_kind` seul
     * ne veut rien dire, c'est lui qui donne son interprétation à `rule_value`.
     */
    private function rule(ThemeKind $kind, string $ruleValue, string $slug): static
    {
        return $this->state([
            'key' => $kind->value.'.'.$slug,
            'theme_kind' => $kind,
            'rule_value' => $ruleValue,
        ]);
    }
}
