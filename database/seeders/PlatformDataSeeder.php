<?php

namespace Database\Seeders;

use App\Enums\Locale;
use App\Enums\MovieDifficulty;
use App\Enums\SettingPresetKey;
use App\Enums\ThemeKind;
use App\Models\SettingPreset;
use App\Models\Theme;
use App\Models\ThemeLabel;
use App\Settings\SettingPresetCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * **Les données du SITE** — celles sans lesquelles aucun salon ne peut être créé.
 *
 * Ce n'est pas de la démonstration : les quatre presets de `setting_preset` et les
 * thèmes de base sont du contenu livré **avec le code**. Ce seeder tourne partout,
 * production comprise, et il est **idempotent** — il réconcilie par la clé stable
 * (`setting_preset.key`, `theme.key`) plutôt que d'insérer, de sorte qu'un
 * redéploiement corrige un chiffrage sans dupliquer une ligne ni casser une
 * référence.
 *
 * Deux règles portées ici, et nulle part ailleurs :
 *
 * 1. **Aucun chiffrage n'est retapé.** Les cinq chiffres de chaque preset vivent
 *    dans {@see SettingPresetCatalog::inputFor()} et passent tous par
 *    `RoomSettings::fromInput()` : un preset livré par le site ne doit jamais
 *    produire un salon inlançable, et un chiffrage fautif lève **ici**, au
 *    seeding, plutôt que dans un lobby.
 * 2. **Un thème publié porte un libellé dans CHAQUE locale activée** (§ 3.7 et
 *    spec `05`), sans quoi le joueur verrait son identifiant technique. Le
 *    dictionnaire de libellés est vérifié avant écriture : un thème dont une
 *    locale manque fait échouer le seeder au lieu d'entrer publié et muet.
 *
 * Ce que ce seeder ne pose PAS, volontairement : aucun thème de `theme_kind =
 * saga`. `rule_value` y désigne un `collection.id` **local**, qui n'existe qu'une
 * fois un catalogue importé — une saga se publie donc en back-office, jamais
 * depuis un fichier du dépôt.
 */
class PlatformDataSeeder extends Seeder
{
    /**
     * Identifiants de **société** TMDB des trois studios nommés au produit.
     *
     * Ce sont des identifiants publics TMDB, jamais un extrait de base de
     * production : la règle d'un thème de studio s'évalue localement sur
     * `movie_tmdb_tag`, sans aucun appel réseau (§ 3.7).
     */
    private const int TMDB_COMPANY_DISNEY = 2;

    private const int TMDB_COMPANY_PIXAR = 3;

    private const int TMDB_COMPANY_GHIBLI = 10_342;

    /**
     * Les décennies proposées à l'hôte. `1930` couvre l'âge d'or de l'animation,
     * celui-là même qui n'entre au catalogue que par exception au filtre d'import
     * (décision 11) : sans elle, le film d'exception du § 13.3 n'appartiendrait à
     * aucun thème de décennie.
     *
     * @var list<int>
     */
    private const array DECADES = [1930, 1970, 1980, 1990, 2000, 2010, 2020];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedSettingPresets();
            $this->seedThemes();
        });
    }

    /**
     * Les quatre presets, réconciliés par `key`.
     *
     * `settings_version` n'est jamais posée ici : `RoomSettingsCast::set()`
     * l'écrit en même temps que la charge utile, jamais séparément.
     */
    private function seedSettingPresets(): void
    {
        foreach (SettingPresetKey::cases() as $key) {
            SettingPreset::query()->updateOrCreate(
                ['key' => $key],
                [
                    'position' => SettingPresetCatalog::positionFor($key),
                    'settings' => SettingPresetCatalog::settingsFor($key),
                ],
            );
        }
    }

    /**
     * Les thèmes de base et leurs libellés, réconciliés par `theme.key`.
     *
     * `is_published` est posé en **assignation directe** et non par un tableau :
     * la colonne est volontairement hors du `#[Fillable]` de {@see Theme}, la
     * publication étant un geste de back-office et non un champ de formulaire.
     */
    private function seedThemes(): void
    {
        foreach ($this->themeDefinitions() as $index => $definition) {
            $theme = Theme::query()->where('key', $definition['key'])->first() ?? new Theme;

            $theme->key = $definition['key'];
            $theme->theme_kind = $definition['kind'];
            $theme->rule_value = $definition['rule'];
            $theme->rule_negated = $definition['negated'];
            $theme->sort_order = $index + 1;
            $theme->is_published = true;
            $theme->save();

            $this->seedThemeLabels($theme, $definition['labels']);
        }
    }

    /**
     * Le libellé de chaque locale activée, et la garde qui rend l'oubli bruyant.
     *
     * @param  array<string, string>  $labels  locale d'INTERFACE => texte affiché
     */
    private function seedThemeLabels(Theme $theme, array $labels): void
    {
        foreach (Locale::cases() as $locale) {
            if (! array_key_exists($locale->value, $labels)) {
                throw new RuntimeException(
                    "Le thème [{$theme->key}] n'a pas de libellé en [{$locale->value}] : un thème publié sans "
                    .'libellé dans chaque locale activée affiche son identifiant technique au joueur (§ 3.7).',
                );
            }

            $label = ThemeLabel::query()
                ->where('theme_id', $theme->id)
                ->where('locale', $locale->value)
                ->first() ?? new ThemeLabel;

            $label->theme_id = $theme->id;
            $label->locale = $locale;
            $label->label = $labels[$locale->value];
            $label->save();
        }
    }

    /**
     * Les thèmes de base, dans leur ordre d'affichage.
     *
     * L'ordre du tableau **est** `sort_order` : il est figé par le site et ne
     * dépend d'aucun tri alphabétique, qui changerait d'une langue à l'autre.
     *
     * @return list<array{key: string, kind: ThemeKind, rule: string, negated: bool, labels: array<string, string>}>
     */
    private function themeDefinitions(): array
    {
        return array_merge(
            $this->genreThemes(),
            $this->studioThemes(),
            $this->decadeThemes(),
            $this->languageThemes(),
            $this->difficultyThemes(),
        );
    }

    /**
     * Genres — `rule_value` porte un `movie_tmdb_tag.tmdb_tag_id` de nature `genre`.
     *
     * @return list<array{key: string, kind: ThemeKind, rule: string, negated: bool, labels: array<string, string>}>
     */
    private function genreThemes(): array
    {
        /** @var list<array{slug: string, tag: int, en: string, fr: string}> $genres */
        $genres = [
            ['slug' => 'animation', 'tag' => 16, 'en' => 'Animation', 'fr' => 'Animation'],
            ['slug' => 'action', 'tag' => 28, 'en' => 'Action', 'fr' => 'Action'],
            ['slug' => 'adventure', 'tag' => 12, 'en' => 'Adventure', 'fr' => 'Aventure'],
            ['slug' => 'comedy', 'tag' => 35, 'en' => 'Comedy', 'fr' => 'Comédie'],
            ['slug' => 'drama', 'tag' => 18, 'en' => 'Drama', 'fr' => 'Drame'],
            ['slug' => 'fantasy', 'tag' => 14, 'en' => 'Fantasy', 'fr' => 'Fantastique'],
            ['slug' => 'horror', 'tag' => 27, 'en' => 'Horror', 'fr' => 'Horreur'],
            ['slug' => 'science-fiction', 'tag' => 878, 'en' => 'Science fiction', 'fr' => 'Science-fiction'],
            ['slug' => 'thriller', 'tag' => 53, 'en' => 'Thriller', 'fr' => 'Thriller'],
            ['slug' => 'crime', 'tag' => 80, 'en' => 'Crime', 'fr' => 'Policier'],
            ['slug' => 'family', 'tag' => 10_751, 'en' => 'Family', 'fr' => 'Famille'],
        ];

        return array_map(
            fn (array $genre): array => [
                'key' => ThemeKind::Genre->value.'.'.$genre['slug'],
                'kind' => ThemeKind::Genre,
                'rule' => (string) $genre['tag'],
                'negated' => false,
                'labels' => [Locale::English->value => $genre['en'], Locale::French->value => $genre['fr']],
            ],
            $genres,
        );
    }

    /**
     * Studios — `rule_value` porte un `tmdb_tag_id` de nature `company`.
     *
     * @return list<array{key: string, kind: ThemeKind, rule: string, negated: bool, labels: array<string, string>}>
     */
    private function studioThemes(): array
    {
        /** @var list<array{slug: string, tag: int, label: string}> $studios */
        $studios = [
            ['slug' => 'disney', 'tag' => self::TMDB_COMPANY_DISNEY, 'label' => 'Disney'],
            ['slug' => 'pixar', 'tag' => self::TMDB_COMPANY_PIXAR, 'label' => 'Pixar'],
            ['slug' => 'ghibli', 'tag' => self::TMDB_COMPANY_GHIBLI, 'label' => 'Studio Ghibli'],
        ];

        return array_map(
            fn (array $studio): array => [
                'key' => ThemeKind::Studio->value.'.'.$studio['slug'],
                'kind' => ThemeKind::Studio,
                'rule' => (string) $studio['tag'],
                'negated' => false,
                // Un nom de studio est un nom propre : il ne se traduit pas, mais il
                // porte quand même une ligne par locale — la règle « un libellé dans
                // chaque locale activée » n'admet aucune exception, sans quoi la garde
                // de publication devrait raisonner sur le contenu du texte.
                'labels' => [
                    Locale::English->value => $studio['label'],
                    Locale::French->value => $studio['label'],
                ],
            ],
            $studios,
        );
    }

    /**
     * Décennies — `rule_value` porte l'année de DÉBUT.
     *
     * @return list<array{key: string, kind: ThemeKind, rule: string, negated: bool, labels: array<string, string>}>
     */
    private function decadeThemes(): array
    {
        return array_map(
            fn (int $start): array => [
                'key' => ThemeKind::Decade->value.'.'.$start,
                'kind' => ThemeKind::Decade,
                'rule' => (string) $start,
                'negated' => false,
                'labels' => [
                    Locale::English->value => $start.'s',
                    Locale::French->value => 'Années '.$start,
                ],
            ],
            self::DECADES,
        );
    }

    /**
     * « Cinéma international » — le seul thème négué du lot, et la raison d'être de
     * `rule_negated` : il s'écrit `original_language <> 'en'` sans introduire ni
     * liste de langues ni expression à analyser.
     *
     * @return list<array{key: string, kind: ThemeKind, rule: string, negated: bool, labels: array<string, string>}>
     */
    private function languageThemes(): array
    {
        return [[
            'key' => ThemeKind::Language->value.'.international',
            'kind' => ThemeKind::Language,
            'rule' => Locale::English->value,
            'negated' => true,
            'labels' => [
                Locale::English->value => 'International cinema',
                Locale::French->value => 'Cinéma international',
            ],
        ]];
    }

    /**
     * Difficulté — projection de `movie.movie_difficulty`, qui ne porte aucun index :
     * `movie_theme` **est** sa forme requêtable (§ 3.7).
     *
     * @return list<array{key: string, kind: ThemeKind, rule: string, negated: bool, labels: array<string, string>}>
     */
    private function difficultyThemes(): array
    {
        /** @var array<string, array{en: string, fr: string}> $labels */
        $labels = [
            MovieDifficulty::VeryEasy->value => ['en' => 'Very easy', 'fr' => 'Très facile'],
            MovieDifficulty::Easy->value => ['en' => 'Easy', 'fr' => 'Facile'],
            MovieDifficulty::Medium->value => ['en' => 'Medium', 'fr' => 'Moyen'],
            MovieDifficulty::Hard->value => ['en' => 'Hard', 'fr' => 'Difficile'],
            MovieDifficulty::VeryHard->value => ['en' => 'Very hard', 'fr' => 'Très difficile'],
        ];

        return array_map(
            fn (MovieDifficulty $difficulty): array => [
                'key' => ThemeKind::Difficulty->value.'.'.$difficulty->value,
                'kind' => ThemeKind::Difficulty,
                'rule' => $difficulty->value,
                'negated' => false,
                'labels' => [
                    Locale::English->value => $labels[$difficulty->value]['en'],
                    Locale::French->value => $labels[$difficulty->value]['fr'],
                ],
            ],
            MovieDifficulty::cases(),
        );
    }
}
