<?php

namespace Database\Seeders;

use App\Enums\Locale;
use App\Enums\MovieDifficulty;
use App\Enums\SettingPresetKey;
use App\Enums\ThemeKind;
use App\Jobs\Catalog\SyncThemeMembership;
use App\Models\Collection;
use App\Models\SettingPreset;
use App\Models\Theme;
use App\Models\TmdbCompany;
use App\Settings\SettingPresetCatalog;
use App\Support\Admin\ThemeDesignation;
use App\Support\Catalog\ThemeRules;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * **Les données du SITE** — celles sans lesquelles aucun salon ne peut être créé.
 *
 * Ce n'est pas de la démonstration : les quatre presets de `setting_preset` et les
 * thèmes de base sont du contenu livré **avec le code**. Ce seeder tourne partout,
 * production comprise, et le hook de déploiement le rejoue **à chaque
 * déploiement** (contrat C18-bis, étape 6). Il est donc **scindé** en deux
 * régimes que rien ne mélange (spec 30 § 12.5) :
 *
 * 1. **Réconciliation** de `setting_preset` par `key` (`updateOrCreate`) — la
 *    seule table dont ce seeder est l'écrivain unique (spec 10 § 6.3) : aucun
 *    écran ne l'édite, donc un redéploiement y corrige un chiffrage sans dupliquer
 *    une ligne ni casser une référence. Aucun chiffrage n'est retapé : les cinq
 *    chiffres de chaque preset vivent dans {@see SettingPresetCatalog::inputFor()}
 *    et passent tous par `RoomSettings::fromInput()`, de sorte qu'un chiffrage
 *    fautif lève **ici**, au seeding, plutôt que dans un lobby.
 * 2. **Amorçage en insertion si absent** de `collection` (par `tmdb_id`), `theme`
 *    (par `key`), `theme_label` (par `(theme_id, locale)`) et `tmdb_company` (par
 *    `tmdb_id`) : une ligne présente n'est **jamais réécrite**, quel que soit son
 *    contenu. Ce sont des lignes que le back-office édite (publication, ordre,
 *    libellés, règle) ou que l'import nomme : un seeder qui les réconcilierait
 *    effacerait ces éditions au déploiement suivant, et « publier une saga n'exige
 *    pas un déploiement » (S4 du 23/09) deviendrait faux. Corriger un thème livré
 *    après son insertion se fait donc en back-office, jamais en modifiant ce
 *    fichier — `studio.disney` compris, dont la règle corrigée
 *    (`2,6125,171656`) est posée par le porteur, jamais ici (spec 30 § 12.3).
 *
 * **Garde de libellés, à l'insertion seulement.** Un thème naît avec un libellé
 * dans CHAQUE locale activée (spec `05`, § 3.7), sans quoi le joueur verrait son
 * identifiant technique : une définition dont une locale manque fait échouer le
 * seeder avant d'écrire le thème. Sur un thème déjà présent, le seeder n'ajoute
 * que les libellés absents que sa définition fournit et ne lève jamais —
 * compléter les libellés d'une nouvelle locale reste l'étape 3 de la procédure
 * de `05` § Ajouter une troisième langue, en back-office.
 *
 * **`sort_order` par blocs de famille**, posé à l'insertion par
 * {@see self::sortOrderFor()} et jamais réécrit : `bloc × 100 + rang`. Un thème
 * livré après coup se range ainsi dans le bloc de sa famille sans rien décaler.
 * Les bases amorcées par l'ancien seeder (ordre séquentiel 1 à 27) sont
 * réalignées une fois par la migration `realign_platform_theme_sort_order`.
 *
 * **Tout thème ajouté par le lot L30-9 naît non publié** (spec 30 § 12.3,
 * D43 du 01/10) : décennies 1940-1960, `studio.marvel`, `studio.dc`,
 * `language.anime` et les douze sagas par défaut de S4 du 23/09 (§ 12.4). Les
 * thèmes livrés avant ce lot restent publiés à l'insertion. Un thème ajouté peut
 * ne compter aucun film curé ; sa publication est un geste du back-office, sans
 * déploiement.
 *
 * **Les sagas sont livrées avec leur `collection`**, insérée par `tmdb_id` si
 * elle manque, au `name` littéral de ce fichier : ce seeder n'appelle jamais
 * TMDB, parce qu'il tourne dans le hook de déploiement et dans une suite de
 * tests à zéro secret. **Gardes de désignation** (§ 12.5) : un thème de saga
 * n'est pas inséré quand sa collection est déjà désignée par un thème de saga,
 * et un thème studio quand l'une de ses sociétés est déjà désignée par un thème
 * studio, fût-ce sous une autre clé.
 *
 * **Chaque thème réellement inséré dispatche sa synchronisation**
 * ({@see SyncThemeMembership}, après commit, règle portée par la classe) : un
 * thème amorcé par le hook ne passe par aucun geste du back-office et naîtrait
 * sinon sans aucune appartenance (spec 30 § 13.2). Rien n'est dispatché pour une
 * ligne déjà présente, ni pour un thème écarté par une garde de désignation.
 *
 * @phpstan-type ThemeDefinition array{
 *     key: string,
 *     kind: ThemeKind,
 *     rule: string|null,
 *     negated: bool,
 *     published: bool,
 *     collection: array{tmdb_id: int, name: string}|null,
 *     labels: array<string, string>,
 * }
 */
class PlatformDataSeeder extends Seeder
{
    /**
     * Largeur d'un bloc de famille dans `theme.sort_order` : `bloc × 100 + rang`
     * (spec 30 § 12.5). Une valeur sous ce seuil ne peut venir que de l'ancien
     * ordre séquentiel, ce que la migration de réalignement exploite.
     */
    public const int SORT_ORDER_BLOCK = ThemeKind::SORT_BLOCK_WIDTH;

    /**
     * Identifiants de **société** TMDB des studios nommés au produit.
     *
     * Ce sont des identifiants publics TMDB, relevés par le porteur le 01/10,
     * jamais un extrait de base de production : la règle d'un thème de studio
     * s'évalue localement sur `movie_tmdb_tag`, sans aucun appel réseau (§ 3.7).
     */
    private const int TMDB_COMPANY_DISNEY = 2;

    private const int TMDB_COMPANY_PIXAR = 3;

    private const int TMDB_COMPANY_GHIBLI = 10_342;

    private const int TMDB_COMPANY_MARVEL_STUDIOS = 420;

    /**
     * DC vit sous trois sociétés TMDB, aucune ne couvrant seule ses films :
     * « DC » pour les films anciens, « DC Films » de 2017 à 2022, « DC Studios »
     * depuis 2025 — d'où la règle studio multi-valeurs (spec 30 § 12.1).
     *
     * @var list<int>
     */
    private const array TMDB_COMPANIES_DC = [429, 128_064, 184_898];

    /**
     * Noms TMDB non localisés des sociétés que ce seeder nomme dans
     * `tmdb_company` (spec 10 § 3.6 bis) : celles que désignent les thèmes studio
     * livrés, plus 6125 et 171656, de la correction de `studio.disney` que le
     * porteur pose en back-office. Littéraux relevés le 01/10, jamais lus chez
     * TMDB ici.
     *
     * @var array<int, string>
     */
    private const array TMDB_COMPANY_NAMES = [
        self::TMDB_COMPANY_DISNEY => 'Walt Disney Pictures',
        self::TMDB_COMPANY_PIXAR => 'Pixar',
        self::TMDB_COMPANY_GHIBLI => 'Studio Ghibli',
        self::TMDB_COMPANY_MARVEL_STUDIOS => 'Marvel Studios',
        429 => 'DC',
        128_064 => 'DC Films',
        184_898 => 'DC Studios',
        6125 => 'Walt Disney Animation Studios',
        171_656 => 'Walt Disney Feature Animation',
    ];

    /**
     * Année de début de la première décennie du bloc des décennies : le rang
     * d'une décennie vaut `(année − 1930) / 10 + 1` (spec 30 § 12.5).
     */
    private const int FIRST_DECADE = 1930;

    /**
     * Les décennies proposées à l'hôte, de 1930 à 2020 **sans trou** (spec 30
     * § 12.3) : la voie d'exception fait entrer l'âge d'or de l'animation et les
     * classiques antérieurs à 1970 (décision 11), et un trou de 1940 à 1960 les
     * laisserait sans aucun thème de décennie.
     *
     * @var list<int>
     */
    private const array DECADES = [1930, 1940, 1950, 1960, 1970, 1980, 1990, 2000, 2010, 2020];

    /**
     * Les décennies ajoutées par L30-9, nées **non publiées** comme tout thème
     * ajouté (spec 30 § 12.3).
     *
     * @var list<int>
     */
    private const array DECADES_BORN_UNPUBLISHED = [1940, 1950, 1960];

    public function run(): void
    {
        // Sous le verrou de désignation de l'écran des thèmes (critique C5) :
        // un hook de déploiement qui insère une saga ou un studio pendant
        // qu'un curateur en crée un ne désigne jamais deux fois la même
        // collection ni la même société — les gardes ci-dessous se relisent
        // sous ce verrou, comme celles de `CreateTheme`.
        ThemeDesignation::exclusively(fn () => DB::transaction(function (): void {
            $this->reconcileSettingPresets();
            $this->seedTmdbCompanies();

            foreach ($this->seedThemes() as $theme) {
                // Après commit, règle portée par la classe (`ShouldQueueAfterCommit`) :
                // le job ne lit jamais un thème encore invisible hors de cette
                // transaction (spec 30 § 13.2).
                SyncThemeMembership::dispatch($theme->id);
            }
        }));
    }

    /**
     * L'ordre d'affichage d'un thème livré : `bloc × 100 + rang` (spec 30 § 12.5).
     *
     * Blocs : genre = 1, studio = 2, décennie = 3, langue = 4, difficulté = 5,
     * saga = 6. Le rang d'une décennie se calcule depuis son année
     * (`(année − 1930) / 10 + 1`) ; celui des autres familles est la position de
     * la clé dans la liste livrée de sa famille, à partir de 1. L'ordre d'affichage
     * étant `sort_order` puis `key`, une insertion ultérieure (une décennie, un
     * studio, une saga) se range dans le bloc de sa famille sans décaler aucun
     * thème déjà inséré.
     *
     * La règle vit ici et nulle part ailleurs : le seeder la pose à l'insertion,
     * la migration de réalignement la pose une fois sur les bases amorcées par
     * l'ancien ordre séquentiel.
     *
     * @throws InvalidArgumentException clé qui n'est pas celle d'un thème livré
     */
    public static function sortOrderFor(string $key): int
    {
        $kind = ThemeKind::tryFrom(Str::before($key, '.'));

        if ($kind === null || ! str_contains($key, '.')) {
            throw new InvalidArgumentException("La clé de thème [{$key}] ne commence par aucune nature de ThemeKind.");
        }

        $rank = $kind === ThemeKind::Decade
            ? self::decadeRank($key)
            : self::familyRank($kind, $key);

        if ($rank < 1 || $rank >= self::SORT_ORDER_BLOCK) {
            throw new InvalidArgumentException(
                "Le rang [{$rank}] du thème [{$key}] sort de son bloc de famille (1 à ".(self::SORT_ORDER_BLOCK - 1).').',
            );
        }

        return self::familyBlock($kind) * self::SORT_ORDER_BLOCK + $rank;
    }

    /**
     * Les clés de tous les thèmes livrés par ce seeder, dans leur ordre
     * d'affichage — celles que la migration de réalignement a le droit de toucher.
     *
     * @return list<string>
     */
    public static function deliveredThemeKeys(): array
    {
        return array_map(
            static fn (array $definition): string => $definition['key'],
            self::themeDefinitions(),
        );
    }

    /**
     * La collection **livrée** d'une saga par défaut, prise ou insérée par son
     * `tmdb_id` avec le `name` littéral de ce seeder, jamais réécrite.
     *
     * Publique pour le catalogue de démonstration, qui rattache ses films de saga
     * à cette ligne et jamais à une collection sans `tmdb_id` : une saga de
     * démonstration détachée laisserait le thème livré vide (spec 30 L30-9).
     *
     * @throws InvalidArgumentException clé qui n'est pas celle d'une saga livrée
     */
    public static function deliveredSagaCollection(string $key): Collection
    {
        foreach (self::sagaThemes() as $definition) {
            if ($definition['key'] === $key && $definition['collection'] !== null) {
                return self::collectionFor($definition['collection']['tmdb_id'], $definition['collection']['name']);
            }
        }

        throw new InvalidArgumentException("La clé [{$key}] n'est pas celle d'une saga livrée.");
    }

    /**
     * Les quatre presets, réconciliés par `key` : ce seeder est leur seul
     * écrivain (spec 10 § 6.3), la réconciliation n'efface donc aucune édition.
     *
     * `settings_version` n'est jamais posée ici : `RoomSettingsCast::set()`
     * l'écrit en même temps que la charge utile, jamais séparément.
     */
    private function reconcileSettingPresets(): void
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
     * Les noms des sociétés nommées par ce seeder, en insertion si absente : un
     * nom présent — écrit par `MovieImporter` ou par `catalog:company-names` —
     * n'est jamais réécrit (spec 10 § 3.6 bis).
     */
    private function seedTmdbCompanies(): void
    {
        foreach (self::TMDB_COMPANY_NAMES as $tmdbId => $name) {
            TmdbCompany::query()->firstOrCreate(['tmdb_id' => $tmdbId], ['name' => $name]);
        }
    }

    /**
     * Les thèmes livrés et leurs libellés, en insertion si absent.
     *
     * Un thème présent sous sa clé n'est jamais réécrit — ni `is_published`, ni
     * `sort_order`, ni sa règle — et ses libellés présents non plus : seuls les
     * libellés absents que la définition fournit sont ajoutés.
     *
     * @return list<Theme> les thèmes réellement insérés par ce passage
     */
    private function seedThemes(): array
    {
        $inserted = [];

        foreach (self::themeDefinitions() as $definition) {
            $theme = Theme::query()->where('key', $definition['key'])->first();

            if ($theme === null) {
                $theme = $this->insertTheme($definition);

                if ($theme === null) {
                    continue;
                }

                $inserted[] = $theme;
            }

            $this->insertMissingLabels($theme, $definition['labels']);
        }

        return $inserted;
    }

    /**
     * L'insertion d'un thème livré absent, garde de libellés comprise.
     *
     * `is_published` est posé en **assignation directe** et non par un tableau :
     * la colonne est volontairement hors du `#[Fillable]` de {@see Theme}, la
     * publication étant un geste de back-office et non un champ de formulaire.
     *
     * Rend `null`, sans rien écrire, pour un thème de saga dont la collection est
     * déjà désignée par un thème de saga, et pour un thème studio dont l'une des
     * sociétés est déjà désignée par un thème studio (spec 30 § 12.5) : la ligne
     * existante l'emporte, et deux thèmes sur une même collection ou une même
     * société afficheraient deux fois le même choix au sélecteur.
     *
     * @param  ThemeDefinition  $definition
     */
    private function insertTheme(array $definition): ?Theme
    {
        $this->assertEveryLocaleLabel($definition['key'], $definition['labels']);

        $rule = $definition['rule'];

        if ($definition['collection'] !== null) {
            $collection = self::collectionFor($definition['collection']['tmdb_id'], $definition['collection']['name']);

            if ($this->isDesignatedBySaga($collection)) {
                return null;
            }

            $rule = (string) $collection->id;
        }

        if ($definition['kind'] === ThemeKind::Studio) {
            if ($rule === null || ! ThemeRules::isValidStudioRule($rule)) {
                throw new RuntimeException(
                    "Le thème studio livré [{$definition['key']}] porte une règle hors de la forme canonique ou "
                    .'des bornes de la règle multi-valeurs (spec 30 § 12.1).',
                );
            }

            if ($this->isDesignatedByStudio(ThemeRules::studioCompanyIds($rule))) {
                return null;
            }
        }

        $theme = new Theme;
        $theme->key = $definition['key'];
        $theme->theme_kind = $definition['kind'];
        $theme->rule_value = $rule;
        $theme->rule_negated = $definition['negated'];
        $theme->sort_order = self::sortOrderFor($definition['key']);
        $theme->is_published = $definition['published'];
        $theme->save();

        return $theme;
    }

    /**
     * La garde qui rend l'oubli bruyant, à l'insertion seulement : un thème qui
     * naîtrait sans libellé dans une locale activée afficherait son identifiant
     * technique au joueur (§ 3.7 et spec `05`).
     *
     * @param  array<string, string>  $labels  locale d'INTERFACE => texte affiché
     */
    private function assertEveryLocaleLabel(string $key, array $labels): void
    {
        foreach (Locale::cases() as $locale) {
            if (! array_key_exists($locale->value, $labels)) {
                throw new RuntimeException(
                    "Le thème [{$key}] n'a pas de libellé en [{$locale->value}] : un thème livré naît avec un "
                    .'libellé dans chaque locale activée, sans quoi le joueur verrait son identifiant technique (§ 3.7).',
                );
            }
        }
    }

    /**
     * Les libellés absents, par `(theme_id, locale)` : un libellé présent — édité
     * en back-office ou non — n'est jamais réécrit, et une locale que la
     * définition ne fournit pas est laissée au back-office, sans lever.
     *
     * @param  array<string, string>  $labels  locale d'INTERFACE => texte affiché
     */
    private function insertMissingLabels(Theme $theme, array $labels): void
    {
        foreach ($theme->missingLabelLocales() as $locale) {
            if (! array_key_exists($locale->value, $labels)) {
                continue;
            }

            $theme->labels()->create([
                'locale' => $locale,
                'label' => $labels[$locale->value],
            ]);
        }
    }

    /**
     * La collection d'une saga livrée, par `tmdb_id`, insérée si absente : une
     * ligne déjà créée par `MovieImporter` n'est pas réécrite, son `name` compris.
     * `name` est le littéral du seeder, jamais lu chez TMDB (spec 30 § 12.4).
     */
    private static function collectionFor(int $tmdbId, string $name): Collection
    {
        return Collection::query()->firstOrCreate(['tmdb_id' => $tmdbId], ['name' => $name]);
    }

    private function isDesignatedBySaga(Collection $collection): bool
    {
        return Theme::query()
            ->where('theme_kind', ThemeKind::Saga)
            ->where('rule_value', (string) $collection->id)
            ->exists();
    }

    /**
     * Vrai si l'une des sociétés est déjà désignée par un thème studio, fût-ce
     * dans une liste et sous une autre clé (spec 30 § 12.5) : pendant de
     * {@see self::isDesignatedBySaga()}. Les règles se découpent en PHP par
     * {@see ThemeRules::studioCompanyIds()}, seul lecteur de leur forme — une
     * recherche `LIKE` confondrait 42 et 420. Les thèmes studio se comptent sur
     * les doigts : les lire tous est sans coût.
     *
     * @param  list<int>  $companyIds
     */
    private function isDesignatedByStudio(array $companyIds): bool
    {
        /** @var list<string> $rules */
        $rules = Theme::query()
            ->where('theme_kind', ThemeKind::Studio)
            ->whereNotNull('rule_value')
            ->pluck('rule_value')
            ->all();

        foreach ($rules as $rule) {
            if (array_intersect($companyIds, ThemeRules::studioCompanyIds($rule)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le bloc de chaque famille dans `sort_order` (spec 30 § 12.5).
     */
    private static function familyBlock(ThemeKind $kind): int
    {
        return $kind->sortBlock();
    }

    /**
     * Rang d'une décennie, calculé depuis son année de début : il ne dépend pas
     * de la liste livrée, pour qu'une décennie ajoutée plus tard tombe à sa place
     * chronologique (`decade.1940` → 302).
     */
    private static function decadeRank(string $key): int
    {
        $year = Str::after($key, '.');

        if (preg_match('/^\d{4}$/', $year) !== 1
            || (int) $year < self::FIRST_DECADE
            || (int) $year % 10 !== 0) {
            throw new InvalidArgumentException("La clé de décennie [{$key}] ne porte pas une année de début de décennie depuis ".self::FIRST_DECADE.'.');
        }

        return intdiv((int) $year - self::FIRST_DECADE, 10) + 1;
    }

    /**
     * Rang d'un thème dans la liste livrée de sa famille, à partir de 1.
     */
    private static function familyRank(ThemeKind $kind, string $key): int
    {
        foreach (self::familyDefinitions($kind) as $index => $definition) {
            if ($definition['key'] === $key) {
                return $index + 1;
            }
        }

        throw new InvalidArgumentException("La clé [{$key}] n'est pas celle d'un thème livré de la famille [{$kind->value}].");
    }

    /**
     * Les thèmes livrés, dans leur ordre d'affichage : familles dans l'ordre de
     * leurs blocs, rang croissant dans chaque famille. Il ne dépend d'aucun tri
     * alphabétique, qui changerait d'une langue à l'autre.
     *
     * @return list<ThemeDefinition>
     */
    private static function themeDefinitions(): array
    {
        return array_merge(
            self::genreThemes(),
            self::studioThemes(),
            self::decadeThemes(),
            self::languageThemes(),
            self::difficultyThemes(),
            self::sagaThemes(),
        );
    }

    /**
     * @return list<ThemeDefinition>
     */
    private static function familyDefinitions(ThemeKind $kind): array
    {
        return match ($kind) {
            ThemeKind::Genre => self::genreThemes(),
            ThemeKind::Studio => self::studioThemes(),
            ThemeKind::Decade => self::decadeThemes(),
            ThemeKind::Language => self::languageThemes(),
            ThemeKind::Difficulty => self::difficultyThemes(),
            ThemeKind::Saga => self::sagaThemes(),
        };
    }

    /**
     * Genres — `rule_value` porte un `movie_tmdb_tag.tmdb_tag_id` de nature `genre`.
     *
     * @return list<ThemeDefinition>
     */
    private static function genreThemes(): array
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
            static fn (array $genre): array => [
                'key' => ThemeKind::Genre->value.'.'.$genre['slug'],
                'kind' => ThemeKind::Genre,
                'rule' => (string) $genre['tag'],
                'negated' => false,
                'published' => true,
                'collection' => null,
                'labels' => [Locale::English->value => $genre['en'], Locale::French->value => $genre['fr']],
            ],
            $genres,
        );
    }

    /**
     * Studios — `rule_value` porte une **liste** de `tmdb_tag_id` de nature
     * `company`, sous la forme canonique de {@see ThemeRules::studioRuleValue()}
     * (spec 30 § 12.1) ; un seul identifiant reste une liste d'un élément.
     *
     * Marvel et DC, ajoutés par L30-9, naissent non publiés (§ 12.3).
     *
     * @return list<ThemeDefinition>
     */
    private static function studioThemes(): array
    {
        /** @var list<array{slug: string, companies: list<int>, label: string, published: bool}> $studios */
        $studios = [
            ['slug' => 'disney', 'companies' => [self::TMDB_COMPANY_DISNEY], 'label' => 'Disney', 'published' => true],
            ['slug' => 'pixar', 'companies' => [self::TMDB_COMPANY_PIXAR], 'label' => 'Pixar', 'published' => true],
            ['slug' => 'ghibli', 'companies' => [self::TMDB_COMPANY_GHIBLI], 'label' => 'Studio Ghibli', 'published' => true],
            ['slug' => 'marvel', 'companies' => [self::TMDB_COMPANY_MARVEL_STUDIOS], 'label' => 'Marvel Studios', 'published' => false],
            ['slug' => 'dc', 'companies' => self::TMDB_COMPANIES_DC, 'label' => 'DC', 'published' => false],
        ];

        return array_map(
            static fn (array $studio): array => [
                'key' => ThemeKind::Studio->value.'.'.$studio['slug'],
                'kind' => ThemeKind::Studio,
                'rule' => ThemeRules::studioRuleValue($studio['companies']),
                'negated' => false,
                'published' => $studio['published'],
                'collection' => null,
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
     * Décennies — `rule_value` porte l'année de DÉBUT. Les décennies 1940 à 1960,
     * ajoutées par L30-9, naissent non publiées (§ 12.3).
     *
     * @return list<ThemeDefinition>
     */
    private static function decadeThemes(): array
    {
        return array_map(
            static fn (int $start): array => [
                'key' => ThemeKind::Decade->value.'.'.$start,
                'kind' => ThemeKind::Decade,
                'rule' => (string) $start,
                'negated' => false,
                'published' => ! in_array($start, self::DECADES_BORN_UNPUBLISHED, true),
                'collection' => null,
                'labels' => [
                    Locale::English->value => $start.'s',
                    Locale::French->value => 'Années '.$start,
                ],
            ],
            self::DECADES,
        );
    }

    /**
     * Langue.
     *
     * « Cinéma international » est le seul thème négué du lot, et la raison d'être
     * de `rule_negated` : il s'écrit `original_language <> 'en'` sans introduire
     * ni liste de langues ni expression à analyser.
     *
     * « Animés japonais » est un thème de langue sur `ja`, complété par des
     * **retraits manuels** des films japonais en prise de vue réelle (spec 30
     * § 12.2) : il naît non publié, et sa publication suppose ces retraits faits.
     *
     * @return list<ThemeDefinition>
     */
    private static function languageThemes(): array
    {
        return [
            [
                'key' => ThemeKind::Language->value.'.international',
                'kind' => ThemeKind::Language,
                'rule' => Locale::English->value,
                'negated' => true,
                'published' => true,
                'collection' => null,
                'labels' => [
                    Locale::English->value => 'International cinema',
                    Locale::French->value => 'Cinéma international',
                ],
            ],
            [
                'key' => ThemeKind::Language->value.'.anime',
                'kind' => ThemeKind::Language,
                // Code de langue ORIGINALE d'un film (`movie.original_language`),
                // pas une locale d'interface : aucune constante de `Locale` ne le porte.
                'rule' => 'ja',
                'negated' => false,
                'published' => false,
                'collection' => null,
                'labels' => [
                    Locale::English->value => 'Japanese animation',
                    Locale::French->value => 'Animés japonais',
                ],
            ],
        ];
    }

    /**
     * Difficulté — projection de `movie.movie_difficulty`, qui ne porte aucun index :
     * `movie_theme` **est** sa forme requêtable (§ 3.7).
     *
     * @return list<ThemeDefinition>
     */
    private static function difficultyThemes(): array
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
            static fn (MovieDifficulty $difficulty): array => [
                'key' => ThemeKind::Difficulty->value.'.'.$difficulty->value,
                'kind' => ThemeKind::Difficulty,
                'rule' => $difficulty->value,
                'negated' => false,
                'published' => true,
                'collection' => null,
                'labels' => [
                    Locale::English->value => $labels[$difficulty->value]['en'],
                    Locale::French->value => $labels[$difficulty->value]['fr'],
                ],
            ],
            MovieDifficulty::cases(),
        );
    }

    /**
     * Sagas par défaut (S4 du 23/09, spec 30 § 12.4 ; douze depuis D43 du 01/10)
     * — `rule_value` porte un `collection.id` **local**, résolu à l'insertion
     * depuis `collection` (`rule` reste donc nul dans la définition).
     *
     * Chaque saga naît **non publiée**, avec sa collection TMDB et le `name`
     * littéral du tableau de la spec (nom anglais vérifié par le porteur le
     * 01/10). Le nom français de TMDB (« Iron Man - Saga ») n'est jamais un
     * libellé : les libellés sont écrits à la main.
     *
     * @return list<ThemeDefinition>
     */
    private static function sagaThemes(): array
    {
        /** @var list<array{slug: string, tmdb_id: int, name: string, en: string, fr: string}> $sagas */
        $sagas = [
            ['slug' => 'star-wars', 'tmdb_id' => 10, 'name' => 'Star Wars Collection', 'en' => 'Star Wars', 'fr' => 'Star Wars'],
            ['slug' => 'harry-potter', 'tmdb_id' => 1241, 'name' => 'Harry Potter Collection', 'en' => 'Harry Potter', 'fr' => 'Harry Potter'],
            ['slug' => 'lord-of-the-rings', 'tmdb_id' => 119, 'name' => 'The Lord of the Rings Collection', 'en' => 'The Lord of the Rings', 'fr' => 'Le Seigneur des Anneaux'],
            ['slug' => 'james-bond', 'tmdb_id' => 645, 'name' => 'James Bond Collection', 'en' => 'James Bond', 'fr' => 'James Bond'],
            ['slug' => 'indiana-jones', 'tmdb_id' => 84, 'name' => 'Indiana Jones Collection', 'en' => 'Indiana Jones', 'fr' => 'Indiana Jones'],
            ['slug' => 'back-to-the-future', 'tmdb_id' => 264, 'name' => 'Back to the Future Collection', 'en' => 'Back to the Future', 'fr' => 'Retour vers le futur'],
            ['slug' => 'jurassic-park', 'tmdb_id' => 328, 'name' => 'Jurassic Park Collection', 'en' => 'Jurassic Park', 'fr' => 'Jurassic Park'],
            ['slug' => 'toy-story', 'tmdb_id' => 10_194, 'name' => 'Toy Story Collection', 'en' => 'Toy Story', 'fr' => 'Toy Story'],
            ['slug' => 'pirates-of-the-caribbean', 'tmdb_id' => 295, 'name' => 'Pirates of the Caribbean Collection', 'en' => 'Pirates of the Caribbean', 'fr' => 'Pirates des Caraïbes'],
            ['slug' => 'shrek', 'tmdb_id' => 2150, 'name' => 'Shrek Collection', 'en' => 'Shrek', 'fr' => 'Shrek'],
            ['slug' => 'avatar', 'tmdb_id' => 87_096, 'name' => 'Avatar Collection', 'en' => 'Avatar', 'fr' => 'Avatar'],
            ['slug' => 'iron-man', 'tmdb_id' => 131_292, 'name' => 'Iron Man Collection', 'en' => 'Iron Man', 'fr' => 'Iron Man'],
        ];

        return array_map(
            static fn (array $saga): array => [
                'key' => ThemeKind::Saga->value.'.'.$saga['slug'],
                'kind' => ThemeKind::Saga,
                'rule' => null,
                'negated' => false,
                'published' => false,
                'collection' => ['tmdb_id' => $saga['tmdb_id'], 'name' => $saga['name']],
                'labels' => [Locale::English->value => $saga['en'], Locale::French->value => $saga['fr']],
            ],
            $sagas,
        );
    }
}
