<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Theme;
use App\Models\ThemeLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Le libellé d'un thème dans une locale d'INTERFACE (§ 3.7).
 *
 * `theme_label.locale` est castée par {@see Locale} — c'est la seule des trois
 * familles de contenu traduit en base à porter une locale d'interface et non une
 * locale de catalogue (§ 1.3) : un `theme_label` en `ja` n'existera jamais, et la
 * garde de publication compare aux cas de l'enum.
 *
 * Le parent par défaut est un thème **sans libellé**
 * ({@see ThemeFactory::withoutLabels()}) : sans cela, `Theme::factory()` poserait
 * lui-même les deux locales et la ligne fabriquée ici heurterait
 * `theme_label_theme_locale_uq` — un `ThemeLabel::factory()->create()` nu, celui du
 * balayage de modèles, échouerait systématiquement.
 *
 * @extends Factory<ThemeLabel>
 */
class ThemeLabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'theme_id' => Theme::factory()->withoutLabels(),
            'locale' => Locale::English,
            'label' => rtrim(fake()->sentence(2), '.'),
        ];
    }

    /**
     * La locale du libellé — toujours un cas de l'enum, jamais une chaîne libre.
     */
    public function locale(Locale $locale): static
    {
        return $this->state(['locale' => $locale]);
    }

    public function english(): static
    {
        return $this->locale(Locale::English);
    }

    public function french(): static
    {
        return $this->locale(Locale::French);
    }

    /**
     * Le texte affiché au joueur — éditable en back-office, borné à 80 caractères.
     */
    public function labelled(string $label): static
    {
        return $this->state(['label' => $label]);
    }
}
