<?php

use App\Enums\AdminActionRetention;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameProcessingState;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Enums\ReviewDecision;
use App\Enums\ThemeKind;
use App\Enums\TmdbTagKind;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieProjection;
use App\Models\MovieTheme;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\SettingPreset;
use App\Models\Theme;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use App\Support\Curation\ExclusionGrid;
use App\Support\Frames\FrameStoragePrefix;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Database\Factories\AnswerKeyFactory;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCatalogueSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Storage;

/**
 * **Exigence 4 du § 13.3** — le test qui prouve la chaîne des SEPT FAITS.
 *
 * Le schéma conditionne une partie jouable à sept faits indépendants, dont deux
 * portent sur des octets réels sur disque. Chacun est ici son propre test, nommé
 * par son numéro : c'est tout l'intérêt du fichier, parce qu'un « 0 film » dans
 * le lobby ne dit jamais **lequel** a lâché, et que chacun des sept lâche
 * silencieusement.
 *
 * Trois faits supplémentaires ferment les trois portes par lesquelles un
 * catalogue peut être déclaré complet tout en étant muet : les **étiquettes et
 * les thèmes** (FAIT 8), sans lesquels tout thème publié rend « 0 film » ; les
 * **clés de réponse** (FAIT 9), sans lesquelles chaque manche refuse toutes les
 * réponses ; et les **variantes**, sans lesquelles le tirage de la spec 30 n'a
 * rien à départager.
 *
 * **Périmètre, et il est volontairement étroit.** Le moteur de jeu n'existe pas :
 * aucun contrôleur, aucune route de jeu, aucun service de tirage, et la spec 60
 * n'est pas écrite. Ce test se joue donc **au niveau des données**, et c'est tout
 * ce qu'on peut prouver honnêtement aujourd'hui — que le catalogue de
 * démonstration seedé satisfait réellement la chaîne. Il ne lance aucune partie,
 * n'appelle aucune route et ne simule aucun moteur.
 *
 * Les requêtes de vivier et de tirage sont **écrites dans ce fichier**, avec leur
 * date de péremption : elles seront remplacées par le service de
 * `docs/specs/30-themes-vivier-et-tirage-des-variantes.md`, qui en est le
 * propriétaire. Les dupliquer ici est un choix assumé — une chaîne de fixtures ne
 * peut pas dépendre d'un service qui n'est pas encore écrit, et ces deux requêtes
 * sont exactement celles du § 12.
 */

/**
 * Le VIVIER pour un `N` donné — prédicat du § 12, ligne « Vivier », sans thème.
 *
 * Les trois conditions ensemble, et jamais deux sur trois : `availability`,
 * `content_flag` (arbitrage A3 : le filtre de contenu n'est contournable par
 * aucune voie) et `levels_count >= N`. `levels_mask & 21 = 21` redouble la
 * couverture des niveaux 1, 3 et 5 en arithmétique entière — portable, là où
 * `BIT_COUNT` existe en MySQL et pas en SQLite.
 *
 * **À REMPLACER** par le service de la spec 30 dès qu'il existe.
 *
 * @return Builder<Movie>
 */
function demoPool(int $framesPerRound): Builder
{
    return Movie::query()
        ->join('movie_projection as p', 'p.movie_id', '=', 'movie.id')
        ->where('movie.availability', ContentAvailability::Published->value)
        ->where('movie.content_flag', ContentFlag::Clear->value)
        ->where('p.levels_count', '>=', $framesPerRound)
        ->whereRaw('(p.levels_mask & 21) = 21')
        ->select('movie.*');
}

/**
 * Les variantes SERVABLES d'un film — prédicat unique du § 3.2, les trois
 * conditions ensemble. Un prédicat de comptage plus permissif que le prédicat de
 * service fabrique des films qui passent la garde de vivier et cassent une manche.
 *
 * @return EloquentCollection<int, Frame>
 */
function servableFramesOf(Movie $movie): EloquentCollection
{
    return Frame::query()
        ->where('movie_id', $movie->id)
        ->where('availability', ContentAvailability::Published->value)
        ->where('processing_state', FrameProcessingState::Ready->value)
        ->whereNotNull('game_path')
        ->get();
}

beforeEach(function (): void {
    // Un fichier ne participe à aucune transaction : `RefreshDatabase` annule la
    // ligne, jamais les octets. Sans ce `fake`, le seeder écrirait ses fichiers
    // dans la racine réelle du disque `frames` à chaque test, et ils y resteraient.
    Storage::fake(FrameStoragePrefix::DISK);

    $this->seed(DatabaseSeeder::class);
});

it('FAIT 0 — les données du site existent : quatre presets et des thèmes publiés dans chaque locale', function () {
    // Ce fait précède les sept : sans preset ni thème, aucun salon ne peut être
    // créé, et la question « combien de films ? » ne se pose même pas.
    $this->assertSame(
        4,
        SettingPreset::query()->count(),
        'FAIT 0 — les quatre presets du site sont absents : aucun salon ne peut être créé.',
    );

    /** @var EloquentCollection<int, Theme> $themes */
    $themes = Theme::query()->where('is_published', true)->with('labels')->get();

    $this->assertGreaterThan(
        0,
        $themes->count(),
        'FAIT 0 — aucun thème publié : le sélecteur de thèmes du lobby est vide.',
    );

    foreach ($themes as $theme) {
        $this->assertTrue(
            $theme->hasEveryLocaleLabel(),
            "FAIT 0 — le thème publié [{$theme->key}] n'a pas de libellé dans chaque locale activée : "
            .'le joueur verrait son identifiant technique.',
        );
    }
});

it('FAIT 1 — le vivier compte au moins 10 films éligibles, à CHAQUE N des bornes de salon', function () {
    // La boucle est le fait, pas le confort : le prédicat de vivier du § 3.2 est
    // `levels_count >= N`, donc un catalogue qui ne couvrirait que les niveaux 1, 3
    // et 5 rendrait un vivier VIDE dès N=4 — y compris pour le preset `hardcore`
    // livré par le site, qui pose framesPerRound = 5. Mesurer le seul N=3, c'est
    // déclarer complet un catalogue qui affiche « 0 film » au premier clic d'hôte.
    $range = range(
        RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
        RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
    );

    foreach ($range as $framesPerRound) {
        $pool = demoPool($framesPerRound)->count();

        $this->assertGreaterThanOrEqual(
            DemoCatalogueSeeder::REFERENCE_ROUNDS,
            $pool,
            "FAIT 1 — le vivier ne compte que {$pool} films éligibles à N={$framesPerRound}, alors que la garde "
            .'de lancement d’une partie de '.DemoCatalogueSeeder::REFERENCE_ROUNDS.' manches exige pool >= '
            .DemoCatalogueSeeder::REFERENCE_ROUNDS.'. Causes possibles, dans l’ordre : movie.availability, '
            .'movie.content_flag, ou movie_projection.levels_count — la projection compte les frames SERVABLES, '
            .'donc un film sans octets sur disque n’y figure pas.',
        );
    }
});

it('FAIT 2 — le vivier dépasse la marge de tirage : min(M + 3, |pool|) matérialise bien 13 films', function () {
    $pool = demoPool(DemoCatalogueSeeder::DEMO_FRAMES_PER_ROUND)->count();

    $this->assertGreaterThanOrEqual(
        DemoCatalogueSeeder::REFERENCE_ROUNDS + DemoCatalogueSeeder::DRAW_MARGIN,
        $pool,
        "FAIT 2 — le vivier de {$pool} films est sous la marge de tirage de "
        .(DemoCatalogueSeeder::REFERENCE_ROUNDS + DemoCatalogueSeeder::DRAW_MARGIN).' : le tirage ne '
        .'matérialiserait pas ses films de remplacement, et la partie en manquerait dès le premier incident '
        .'de manche.',
    );
});

it('FAIT 3 — chaque film tirable couvre les niveaux attendus par des frames servables', function () {
    $range = range(
        RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
        RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
    );

    foreach ($range as $framesPerRound) {
        $expected = FrameLevelCoverage::nominal($framesPerRound);

        /** @var EloquentCollection<int, Movie> $drawn */
        $drawn = demoPool($framesPerRound)->get();

        foreach ($drawn as $movie) {
            $levels = servableFramesOf($movie)
                ->map(fn (Frame $frame): int => $frame->frame_level->value)
                ->unique()
                ->all();

            foreach ($expected as $level) {
                $this->assertContains(
                    $level->value,
                    $levels,
                    "FAIT 3 — le film [{$movie->title_original}] (#{$movie->id}) est dans le vivier à "
                    ."N={$framesPerRound} sans aucune variante SERVABLE de niveau {$level->value}. Le palier "
                    .'correspondant n’aurait aucune image à servir, et la manche partirait sur le chemin de '
                    .'substitution dès son ouverture.',
                );
            }
        }
    }
});

it('FAIT 4 — chaque frame servable pointe un fichier réellement présent sur le disque frames', function () {
    $disk = Storage::disk(FrameStoragePrefix::DISK);

    /** @var EloquentCollection<int, Movie> $drawn */
    $drawn = demoPool(DemoCatalogueSeeder::DEMO_FRAMES_PER_ROUND)->get();

    $checked = 0;

    foreach ($drawn as $movie) {
        foreach (servableFramesOf($movie) as $frame) {
            $game = $frame->game_path;
            $master = $frame->master_path;

            $this->assertNotNull(
                $game,
                "FAIT 4 — la frame #{$frame->id} est servable sans `game_path` : le prédicat de service et le "
                .'prédicat de comptage ont divergé.',
            );

            // Le prédicat écrit dans ce fichier prouve que des octets existent ; il ne
            // prouve rien de la méthode que le moteur appellera au moment de signer
            // l'URL. Les deux doivent rendre le même verdict sur la même ligne, sans
            // quoi le catalogue reste déclaré conforme pendant que la première manche
            // sert une image absente.
            $this->assertTrue(
                $frame->isServable(),
                "FAIT 4 — la frame #{$frame->id} passe le prédicat de comptage de ce test mais pas "
                .'`Frame::isServable()` : le prédicat de comptage et le prédicat de service ont divergé.',
            );

            $this->assertTrue(
                $disk->exists((string) $game),
                "FAIT 4 — la frame #{$frame->id} du film [{$movie->title_original}] désigne l'objet [{$game}], "
                .'qui n’existe pas sur le disque `frames`. `isServable()` est vrai en base, le moteur '
                .'n’emprunte donc pas le chemin de substitution, et l’image reste cassée toute la manche.',
            );

            // Le master n'est JAMAIS servi (la route refuse structurellement tout
            // chemin hors `game/`), mais il doit exister : sans lui, aucun re-cadrage
            // n'est possible et la frame est irrécupérable.
            $this->assertNotNull($master, "FAIT 4 — la frame #{$frame->id} n'a pas de `master_path`.");
            $this->assertTrue(
                $disk->exists((string) $master),
                "FAIT 4 — la source de re-cadrage [{$master}] de la frame #{$frame->id} est absente du disque.",
            );

            $checked++;
        }
    }

    // La borne est CALCULÉE, jamais littérale : un film ajouté au catalogue sans
    // toucher au test le laissait vert tout en faisant mentir son message d'échec.
    $expected = DemoCatalogueSeeder::DEMO_MOVIE_COUNT * RoomSettingsBounds::MAX_FRAMES_PER_ROUND;

    $this->assertGreaterThanOrEqual(
        $expected,
        $checked,
        "FAIT 4 — seules {$checked} frames servables ont été vérifiées : le catalogue de démonstration en "
        ."attend au moins {$expected} (".DemoCatalogueSeeder::DEMO_MOVIE_COUNT.' films × '
        .RoomSettingsBounds::MAX_FRAMES_PER_ROUND.' niveaux).',
    );
});

it('FAIT 5 — le hash du fichier présent correspond à published_hash ET à reviewed_hash', function () {
    $disk = Storage::disk(FrameStoragePrefix::DISK);

    /** @var EloquentCollection<int, Movie> $drawn */
    $drawn = demoPool(DemoCatalogueSeeder::DEMO_FRAMES_PER_ROUND)->get();

    foreach ($drawn as $movie) {
        foreach (servableFramesOf($movie) as $frame) {
            $bytes = (string) $disk->get((string) $frame->game_path);
            $hash = hash('sha256', $bytes);

            $this->assertSame(
                $hash,
                $frame->published_hash,
                "FAIT 5 — `published_hash` de la frame #{$frame->id} ne correspond pas aux octets réellement "
                .'présents sur le disque : l’empreinte a été tirée indépendamment des octets.',
            );

            $review = $frame->publishedReview;

            $this->assertNotNull(
                $review,
                "FAIT 5 — la frame #{$frame->id} est publiée sans `published_review_id` : la garde de "
                .'publication n’a aucune preuve à interroger (sonde du § 12 à zéro).',
            );

            if ($review === null) {
                continue;
            }

            $this->assertSame(
                ReviewDecision::Passed,
                $review->decision,
                "FAIT 5 — la revue #{$review->id} de la frame #{$frame->id} n'est pas `passed`.",
            );

            $this->assertSame(
                $hash,
                $review->reviewed_hash,
                "FAIT 5 — la revue #{$review->id} cite l'empreinte [{$review->reviewed_hash}] alors que les "
                ."octets servis valent [{$hash}]. Avec deux hashs tirés indépendamment, aucun film n'est "
                .'publiable et le lobby affiche « 0 film » sur un catalogue pourtant complet.',
            );

            $this->assertSame(
                ExclusionGrid::CURRENT_VERSION,
                $review->grid_version,
                "FAIT 5 — la revue #{$review->id} cite la grille d'exclusion v{$review->grid_version} au lieu "
                .'de la version courante : la frame entre dans la file « images à re-revoir », pas en jeu.',
            );
        }
    }
});

it('FAIT 6 — chaque film porte une movie_projection au profil de titre COMPLET', function () {
    /** @var EloquentCollection<int, Movie> $movies */
    $movies = Movie::query()->with('projection')->get();

    // Le profil complet : un bit par locale activée. C'est la propriété utile, et
    // non « le masque est non nul » — `movie_projection_qcm_idx` tire les trois
    // leurres par ÉGALITÉ sur (title_mask_version, title_locale_mask), donc un film
    // au profil isolé ne trouve aucun leurre et fait basculer les quatre
    // propositions sur `title_original`, la dégradation de la spec 05 devenue le
    // chemin nominal.
    $full = array_reduce(
        Locale::cases(),
        fn (int $carry, Locale $locale): int => $carry | $locale->maskBit(),
        0,
    );

    foreach ($movies as $movie) {
        $projection = $movie->projection;

        $this->assertNotNull(
            $projection,
            "FAIT 6 — le film [{$movie->title_original}] (#{$movie->id}) n'a AUCUNE ligne `movie_projection` : "
            .'il est invisible du vivier quelles que soient ses images, la requête de lobby faisant une '
            .'jointure et non un `LEFT JOIN`.',
        );

        if ($projection === null) {
            continue;
        }

        $this->assertSame(
            Locale::MASK_VERSION,
            $projection->title_mask_version,
            "FAIT 6 — la projection du film #{$movie->id} porte `title_mask_version` "
            ."= {$projection->title_mask_version} au lieu de ".Locale::MASK_VERSION.' : tant que '
            .'`catalog:reproject` n’a pas tourné, aucune ligne n’apparie la version courante.',
        );

        $this->assertSame(
            $full,
            $projection->title_locale_mask,
            "FAIT 6 — le film [{$movie->title_original}] (#{$movie->id}) porte le profil de titre "
            ."{$projection->title_locale_mask} au lieu du profil complet {$full} : il lui manque un titre dans "
            .'une locale activée, et le repli d’affichage deviendrait le chemin nominal du catalogue.',
        );
    }

    $this->assertGreaterThanOrEqual(
        4,
        MovieProjection::query()
            ->where('title_mask_version', Locale::MASK_VERSION)
            ->where('title_locale_mask', $full)
            ->count(),
        'FAIT 6 — moins de 4 films partagent un profil de titre identique : à `T_N`, le QCM ne trouve pas ses '
        .'trois leurres et les quatre propositions basculent ensemble sur `title_original`.',
    );
});

it('FAIT 7 — les trois rôles, la signature du curateur, le journal de l’admin et le film d’exception', function () {
    foreach (UserRole::cases() as $role) {
        $this->assertTrue(
            User::query()->where('role', $role)->exists(),
            "FAIT 7 — aucun compte de démonstration de rôle [{$role->value}] : la propriété absolue d'une "
            .'`saved_config` face à un admin, l’exemption de purge des comptes privilégiés et la file '
            .'« rôle privilégié sans 2FA » ne sont testables par aucun test.',
        );
    }

    $curator = User::query()->where('role', UserRole::Curator)->firstOrFail();
    $admin = User::query()->where('role', UserRole::Admin)->firstOrFail();

    // PAR FILM, et non en agrégat : une assertion globale reste verte quand un film
    // sur seize perd sa coche. Le § 13.3 et le § 3.8 ne connaissent que deux voies
    // vers `content_flag = clear` — la coche de curateur, qui horodate et signe, ou
    // une `movie_certification` non restrictive. Un film `clear` sans l'une des
    // deux est une ligne qu'aucun chemin applicatif ne peut produire.
    /** @var EloquentCollection<int, Movie> $pool */
    $pool = demoPool(DemoCatalogueSeeder::DEMO_FRAMES_PER_ROUND)->get();

    foreach ($pool as $movie) {
        $verified = $movie->content_verified_by_id !== null && $movie->content_verified_at !== null;

        $certified = MovieCertification::query()
            ->where('movie_id', $movie->id)
            ->where('is_restrictive', false)
            ->exists();

        $this->assertTrue(
            $verified || $certified,
            "FAIT 7 — le film [{$movie->title_original}] (#{$movie->id}) est `clear` sans coche de curateur "
            .'(`content_verified_by_id` + `content_verified_at`) ni `movie_certification` non restrictive : '
            .'c’est un verdict de filtre de contenu sans auteur ni preuve.',
        );
    }

    $this->assertGreaterThan(
        0,
        MovieCertification::query()->count(),
        'FAIT 7 — `movie_certification` est vide : la base unique du filtre de contenu (§ 3.8) n’a aucune '
        .'donnée de démonstration, et l’écran de back-office correspondant est muet sur un catalogue pourtant '
        .'déclaré complet.',
    );

    // Les trois colonnes que le § 13.3 nomme explicitement. Remplacer
    // `Frame::factory()->published($curator)` par `->published()` laisse tous les
    // autres faits verts et vide les preuves de revue de toute valeur opposable :
    // `reviewer_id` nul et un nom inventé par la fabrique.
    /** @var EloquentCollection<int, FrameReview> $reviews */
    $reviews = FrameReview::query()->get();

    $this->assertGreaterThan(0, $reviews->count(), 'FAIT 7 — aucune `frame_review` : aucune preuve de revue.');

    foreach ($reviews as $review) {
        $this->assertSame(
            $curator->id,
            $review->reviewer_id,
            "FAIT 7 — la revue #{$review->id} ne porte aucun `reviewer_id` de curateur seedé : la preuve "
            .'n’est opposable à personne.',
        );

        // Le NOM RÉEL du curateur, jamais son pseudo de compte (D12 du 23/09).
        $this->assertSame(
            $curator->real_name,
            $review->reviewer_name,
            "FAIT 7 — la revue #{$review->id} cite [{$review->reviewer_name}] : l'instantané du nom réel, exclu "
            .'de l’anonymisation, ne correspond pas au curateur qui a exercé la grille.',
        );

        $this->assertSame(
            UserRole::Curator,
            $review->reviewer_role,
            "FAIT 7 — la revue #{$review->id} fige le rôle [{$review->reviewer_role->value}] : la preuve cesse "
            .'d’être autoportante.',
        );
    }

    // La moitié REJETÉE de la grille d'exclusion : sans une seule décision
    // `rejected`, la file de curation correspondante n'a aucune donnée.
    $this->assertTrue(
        FrameReview::query()->where('decision', ReviewDecision::Rejected)->exists(),
        'FAIT 7 — aucune `frame_review` `rejected` : la moitié rejetée de la grille d’exclusion n’a aucune '
        .'donnée de démonstration.',
    );

    // Le journal permanent de l'administrateur remplace à lui seul la table
    // `role_history` que le schéma n'a pas (A15) : le supprimer ne faisait échouer
    // aucun test, et la table se vidait en silence.
    $this->assertGreaterThan(
        0,
        AdminAction::query()
            ->where('actor_id', $admin->id)
            ->where('retention_class', AdminActionRetention::Permanent)
            ->count(),
        'FAIT 7 — aucune ligne `admin_action` permanente signée de l’administrateur : les deux élévations de '
        .'rôle et la vérification de contenu n’ont aucune trace, et la table qui remplace `role_history` (A15) '
        .'est vide.',
    );

    $this->assertTrue(
        Movie::query()
            ->where('is_import_exception', true)
            ->where('exception_for_release_year', true)
            ->exists(),
        'FAIT 7 — aucun film `is_import_exception` avec le motif `exception_for_release_year` : le filtre '
        .'back-office « entrés par exception » et le comptage PAR MOTIF n’ont aucune donnée, et le marquage '
        .'devient silencieux — ce que la décision 11 interdit nommément.',
    );

    $this->assertTrue(
        Movie::query()
            ->where('is_import_exception', true)
            ->where('exception_for_language', true)
            ->exists(),
        'FAIT 7 — aucun film entré par exception au motif de langue : le comptage PAR MOTIF n’a qu’une seule '
        .'barre, et une exception à double motif passerait inaperçue.',
    );

    // Sans cette dernière assertion, un catalogue de démonstration importé depuis
    // TMDB passerait tous les faits ci-dessus tout en étant rejoué à chaque
    // resynchronisation et compté dans les statistiques de débit de curation.
    $this->assertSame(
        0,
        Movie::query()
            ->where(function (Builder $query): void {
                $query->where('import_source', '!=', ImportSource::Demo->value)
                    ->orWhereNotNull('tmdb_id');
            })
            ->count(),
        'FAIT 7 — un film du catalogue de démonstration n’est pas en `import_source = demo` / `tmdb_id = NULL` : '
        .'il entrerait dans la resynchronisation TMDB et dans les deux statistiques de débit de curation.',
    );
});

it('FAIT 8 — chaque film porte ses étiquettes TMDB, et chaque thème publié a des films à proposer', function () {
    /** @var EloquentCollection<int, Movie> $movies */
    $movies = Movie::query()->get();

    foreach ($movies as $movie) {
        // L'exigence 2 du § 13.3, et le repli silencieux qu'elle ferme :
        // `composeCatalogue()` invente une étiquette de GENRE au hasard quand le film
        // n'en a aucune, et jamais d'étiquette de société. Un film qui perdrait ses
        // appels `withCompany()` resterait donc « étiqueté » — mais Disney, Pixar et
        // Ghibli, les trois thèmes nommés au produit, n'auraient plus aucun film.
        foreach ([TmdbTagKind::Genre, TmdbTagKind::Company] as $kind) {
            $this->assertGreaterThan(
                0,
                MovieTmdbTag::query()
                    ->where('movie_id', $movie->id)
                    ->where('tag_kind', $kind)
                    ->count(),
                "FAIT 8 — le film [{$movie->title_original}] (#{$movie->id}) ne porte aucune étiquette TMDB de "
                ."nature [{$kind->value}] : la règle automatique des thèmes correspondants n'a rien à évaluer "
                .'sur lui (§ 3.6).',
            );
        }
    }

    /** @var EloquentCollection<int, Theme> $themes */
    $themes = Theme::query()->where('is_published', true)->get();

    foreach ($themes as $theme) {
        // Un thème de saga se publie en back-office sur un `collection.id` local :
        // le site n'en livre aucun, et aucun film de démonstration n'a à en porter.
        if ($theme->theme_kind === ThemeKind::Saga) {
            continue;
        }

        $this->assertGreaterThan(
            0,
            MovieTheme::query()
                ->where('theme_id', $theme->id)
                ->where('is_active', true)
                ->count(),
            "FAIT 8 — le thème publié [{$theme->key}] n'a aucun film actif à proposer : le lobby l'affiche et "
            .'rend « 0 film » à l’hôte qui le sélectionne.',
        );
    }
});

it('FAIT 9 — chaque chaîne acceptée du catalogue a sa clé, préfixes et ambiguïté compris', function () {
    /** @var EloquentCollection<int, Movie> $movies */
    $movies = Movie::query()->get();

    foreach ($movies as $movie) {
        /** @var list<string> $sources */
        $sources = [$movie->title_original];

        if ($movie->title_original_latin !== null) {
            $sources[] = $movie->title_original_latin;
        }

        foreach (MovieTitle::query()->where('movie_id', $movie->id)->get() as $title) {
            $sources[] = $title->title;
        }

        foreach (Alias::query()->where('movie_id', $movie->id)->get() as $alias) {
            $sources[] = $alias->alias;
        }

        foreach ($sources as $source) {
            // Vérification INDÉPENDANTE du projecteur : la forme attendue est
            // recalculée ici depuis le texte affiché. Deux normaliseurs distincts, et
            // aucune réponse ne valide jamais sur un catalogue pourtant complet — le
            // pendant exact du piège des deux empreintes tirées indépendamment.
            $normalized = AnswerKeyFactory::normalize($source);

            if ($normalized === '') {
                continue;
            }

            $this->assertTrue(
                AnswerKey::query()
                    ->where('movie_id', $movie->id)
                    ->where('normalized', $normalized)
                    ->exists(),
                "FAIT 9 — la chaîne [{$source}] du film [{$movie->title_original}] (#{$movie->id}) se normalise "
                ."en [{$normalized}], qui n'est aucune de ses `answer_key` : cette réponse serait refusée "
                .'pendant toute la manche.',
            );
        }

        $this->assertGreaterThan(
            0,
            AnswerKey::query()->where('movie_id', $movie->id)->count(),
            "FAIT 9 — le film [{$movie->title_original}] (#{$movie->id}) n'a AUCUNE clé de réponse : sa manche "
            .'refuserait toutes les réponses, lobby parfait et sept faits verts compris.',
        );
    }

    // La nature `prefix` est la SEULE soumise à la règle de collision du § 3.5, et
    // la seule que `prefixOf()` peut cesser de produire sans qu'aucun autre fait ne
    // bouge : un catalogue sans un seul titre à sous-titre ne l'exerce jamais.
    $this->assertGreaterThan(
        0,
        AnswerKey::query()->where('key_kind', AnswerKeyKind::Prefix)->count(),
        'FAIT 9 — aucune clé de nature `prefix` : la branche des préfixes dérivés des titres n’est exercée par '
        .'aucune ligne, et un bug y resterait invisible jusqu’au premier film à sous-titre importé de TMDB.',
    );

    $this->assertGreaterThan(
        0,
        AnswerKey::query()
            ->where('key_kind', AnswerKeyKind::Prefix)
            ->where('is_ambiguous', true)
            ->count(),
        'FAIT 9 — aucun préfixe ambigu : la règle de collision, l’avertissement nominatif du back-office et '
        .'`guess.prefix_was_ambiguous` n’ont aucune donnée.',
    );

    $this->assertGreaterThan(
        0,
        AnswerKey::query()
            ->where('key_kind', AnswerKeyKind::Prefix)
            ->where('is_ambiguous', false)
            ->count(),
        'FAIT 9 — tous les préfixes sont ambigus : le recompte du § 3.5 pose peut-être le drapeau sans compter.',
    );

    // Un alias ne produit JAMAIS de clé de nature `prefix` (décision 13).
    foreach (Alias::query()->get() as $alias) {
        $prefix = AnswerKeyFactory::prefixOf($alias->alias);

        if ($prefix === null) {
            continue;
        }

        $this->assertFalse(
            AnswerKey::query()
                ->where('movie_id', $alias->movie_id)
                ->where('key_kind', AnswerKeyKind::Prefix)
                ->where('normalized', $prefix)
                ->exists(),
            "FAIT 9 — l'alias [{$alias->alias}] a produit une clé `prefix` : la décision 13 réserve les "
            .'préfixes aux seuls titres.',
        );
    }
});

it('VARIANTES — au moins un film porte plusieurs variantes d’un même niveau', function () {
    // `level_i_variants = 1` partout, c'est le signal back-office « variante
    // unique » sur la TOTALITÉ du catalogue : le mécanisme central de la spec 30 —
    // préférence non vue par le salon, repli non vue par le joueur, tirage jamais
    // bloqué — n'a alors aucune donnée sur laquelle s'écrire ni se tester, puisque
    // le seul tirage possible sert la même image à chaque manche du même film.
    $this->assertGreaterThan(
        0,
        MovieProjection::query()->where('level_1_variants', '>', 1)->count(),
        'VARIANTES — aucun film n’a plus d’une variante de niveau 1 : le tirage de variante n’a rien à '
        .'départager, et la cible produit de 8 images par film n’est illustrée nulle part.',
    );

    $this->assertGreaterThan(
        DemoCatalogueSeeder::DEMO_MOVIE_COUNT * RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        (int) MovieProjection::query()->sum('variants_total'),
        'VARIANTES — `variants_total` ne dépasse pas une variante par niveau et par film.',
    );
});

it('DISQUE — le disque `frames` est privé, non servi, et hors du répertoire public', function () {
    // `Storage::fake()` ne reporte QUE la clé `throw` et remplace la racine :
    // `serve` et `visibility` sont perdues, donc aucun test qui passe par un disque
    // simulé ne regarde jamais ces deux clés — sur lesquelles reposent pourtant
    // toute la prémisse anti-triche et la capacité de retrait juridique.
    $this->assertSame('local', config('filesystems.disks.frames.driver'));

    $this->assertFalse(
        config('filesystems.disks.frames.serve'),
        'DISQUE — `serve` est vrai : la route native de `FilesystemServiceProvider` est enregistrée et rend '
        .'tout le disque atteignable par URL signée générique, en contournant le prédicat de service en trois '
        .'parties du § 4.1.',
    );

    $this->assertSame(
        'private',
        config('filesystems.disks.frames.visibility'),
        'DISQUE — le disque des images de jeu n’est pas privé.',
    );

    $this->assertTrue(
        config('filesystems.disks.frames.throw'),
        'DISQUE — `throw` est faux : une écriture ratée passerait pour un succès, et la ligne `frame` '
        .'nommerait un objet absent.',
    );

    $root = (string) config('filesystems.disks.frames.root');

    $this->assertFalse(
        str_starts_with($root, public_path()),
        "DISQUE — la racine [{$root}] est sous `public/` : les images de jeu sont servies en clair.",
    );

    $this->assertArrayNotHasKey(
        $root,
        (array) config('filesystems.links'),
        'DISQUE — la racine du disque `frames` est la cible d’un lien de `storage:link`.',
    );
});

it('EXIGENCE 2 — Movie::factory()->create() produit TOUJOURS une ligne movie_projection', function () {
    // Le test nommé par le § 13.3, exigence 2, et il porte sur le défaut de la
    // factory, jamais sur `playable()` : c'est le film seedé par un test futur
    // qui disparaîtrait du vivier, la requête du § 12 joignant `movie_projection`
    // et ne faisant pas de `LEFT JOIN`. Un film sans projection n'est pas un film
    // mal noté, c'est un film invisible — et il l'est en silence.
    $movie = Movie::factory()->create();

    $projection = MovieProjection::query()->find($movie->id);

    $this->assertNotNull(
        $projection,
        'EXIGENCE 2 — un `Movie::factory()->create()` nu n’écrit pas sa ligne `movie_projection` : '
        .'tout film composé hors de `playable()` serait invisible du vivier.',
    );

    if ($projection === null) {
        return;
    }

    $this->assertSame(
        Locale::MASK_VERSION,
        $projection->title_mask_version,
        'EXIGENCE 2 — la projection du film nu ne porte pas la version courante du masque de titres.',
    );

    // Et le même invariant en lot : `afterCreating` tourne par modèle, pas une
    // fois pour la fournée. Une projection écrite dans `definition()` passerait
    // le cas unitaire et laisserait les quatre suivants sans ligne.
    $batch = Movie::factory()->count(5)->create();

    $this->assertSame(
        5,
        MovieProjection::query()->whereIn('movie_id', $batch->modelKeys())->count(),
        'EXIGENCE 2 — une création en lot laisse des films sans projection : la projection est écrite '
        .'une fois pour la fournée au lieu de l’être par modèle.',
    );
});

it('EXIGENCE 2 — un film `playable()` sans curateur emprunte la voie de la certification', function () {
    // `playable()` est LE composeur d'un film de vivier, et c'est donc elle qu'un
    // test futur utilisera. Tant qu'elle posait `content_flag = clear` en état nu,
    // elle produisait une ligne qu'aucun chemin applicatif ne peut produire : un
    // verdict de filtre de contenu sans auteur, sans horodatage et sans preuve. Le
    // § 3.8 ne connaît que deux voies vers ce verdict.
    $movie = Movie::factory()->playable(3)->create();

    $this->assertSame(ContentFlag::Clear, $movie->content_flag);

    $this->assertTrue(
        MovieCertification::query()
            ->where('movie_id', $movie->id)
            ->where('is_restrictive', false)
            ->exists(),
        'EXIGENCE 2 — `playable()` sans curateur rend un film `clear` sans aucune `movie_certification` : un '
        .'état que ni l’import ni la curation ne peuvent produire.',
    );

    $curator = User::query()->where('role', UserRole::Curator)->firstOrFail();

    $verified = Movie::factory()->playable(3, $curator)->create();

    $this->assertSame($curator->id, $verified->content_verified_by_id);
    $this->assertNotNull($verified->content_verified_at);
});
