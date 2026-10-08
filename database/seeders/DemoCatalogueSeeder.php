<?php

namespace Database\Seeders;

use App\Enums\AdminActionType;
use App\Enums\FrameLevel;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Enums\MovieDifficulty;
use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\ThemeEvaluator;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Database\Factories\FrameFactory;
use Database\Factories\MovieFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * **Le catalogue de démonstration du § 13.3** — une partie jouable sans clé TMDB
 * ni réseau.
 *
 * Le schéma conditionne une partie jouable à une chaîne de **sept faits
 * indépendants**, dont deux portent sur des octets réels sur disque. Ce seeder les
 * produit tous les sept ; `tests/Feature/Schema/DemoCatalogueChainTest.php` les
 * vérifie **un par un**, parce qu'un « 0 film » dans le lobby ne dit jamais lequel
 * a lâché.
 *
 * Pour lancer une partie de 10 manches, la garde exige `pool >= 10` et le tirage
 * matérialise `min(M + marge, pool)` œuvres, la marge étant
 * `PlatformLimits::drawSubstituteMargin()` (3 par défaut, soit 13 œuvres) : ce
 * seeder en produit {@see self::DEMO_MOVIE_COUNT}, **tous éligibles à `N = 5`**,
 * la borne haute du réglage de salon. Le vivier ne doit pas dépendre du `N` demandé : le preset
 * `hardcore` livré par le site pose `framesPerRound = 5`, et un catalogue qui ne
 * couvrirait que les niveaux 1, 3 et 5 rendrait « 0 film » à l'hôte qui le clique
 * — le scénario même du § 13.3, arrivé par la porte du nombre de niveaux au lieu
 * de celle des deux empreintes.
 *
 * Trois propriétés que ce fichier tient, et qui sont la raison d'être du contrat :
 *
 * 1. **Aucune image de jeu réelle, aucun extrait de base de production.** Les
 *    fichiers sont des aplats WebP produits par Imagick ({@see FrameFactory}),
 *    au format exact de la chaîne réelle (contrat C9) : dérivé de 1280 × 720
 *    paddé au multiple de 8 192 octets, master de 1920 de large, rectangle par
 *    défaut du plancher — les sondes de frame de la spec 20 § 5.9 passent donc
 *    sur ce catalogue. Ce qui est réel, ce sont les
 *    **octets** : `published_hash` est calculé sur eux, la `frame_review` `passed`
 *    cite cette empreinte exacte, et `frame.published_review_id` désigne cette
 *    ligne. Jamais deux empreintes tirées indépendamment.
 * 2. **`import_source = 'demo'` et `tmdb_id = NULL` sur tous.** Ils sont ainsi
 *    exclus, définitivement, de la resynchronisation TMDB et des deux
 *    statistiques de débit de curation.
 * 3. **Des films entrés par exception**, avec leur motif : les classiques
 *    antérieurs à 1970 (`exception_for_release_year`) et deux pour une langue hors
 *    filtre (`exception_for_language`). Sans eux, le filtre back-office « entrés
 *    par exception » et le comptage **par motif** n'ont aucune donnée, et le
 *    marquage devient silencieux — ce que la décision 11 interdit nommément.
 *
 * **Jamais hors `local`, `testing` et `staging`** : {@see self::assertSeedableEnvironment()}
 * lève. `staging` est la préproduction (spec 100 § 18, n° 5 de D66 du 07/10) :
 * elle reçoit ce catalogue, signé par {@see PreprodCurationAccountsSeeder}, et
 * jamais les comptes de démonstration, gardés par leur propre seeder. La garde est une **liste blanche** et non une liste noire, parce que
 * `composer setup` — le chemin d'installation documenté — copie `.env.example`,
 * qui pose `APP_ENV=local` : adosser la garde à la seule variable que la
 * procédure d'installation laisse à `local` livrerait `admin@tripleframes.test`
 * et son mot de passe connu sur toute machine servie installée par ce chemin.
 */
class DemoCatalogueSeeder extends Seeder
{
    /** Nombre de manches de la partie de référence du § 13.3. */
    public const int REFERENCE_ROUNDS = 10;

    /** Le `N` de référence du § 13.3 — jamais le seul auquel le catalogue est éligible. */
    public const int DEMO_FRAMES_PER_ROUND = 3;

    /**
     * Cardinalité du catalogue, déclarée pour que les bornes des tests cessent
     * d'être des nombres littéraux. Vérifiée contre `movieDefinitions()` au
     * démarrage : un film ajouté sans toucher à cette constante lève ici.
     */
    public const int DEMO_MOVIE_COUNT = 21;

    /**
     * Niveaux dupliqués sur le premier film, pour qu'au moins un film du
     * catalogue porte **plusieurs variantes d'un même niveau**.
     *
     * Sans cela, `level_i_variants` vaut 1 partout — c'est-à-dire le signal
     * back-office « variante unique » sur la totalité du catalogue — et le
     * mécanisme central de la spec 30, non vue par le salon → vue la moins
     * récemment par le salon → graine, n'a rien à départager : à chaque manche du
     * même film, le seul tirage possible sert la même image.
     *
     * @var list<FrameLevel>
     */
    private const array EXTRA_VARIANT_LEVELS = [FrameLevel::Level1, FrameLevel::Level5];

    public function run(): void
    {
        self::assertSeedableEnvironment();

        $definitions = $this->movieDefinitions();

        if (count($definitions) !== self::DEMO_MOVIE_COUNT) {
            throw new RuntimeException(
                'DemoCatalogueSeeder::DEMO_MOVIE_COUNT vaut '.self::DEMO_MOVIE_COUNT.' alors que le catalogue en '
                .'définit '.count($definitions).'. Les bornes des tests de chaîne sont écrites sur cette '
                .'constante : la laisser dériver leur ferait annoncer un compte faux.',
            );
        }

        // En préproduction, les auteurs sont les deux comptes inouvrables de
        // PreprodCurationAccountsSeeder, jamais les comptes de démonstration.
        [$curator, $admin] = app()->environment('staging')
            ? [PreprodCurationAccountsSeeder::account(UserRole::Curator), PreprodCurationAccountsSeeder::account(UserRole::Admin)]
            : [DemoAccountsSeeder::demoAccount(UserRole::Curator), DemoAccountsSeeder::demoAccount(UserRole::Admin)];

        // Idempotence par COMPLÉTUDE, jamais par existence. Chaque film est posé
        // dans sa propre transaction et aucune n'enveloppe la boucle : un seul film
        // commité suffirait à faire taire définitivement un seeder adossé à
        // `exists()`, sur un vivier de 6 films qui ne franchira jamais `pool >= 10`
        // — et le développeur n'aurait aucun moyen de s'en sortir sans vider les
        // tables à la main. Soit propre, soit un échec clair, jamais le silence.
        $present = Movie::query()->where('import_source', ImportSource::Demo)->count();

        if ($present === self::DEMO_MOVIE_COUNT) {
            return;
        }

        if ($present > 0) {
            throw new RuntimeException(
                "Catalogue de démonstration PARTIEL : {$present}/".self::DEMO_MOVIE_COUNT.' films. Un passage '
                .'précédent s’est interrompu. Purgez les films `demo` (ou rejouez `migrate:fresh`) avant de '
                .'relancer : un vivier incomplet passe toutes les gardes et bloque le lancement sans cause visible.',
            );
        }

        /** @var list<string> $sagas */
        $sagas = [];

        foreach ($definitions as $definition) {
            if ($definition['saga'] !== null && ! in_array($definition['saga'], $sagas, true)) {
                $sagas[] = $definition['saga'];
            }
        }

        $collections = $this->seedCollections($sagas);

        /** @var list<Movie> $movies */
        $movies = [];

        foreach ($definitions as $index => $definition) {
            $movies[] = $this->seedMovie($definition, $curator, $collections, $index === 0);
        }

        // La garde de cardinalité ci-dessus rend la liste non vide par construction :
        // un catalogue sans film aurait levé avant d'écrire une seule ligne.
        $first = $movies[0];

        $this->seedRejectedReview($first, $curator);
        $this->seedAdminJournal($admin, $curator, $first);
    }

    /**
     * La garde d'environnement, en **liste blanche** et non contournable par le
     * point d'entrée.
     *
     * Le catalogue de démonstration est du contenu de fixture : des titres posés à
     * la main et des images de bruit (les comptes au mot de passe connu ont leur
     * propre garde, plus stricte : {@see DemoAccountsSeeder}). Rien de
     * tout cela n'a sa place dans une base servie à des joueurs — et « pas
     * production » ne suffit pas à le garantir, `APP_ENV` valant `local` dans le
     * `.env.example` que `composer setup` recopie sur toute installation.
     */
    public static function assertSeedableEnvironment(): void
    {
        if (app()->environment(['local', 'testing', 'staging'])) {
            return;
        }

        throw new RuntimeException(
            'Le catalogue de démonstration ne tourne qu’en `local`, en `testing` ou en `staging` ; APP_ENV vaut ['
            .app()->environment().']. Il pose des images de fixture. '
            .'Seul '.PlatformDataSeeder::class.' est joué partout.',
        );
    }

    /**
     * Les sagas citées par le catalogue — « même saga », jamais « même œuvre ».
     *
     * Sans une seule ligne `collection`, la distinction que le § 3.3 désigne comme
     * ce qui rend la révélation compréhensible n'a aucune donnée de démonstration.
     *
     * Chaque film de saga est rattaché à la collection **livrée** par
     * {@see PlatformDataSeeder} (par son `tmdb_id`, prise ou insérée au `name`
     * littéral du seeder de plateforme), jamais à une collection de démonstration
     * sans `tmdb_id` : le thème de saga livré désigne cette ligne, et une saga de
     * démonstration détachée le laisserait vide (spec 30 L30-9, critique C16).
     * Les films, eux, restent `tmdb_id = NULL` : seule la collection est partagée.
     *
     * @param  list<string>  $sagas  clés des sagas livrées (`saga.toy-story`)
     * @return array<string, Collection>
     */
    private function seedCollections(array $sagas): array
    {
        /** @var array<string, Collection> $collections */
        $collections = [];

        foreach ($sagas as $saga) {
            $collections[$saga] = PlatformDataSeeder::deliveredSagaCollection($saga);
        }

        return $collections;
    }

    /**
     * Un film jouable complet, et les images qui le rendent tirable.
     *
     * L'ordre des states est l'ordre d'ajout, et il porte une subtilité :
     * `importException()` pose `import_source = paste`, donc `demo()` est appelée
     * **juste après** pour ramener la voie d'entrée à `demo` et le `tmdb_id` à
     * `NULL`. Un film de démonstration entré par exception porte bien les deux
     * faits à la fois — c'est exactement ce que le § 13.3 demande. Les motifs
     * passent en **un seul appel** : `importException()` est additive, mais un
     * second appel qui ne nommerait qu'un motif laisserait l'autre tel quel, et
     * c'est la lecture qui doit rester évidente.
     *
     * Les frames sont posées par `has()` : `Factory::store()` crée les enfants
     * AVANT les `afterCreating`, donc la `movie_projection` recalculée par
     * {@see MovieFactory::create()} compte des frames qui existent réellement.
     *
     * @param  array{
     *     title_original: string,
     *     title_original_latin: string|null,
     *     language: string,
     *     year: int,
     *     difficulty: MovieDifficulty,
     *     genres: list<int>,
     *     companies: list<int>,
     *     titles: array<string, string>,
     *     aliases: array<string, list<string>>,
     *     saga: string|null,
     *     certified: bool,
     *     exception_release_year: bool,
     *     exception_language: bool,
     * }  $definition
     * @param  array<string, Collection>  $collections
     */
    private function seedMovie(
        array $definition,
        User $curator,
        array $collections,
        bool $withExtraVariants,
    ): Movie {
        return DB::transaction(function () use (
            $definition,
            $curator,
            $collections,
            $withExtraVariants,
        ): Movie {
            $factory = Movie::factory();

            if ($definition['exception_release_year'] || $definition['exception_language']) {
                $factory = $factory->importException(
                    forLanguage: $definition['exception_language'],
                    forReleaseYear: $definition['exception_release_year'],
                );
            }

            $factory = $factory
                ->demo()
                ->state([
                    'title_original' => $definition['title_original'],
                    'title_original_latin' => $definition['title_original_latin'],
                    'original_language' => $definition['language'],
                    'release_year' => $definition['year'],
                    // `movie_difficulty` est la valeur EFFECTIVE, `_derived` celle du
                    // décile : sans correction manuelle, les deux coïncident.
                    'movie_difficulty' => $definition['difficulty'],
                    'movie_difficulty_derived' => $definition['difficulty'],
                    'movie_difficulty_override' => null,
                ])
                ->curatedBy($curator);

            if ($definition['saga'] !== null) {
                $factory = $factory->inCollection($collections[$definition['saga']]);
            }

            foreach ($definition['genres'] as $genre) {
                $factory = $factory->withGenre($genre);
            }

            foreach ($definition['companies'] as $company) {
                $factory = $factory->withCompany($company);
            }

            $movie = $factory
                ->playable(
                    self::DEMO_FRAMES_PER_ROUND,
                    // Les DEUX voies du § 3.8 vers `content_flag = clear` sont
                    // illustrées : la coche de curateur, qui horodate et signe, et —
                    // pour un film — une `movie_certification` non restrictive. Sans
                    // ce second cas, la base unique du filtre de contenu n'a aucune
                    // ligne et l'écran de back-office correspondant est vide sur un
                    // catalogue pourtant déclaré complet.
                    verifiedBy: $definition['certified'] ? null : $curator,
                    titles: $definition['titles'],
                    aliases: $definition['aliases'],
                )
                ->has($this->frames($curator, $withExtraVariants), 'frames')
                ->createOne();

            // Les appartenances aux thèmes sont ÉVALUÉES, jamais déclarées :
            // chaque film porte au moins une étiquette `movie_tmdb_tag`, et c'est
            // elle — pas une liste écrite dans ce fichier — qui décide de son
            // appartenance à un thème de genre ou de studio. L'évaluateur est
            // l'unique du dépôt (spec 30 § 13.1) : il évalue tous les thèmes,
            // publiés ou non, et passe `is_active` par
            // `MovieTheme::resolveIsActive()`.
            app(ThemeEvaluator::class)->syncMovie($movie);

            return $movie;
        });
    }

    /**
     * Les images du film : une par niveau de l'échelle **entière**, plus les
     * variantes supplémentaires du premier film.
     *
     * Les cinq niveaux, et non les trois de `N = 3` : le prédicat de vivier du
     * § 3.2 est `levels_count >= N`, donc un catalogue à trois niveaux rend un
     * vivier vide dès `N = 4` — y compris pour le preset `hardcore` livré par le
     * site. Ils sont lus dans {@see FrameLevelCoverage::nominal()} au `N`
     * maximal, qui couvre l'échelle entière : la répartition nominale n'est
     * écrite que là (spec 30 § 2).
     *
     * `published()` écrit RÉELLEMENT les deux dérivés sur le disque `frames`,
     * calcule `published_hash` sur les octets relus, crée la `frame_review`
     * `passed` qui cite cette empreinte, et pose `frame.published_review_id`. Le
     * curateur est passé explicitement : l'exigence 5 du § 13.3 veut une preuve
     * nominative, et `reviewer_name` / `reviewer_role` sont des instantanés pris à
     * l'instant de la revue, exclus de l'anonymisation.
     *
     * @return Factory<Frame>
     */
    private function frames(User $curator, bool $withExtraVariants): Factory
    {
        $levels = FrameLevelCoverage::nominal(RoomSettingsBounds::MAX_FRAMES_PER_ROUND);

        if ($withExtraVariants) {
            $levels = array_merge($levels, self::EXTRA_VARIANT_LEVELS);
        }

        return Frame::factory()
            ->published($curator)
            ->count(count($levels))
            ->sequence(...array_map(
                fn (FrameLevel $level): array => ['frame_level' => $level],
                $levels,
            ));
    }

    /**
     * Une image **refusée** par la grille d'exclusion, sur un film par ailleurs
     * complet.
     *
     * La frame reste en `draft` : elle porte ses deux dérivés sur disque, elle
     * n'entre dans aucun comptage de variante jouable, et elle donne à la moitié
     * rejetée de la grille — et à la file de curation qui l'affiche — la seule
     * donnée de démonstration qu'elle ait.
     */
    private function seedRejectedReview(Movie $movie, User $curator): void
    {
        DB::transaction(function () use ($movie, $curator): void {
            $frame = Frame::factory()
                ->withFiles()
                ->for($movie)
                ->createOne(['frame_level' => FrameLevel::Level2]);

            FrameReview::factory()
                ->forFrame($frame)
                ->by($curator)
                ->rejected()
                ->create();
        });
    }

    /**
     * Les lignes `admin_action` **permanentes** du catalogue de démonstration,
     * écrites par l'écrivain unique du journal, dans une transaction.
     *
     * L'élévation INITIALE de l'administrateur passe par la console, comme en
     * production (`admin:first-admin`) : acteur réservé `console`, `actor_id`
     * nul. Les suivantes sont signées de l'administrateur, dont le NOM RÉEL est
     * figé dans `actor_name` (D12 du 23/09, contrat C14 inv. 13).
     *
     * Une trace ne peut jamais être plus courte que l'état qu'elle justifie :
     * `retention_class` est dérivée de l'action par la garde `creating` du modèle,
     * jamais fournie ici. Les deux élévations de rôle remplacent à elles seules la
     * table `role_history` que le schéma n'a pas (A15), et la vérification de
     * contenu est le geste qui justifie, dans un dossier, que les films soient
     * entrés au vivier sans certification connue.
     */
    private function seedAdminJournal(User $admin, User $curator, Movie $movie): void
    {
        $journal = app(AdminJournal::class);

        DB::transaction(function () use ($journal, $admin, $curator, $movie): void {
            $journal->recordFromConsole(
                AdminActionType::RoleChanged,
                $admin->id,
                'Compte d’administration initial de l’instance.',
                UserRole::Player,
                UserRole::Admin,
            );

            $journal->record(
                $admin,
                AdminActionType::RoleChanged,
                $curator->id,
                'Ouverture des droits de curation sur le catalogue de démonstration.',
                roleBefore: UserRole::Player,
                roleAfter: UserRole::Curator,
            );

            $journal->record(
                $admin,
                AdminActionType::MovieContentVerified,
                $movie->id,
                'Contenu vérifié : aucune classification restrictive sur le catalogue de démonstration.',
            );
        });
    }

    /**
     * Les films du catalogue de démonstration.
     *
     * Des titres réels posés à la main, et **aucune donnée importée** : ni
     * `tmdb_id`, ni visuel, ni extrait de base. Les images sont produites par
     * Imagick au moment du seeding.
     *
     * Trois propriétés sont tenues par cette liste, et par elle seule :
     *
     * 1. **Les années couvrent toutes les décennies livrées** par
     *    {@see PlatformDataSeeder}, 1930 à 2020 sans trou, les étiquettes tous les
     *    thèmes de genre et de studio — Marvel et DC compris —, les langues le
     *    thème des animés, et au moins une saga livrée a ses films — un thème
     *    publié qui rendrait « 0 film » au lobby serait un thème que le site
     *    propose sans pouvoir le servir, et les thèmes nés non publiés par L30-9
     *    doivent pouvoir être publiés en développement sans être vides.
     * 2. **Deux films d'une même saga portent un titre à sous-titre partagé**
     *    (« Star Wars: … »). C'est la seule donnée qui exerce la nature
     *    `answer_key.key_kind = prefix`, la règle de collision du § 3.5 et
     *    `is_ambiguous` : sans elle, `prefixOf()` rend `null` partout et toute la
     *    machinerie des préfixes est sans fixture. Un troisième film à sous-titre
     *    **non partagé** (« Dune: … ») donne le cas non ambigu en regard.
     * 3. **Un film entre par la certification** plutôt que par la coche de
     *    curateur, pour que `movie_certification` — base unique du filtre de
     *    contenu — ne soit pas vide.
     *
     * @return list<array{
     *     title_original: string,
     *     title_original_latin: string|null,
     *     language: string,
     *     year: int,
     *     difficulty: MovieDifficulty,
     *     genres: list<int>,
     *     companies: list<int>,
     *     titles: array<string, string>,
     *     aliases: array<string, list<string>>,
     *     saga: string|null,
     *     certified: bool,
     *     exception_release_year: bool,
     *     exception_language: bool,
     * }>
     */
    private function movieDefinitions(): array
    {
        return [
            // Le cas nommé par le § 13.3 : l'âge d'or Disney antérieur à 1970, qui
            // n'entre au catalogue que par exception au filtre d'année.
            $this->movie(
                'Snow White and the Seven Dwarfs', 1937, MovieDifficulty::Medium,
                genres: [16, 10_751, 14], companies: [2],
                titles: ['en' => 'Snow White and the Seven Dwarfs', 'fr' => 'Blanche-Neige et les Sept Nains'],
                aliases: ['fr' => ['Blanche Neige'], 'en' => ['Snow White']],
                exceptionReleaseYear: true,
            ),
            // Deux films en langue hors filtre : le second motif d'exception, sans
            // lequel le comptage par motif du back-office n'a qu'une seule barre.
            $this->movie(
                '千と千尋の神隠し', 2001, MovieDifficulty::Medium,
                genres: [16, 14, 12], companies: [10_342],
                titles: ['en' => 'Spirited Away', 'fr' => 'Le Voyage de Chihiro'],
                aliases: ['fr' => ['Chihiro'], 'en' => ['Sen to Chihiro']],
                language: 'ja',
                latin: 'Sen to Chihiro no Kamikakushi',
                exceptionLanguage: true,
            ),
            $this->movie(
                'となりのトトロ', 1988, MovieDifficulty::Medium,
                genres: [16, 10_751, 14], companies: [10_342],
                titles: ['en' => 'My Neighbor Totoro', 'fr' => 'Mon voisin Totoro'],
                aliases: ['fr' => ['Totoro'], 'en' => ['Totoro']],
                language: 'ja',
                latin: 'Tonari no Totoro',
                exceptionLanguage: true,
            ),
            $this->movie(
                'The Lion King', 1994, MovieDifficulty::VeryEasy,
                genres: [16, 10_751, 18], companies: [2],
                titles: ['en' => 'The Lion King', 'fr' => 'Le Roi Lion'],
                aliases: ['fr' => ['Roi Lion']],
            ),
            $this->movie(
                'Toy Story', 1995, MovieDifficulty::VeryEasy,
                genres: [16, 10_751, 12], companies: [3],
                titles: ['en' => 'Toy Story', 'fr' => 'Toy Story'],
                aliases: ['fr' => ['Histoire de jouets']],
                // Rattaché à la collection LIVRÉE (tmdb_id 10194) : sans lui, la
                // saga par défaut `saga.toy-story` n'aurait aucun film (C16).
                saga: 'saga.toy-story',
            ),
            $this->movie(
                'Finding Nemo', 2003, MovieDifficulty::VeryEasy,
                genres: [16, 10_751, 12], companies: [3],
                titles: ['en' => 'Finding Nemo', 'fr' => 'Le Monde de Nemo'],
                aliases: ['fr' => ['Nemo']],
            ),
            $this->movie(
                'Ratatouille', 2007, MovieDifficulty::Easy,
                genres: [16, 10_751, 35], companies: [3],
                titles: ['en' => 'Ratatouille', 'fr' => 'Ratatouille'],
                // Tableau NON vide et sans variante : la fabrique ne retombe alors pas
                // sur son alias inventé, et le catalogue reste déterministe.
                aliases: ['fr' => []],
            ),
            $this->movie(
                'The Matrix', 1999, MovieDifficulty::Easy,
                genres: [28, 878], companies: [174],
                titles: ['en' => 'The Matrix', 'fr' => 'Matrix'],
                aliases: ['fr' => ['La Matrice']],
            ),
            $this->movie(
                'Jurassic Park', 1993, MovieDifficulty::Easy,
                genres: [12, 878, 53], companies: [33],
                titles: ['en' => 'Jurassic Park', 'fr' => 'Jurassic Park'],
                aliases: ['fr' => ['Le Parc jurassique']],
            ),
            $this->movie(
                'Back to the Future', 1985, MovieDifficulty::Easy,
                genres: [12, 35, 878], companies: [33],
                titles: ['en' => 'Back to the Future', 'fr' => 'Retour vers le futur'],
                aliases: ['fr' => ['Retour vers le futur 1']],
            ),
            $this->movie(
                'The Shining', 1980, MovieDifficulty::Hard,
                genres: [27, 53], companies: [174],
                titles: ['en' => 'The Shining', 'fr' => 'Shining'],
                aliases: ['fr' => ['L’Enfant lumière']],
            ),
            $this->movie(
                'Pulp Fiction', 1994, MovieDifficulty::Hard,
                genres: [53, 80], companies: [14],
                titles: ['en' => 'Pulp Fiction', 'fr' => 'Pulp Fiction'],
                aliases: ['fr' => []],
            ),
            $this->movie(
                'Inception', 2010, MovieDifficulty::VeryHard,
                genres: [28, 878, 12], companies: [174],
                titles: ['en' => 'Inception', 'fr' => 'Inception'],
                aliases: ['fr' => ['Origine']],
            ),
            // Les deux films à PRÉFIXE PARTAGÉ, et la décennie 1970 : `prefix:star
            // wars` naît des deux, donc `is_ambiguous` y est vrai — la seule donnée
            // du dépôt sur laquelle la règle de collision du § 3.5 s'exerce.
            $this->movie(
                'Star Wars: A New Hope', 1977, MovieDifficulty::Easy,
                genres: [12, 28, 878], companies: [1],
                titles: ['en' => 'Star Wars: A New Hope', 'fr' => 'Star Wars : Un nouvel espoir'],
                // Le second alias porte un séparateur : son sous-titre
                // `episode 4` ne naît d'aucun titre, et c'est la seule donnée
                // sur laquelle « un alias ne produit jamais de clé dérivée »
                // (décision 13, D23 du 23/09) s'exerce dans FAIT 9.
                aliases: ['fr' => ['La Guerre des étoiles', 'La Guerre des étoiles : Épisode IV']],
                saga: 'saga.star-wars',
            ),
            $this->movie(
                'Star Wars: The Empire Strikes Back', 1980, MovieDifficulty::Medium,
                genres: [12, 28, 878], companies: [1],
                titles: [
                    'en' => 'Star Wars: The Empire Strikes Back',
                    'fr' => 'Star Wars : L’Empire contre-attaque',
                ],
                aliases: ['fr' => ['L’Empire contre-attaque']],
                saga: 'saga.star-wars',
            ),
            // Les décennies 1940, 1950 et 1960, livrées non publiées par L30-9
            // (spec 30 § 12.3) : publiées en développement, elles ne doivent pas
            // être vides. Antérieurs à 1970, ces films n'entrent au catalogue que
            // par exception au filtre d'année, comme Blanche-Neige.
            $this->movie(
                'Pinocchio', 1940, MovieDifficulty::Easy,
                genres: [16, 10_751, 14], companies: [2],
                titles: ['en' => 'Pinocchio', 'fr' => 'Pinocchio'],
                aliases: ['fr' => []],
                exceptionReleaseYear: true,
            ),
            $this->movie(
                'Singin\' in the Rain', 1952, MovieDifficulty::Hard,
                genres: [35, 10_749], companies: [21],
                titles: ['en' => 'Singin\' in the Rain', 'fr' => 'Chantons sous la pluie'],
                aliases: ['en' => ['Singing in the Rain']],
                exceptionReleaseYear: true,
            ),
            $this->movie(
                'Psycho', 1960, MovieDifficulty::Medium,
                genres: [27, 53], companies: [4],
                titles: ['en' => 'Psycho', 'fr' => 'Psychose'],
                aliases: ['fr' => []],
                exceptionReleaseYear: true,
            ),
            // Marvel (société 420) et DC (128064, « DC Films »), livrés non publiés
            // par L30-9 : un film chacun, pour que ni l'un ni l'autre ne soit vide en
            // développement. Iron Man est aussi rattaché à sa collection livrée.
            $this->movie(
                'Iron Man', 2008, MovieDifficulty::Easy,
                genres: [28, 878, 12], companies: [420],
                titles: ['en' => 'Iron Man', 'fr' => 'Iron Man'],
                aliases: ['fr' => []],
                saga: 'saga.iron-man',
            ),
            $this->movie(
                'Wonder Woman', 2017, MovieDifficulty::Medium,
                genres: [28, 12, 14], companies: [128_064, 174],
                titles: ['en' => 'Wonder Woman', 'fr' => 'Wonder Woman'],
                aliases: ['fr' => []],
            ),
            // Le sous-titre NON partagé, la décennie 2020, et la voie de la
            // certification plutôt que celle de la coche de curateur.
            $this->movie(
                'Dune: Part Two', 2024, MovieDifficulty::Medium,
                genres: [878, 12], companies: [174],
                titles: ['en' => 'Dune: Part Two', 'fr' => 'Dune : Deuxième partie'],
                aliases: ['fr' => []],
                certified: true,
            ),
        ];
    }

    /**
     * Constructeur nommé d'une définition de film — il n'existe que pour que la
     * liste ci-dessus se lise, et que PHPStan voie la forme exacte du tableau.
     *
     * @param  list<int>  $genres
     * @param  list<int>  $companies
     * @param  array<string, string>  $titles
     * @param  array<string, list<string>>  $aliases
     * @return array{
     *     title_original: string,
     *     title_original_latin: string|null,
     *     language: string,
     *     year: int,
     *     difficulty: MovieDifficulty,
     *     genres: list<int>,
     *     companies: list<int>,
     *     titles: array<string, string>,
     *     aliases: array<string, list<string>>,
     *     saga: string|null,
     *     certified: bool,
     *     exception_release_year: bool,
     *     exception_language: bool,
     * }
     */
    private function movie(
        string $titleOriginal,
        int $year,
        MovieDifficulty $difficulty,
        array $genres,
        array $companies,
        array $titles,
        array $aliases,
        string $language = 'en',
        ?string $latin = null,
        ?string $saga = null,
        bool $certified = false,
        bool $exceptionReleaseYear = false,
        bool $exceptionLanguage = false,
    ): array {
        foreach (Locale::cases() as $locale) {
            if (! array_key_exists($locale->value, $titles)) {
                throw new RuntimeException(
                    "Le film de démonstration [{$titleOriginal}] n'a pas de titre en [{$locale->value}] : "
                    .'`movie_projection.title_locale_mask` en porterait la trace, et le repli d’affichage '
                    .'de la spec 05 deviendrait le chemin nominal du catalogue de démonstration.',
                );
            }
        }

        return [
            'title_original' => $titleOriginal,
            // A4 : la translittération latine d'un titre non latin vit sur `movie`,
            // jamais en alias — un alias n'est JAMAIS affiché, et celle-ci l'est.
            'title_original_latin' => $latin,
            'language' => $language,
            'year' => $year,
            'difficulty' => $difficulty,
            'genres' => $genres,
            'companies' => $companies,
            'titles' => $titles,
            'aliases' => $aliases,
            'saga' => $saga,
            'certified' => $certified,
            'exception_release_year' => $exceptionReleaseYear,
            'exception_language' => $exceptionLanguage,
        ];
    }
}
