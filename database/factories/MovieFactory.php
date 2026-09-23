<?php

namespace Database\Factories;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ContentOrigin;
use App\Enums\FrameLevel;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Enums\MovieDifficulty;
use App\Models\Alias;
use App\Models\Collection;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\User;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\MovieProjector;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * L'identité d'une œuvre (§ 3.1), et l'exigence 2 du contrat de fixture
 * (§ 13.3) : **une ligne `movie` n'existe jamais sans sa ligne
 * `movie_projection`, écrite dans la MÊME transaction**.
 *
 * C'est pourquoi {@see self::create()} est redéfinie plutôt que de poser un
 * `afterCreating` dans `configure()` : la projection doit être écrite APRÈS les
 * enfants de `has()` et APRÈS les `afterCreating` de {@see self::playable()} —
 * or `Factory::store()` crée les enfants, puis `callAfterCreating()` s'exécute,
 * et seul un enveloppement de `create()` place l'insertion du film et le calcul
 * de sa projection dans une transaction unique.
 *
 * Conséquence pratique, et c'est le chemin nominal du seeder :
 *
 * ```php
 * Movie::factory()
 *     ->demo()
 *     ->contentVerifiedBy($curator)
 *     ->playable(framesPerRound: 3)
 *     ->has(Frame::factory()->published()->count(3), 'frames')
 *     ->create();
 * ```
 *
 * rend un film dont `movie_projection` compte RÉELLEMENT les frames servables :
 * les frames sont créées par `createChildren()`, avant que la projection ne soit
 * calculée. Si les frames sont ajoutées **après** coup, le seeder rappelle
 * {@see self::recomputeProjection()} — c'est le projecteur synchrone du § 3.2,
 * jamais un job de fond.
 *
 * Convention de nom du § 3.1, reprise de {@see Movie} : `Collection` non aliasée
 * désigne le modèle `App\Models\Collection`, la saga TMDB ; la collection
 * Eloquent est importée sous `EloquentCollection`.
 *
 * @extends Factory<Movie>
 */
class MovieFactory extends Factory
{
    /**
     * Identifiants de genre TMDB réels — des identifiants publics, jamais un
     * extrait de base de production. Ils servent de `rule_value` aux thèmes de
     * genre (§ 3.6, § 3.7) : sans étiquette, la règle automatique d'un thème
     * n'a rien à évaluer et le catalogue de démonstration reste sans thème.
     *
     * @var list<int>
     */
    public const TMDB_GENRE_IDS = [12, 14, 16, 18, 27, 28, 35, 36, 53, 80, 878, 10751];

    /**
     * La projection est-elle écrite avec le film ? Vrai partout, sauf sous
     * {@see self::withoutProjection()} — le seul appelant légitime étant
     * {@see MovieProjectionFactory}, qui a besoin d'un film dont la ligne de
     * projection n'existe pas encore pour pouvoir l'insérer lui-même.
     */
    protected bool $withProjection = true;

    /**
     * Define the model's default state.
     *
     * Un film importé par balayage, en brouillon et **pas encore jouable** :
     * `availability = draft` et `content_flag = unrated_pending` sont les deux
     * défauts de la table, et le rester est volontaire — c'est
     * {@see self::playable()} qui compose un film de vivier, jamais le défaut.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $difficulty = fake()->randomElement(MovieDifficulty::cases());

        return [
            'tmdb_id' => fake()->unique()->numberBetween(1, 900_000),
            'import_source' => ImportSource::Discover,
            'import_run_id' => null,
            'is_import_exception' => false,
            'exception_for_language' => false,
            'exception_for_vote_count' => false,
            'exception_for_release_year' => false,
            'title_original' => self::inventedTitle(),
            'title_original_latin' => null,
            'original_language' => 'en',
            'release_year' => fake()->numberBetween(1970, 2024),
            'vote_count' => fake()->numberBetween(50, 25_000),
            'adult' => false,
            'collection_id' => null,
            'group_id' => null,
            'availability' => ContentAvailability::Draft,
            'availability_changed_at' => null,
            'availability_reason' => null,
            'first_published_at' => null,
            'content_flag' => ContentFlag::UnratedPending,
            'content_verified_by_id' => null,
            'content_verified_at' => null,
            'movie_difficulty' => $difficulty,
            'movie_difficulty_derived' => $difficulty,
            'movie_difficulty_override' => null,
            'curated_by_id' => null,
            'curation_active_seconds' => fake()->numberBetween(0, 900),
        ];
    }

    /**
     * Create a collection of models and persist them to the database.
     *
     * Le film et sa projection dans **une seule** transaction (§ 3.2, règle 1).
     * `parent::create()` insère la ligne, crée les enfants de `has()` puis joue
     * les `afterCreating` ; la projection se calcule ensuite, sur l'état réel de
     * la base, et jamais sur une promesse.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return EloquentCollection<int, Movie>|Movie
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        /** @var EloquentCollection<int, Movie>|Movie $created */
        $created = DB::transaction(function () use ($parent) {
            $created = parent::create([], $parent);

            if ($this->withProjection) {
                $movies = $created instanceof Movie
                    ? new EloquentCollection([$created])
                    : $created;

                foreach ($movies as $movie) {
                    self::recomputeProjection($movie);
                }
            }

            return $created;
        });

        return $created;
    }

    /**
     * Un film de démonstration : `import_source = demo` et `tmdb_id = NULL`
     * (§ 13.3). Il est ainsi exclu **définitivement** de la resynchronisation
     * TMDB et des deux statistiques de débit de curation.
     */
    public function demo(): static
    {
        return $this->state([
            'import_source' => ImportSource::Demo,
            'tmdb_id' => null,
            'import_run_id' => null,
        ]);
    }

    /**
     * Un film publié — la seule valeur de `availability` qui met en jeu.
     * `first_published_at` n'est **jamais** réécrite par une republication.
     */
    public function published(): static
    {
        return $this->state([
            'availability' => ContentAvailability::Published,
            'availability_changed_at' => now(),
            'first_published_at' => now(),
        ]);
    }

    /**
     * Dépublication de curation : le film sort du vivier, ses fichiers restent.
     */
    public function unpublished(string $reason = 'Retiré du vivier par un curateur.'): static
    {
        return $this->state([
            'availability' => ContentAvailability::Unpublished,
            'availability_changed_at' => now(),
            'availability_reason' => $reason,
            'first_published_at' => now(),
        ]);
    }

    /**
     * Suspension — état d'attente d'une décision, jamais terminal.
     */
    public function suspended(string $reason = 'Suspendu le temps d’instruire un signalement.'): static
    {
        return $this->state([
            'availability' => ContentAvailability::Suspended,
            'availability_changed_at' => now(),
            'availability_reason' => $reason,
        ]);
    }

    /**
     * Retrait juridique : la ligne survit, le `tmdb_id` unique devient à lui
     * seul le blocage de réimport, sans table de bannissement (A10). Aucune
     * transition n'en sort.
     */
    public function withdrawn(string $reason = 'Retrait prononcé sur mise en demeure.'): static
    {
        return $this->state([
            'availability' => ContentAvailability::Withdrawn,
            'availability_changed_at' => now(),
            'availability_reason' => $reason,
        ]);
    }

    /**
     * Le verdict du filtre de contenu, jamais contournable : il entre dans le
     * prédicat de vivier (A3).
     */
    public function contentFlag(ContentFlag $flag): static
    {
        return $this->state(['content_flag' => $flag]);
    }

    /**
     * La coche « contenu vérifié, pas de classification restrictive » : c'est
     * elle qui rend publiable un film sans certification FR ni US connue, et
     * elle exige donc un curateur seedé **avant** le catalogue (§ 13.3).
     */
    public function contentVerifiedBy(User $curator): static
    {
        return $this->state([
            'content_flag' => ContentFlag::Clear,
            'content_verified_by_id' => $curator->id,
            'content_verified_at' => now(),
        ]);
    }

    /**
     * L'auteur de la passe de curation — `nullOnDelete`, la traçabilité ne
     * dépend pas de la survie d'un compte.
     */
    public function curatedBy(User $curator, int $activeSeconds = 420): static
    {
        return $this->state([
            'curated_by_id' => $curator->id,
            'curation_active_seconds' => $activeSeconds,
        ]);
    }

    /**
     * Entré par exception au filtre d'import. `is_import_exception` n'est
     * **jamais dérivable** des trois motifs : un film collé qui satisfait tout
     * le filtre reste marqué entré par exception (§ 3.1, décision 11).
     */
    public function importException(
        ?bool $forLanguage = null,
        ?bool $forVoteCount = null,
        ?bool $forReleaseYear = null,
    ): static {
        // Les motifs sont ADDITIFS : un motif laissé à `null` n'est pas déclaré, il
        // est laissé tel quel. Les re-déclarer tous à chaque appel ferait qu'un
        // second `importException(forLanguage: true)` chaîné après un premier
        // `importException(forReleaseYear: true)` écraserait silencieusement le
        // motif d'année — et le comptage PAR MOTIF du back-office, que la décision
        // 11 interdit de rendre silencieux, sous-compterait.
        $state = [
            'import_source' => ImportSource::Paste,
            'is_import_exception' => true,
        ];

        if ($forLanguage !== null) {
            $state['exception_for_language'] = $forLanguage;
        }

        if ($forVoteCount !== null) {
            $state['exception_for_vote_count'] = $forVoteCount;
        }

        if ($forReleaseYear !== null) {
            $state['exception_for_release_year'] = $forReleaseYear;
        }

        return $this->state($state);
    }

    /**
     * Le motif `release_year` — celui qui porte tout l'âge d'or Disney
     * antérieur à 1970, et le cas nommé par le § 13.3.
     */
    public function exceptionForReleaseYear(int $releaseYear = 1937): static
    {
        return $this->importException(forReleaseYear: true)
            ->state(['release_year' => $releaseYear]);
    }

    /**
     * Le motif `language` : le film n'est pas dans une langue du filtre.
     */
    public function exceptionForLanguage(string $originalLanguage = 'ja'): static
    {
        return $this->importException(forLanguage: true)
            ->state(['original_language' => $originalLanguage]);
    }

    /**
     * Le motif `vote_count` : notoriété TMDB sous le seuil du balayage.
     */
    public function exceptionForVoteCount(int $voteCount = 40): static
    {
        return $this->importException(forVoteCount: true)
            ->state(['vote_count' => $voteCount]);
    }

    /**
     * « Même saga » — la collection TMDB, cardinalité 0..1, jamais un pivot.
     */
    public function inCollection(Collection $collection): static
    {
        return $this->state(['collection_id' => $collection->id]);
    }

    /**
     * « Même œuvre » — le groupe manuel d'homonymes et de remakes, à ne jamais
     * confondre avec la saga.
     */
    public function inGroup(MovieGroup $group): static
    {
        return $this->state(['group_id' => $group->id]);
    }

    /**
     * Correction manuelle d'un curateur : `movie_difficulty_override` n'est
     * jamais écrite par un import, un réimport ou le calcul de décile — c'est
     * ce qui la fait survivre à la resynchronisation. La valeur **effective**
     * est dénormalisée sur `movie_difficulty`.
     */
    public function difficultyOverride(MovieDifficulty $difficulty): static
    {
        return $this->state([
            'movie_difficulty' => $difficulty,
            'movie_difficulty_override' => $difficulty,
        ]);
    }

    /**
     * Une étiquette TMDB nommée, créée **avant** le calcul de la projection.
     */
    public function withGenre(int $tmdbGenreId): static
    {
        return $this->has(MovieTmdbTag::factory()->genre($tmdbGenreId), 'tmdbTags');
    }

    /**
     * Une société de production TMDB nommée — le support local des thèmes de
     * studio (Disney, Pixar, Ghibli).
     */
    public function withCompany(int $tmdbCompanyId): static
    {
        return $this->has(MovieTmdbTag::factory()->company($tmdbCompanyId), 'tmdbTags');
    }

    /**
     * La certification retenue pour un pays — au plus une par pays. Sans
     * argument, une certification FR non restrictive : c'est la voie du § 13.3
     * alternative à la coche {@see self::contentVerifiedBy()}.
     */
    public function withCertification(?MovieCertificationFactory $certification = null): static
    {
        return $this->has($certification ?? MovieCertificationFactory::new(), 'certifications');
    }

    /**
     * Un film sans sa ligne de projection. **Un seul appelant légitime** :
     * {@see MovieProjectionFactory::definition()}, qui insère lui-même la ligne
     * et se heurterait sinon à la clé primaire `movie_id` déjà prise.
     */
    public function withoutProjection(): static
    {
        $factory = $this->newInstance();
        $factory->withProjection = false;

        return $factory;
    }

    /**
     * **Exigence 2 du § 13.3.** Compose le film complet du catalogue de
     * démonstration : disponibilité `published`, `content_flag = clear`, titres,
     * alias, au moins une étiquette TMDB, et la projection `answer_key`
     * cohérente avec les deux premiers — puis, par {@see self::create()}, la
     * ligne `movie_projection` recalculée dans la même transaction.
     *
     * Ce que cette factory ne fait PAS, parce que la banque d'images est un
     * autre domaine : **elle ne crée aucune `frame`**. Un film `playable(3)`
     * sans frame a `levels_count = 0` et n'est donc PAS dans le vivier — c'est
     * voulu, la projection ne ment jamais. Le composeur attendu :
     * `->has(Frame::factory()->published()->count(3)->sequence(...), 'frames')`
     * avec un niveau par entrée de {@see self::expectedFrameLevels()}.
     *
     * Les titres et alias passés ici sont créés seulement si la locale n'est pas
     * déjà pourvue : un `->has(MovieTitle::factory()…, 'titles')` posé en amont
     * gagne toujours, ses enfants étant créés avant les `afterCreating`.
     *
     * **`content_flag = clear` n'est jamais posé nu.** Le § 3.8 ne connaît que
     * deux voies vers ce verdict : une `movie_certification` non restrictive, ou
     * la coche de curateur — qui, par définition, horodate et signe. Un film
     * `clear` sans auteur ni preuve est une ligne qu'aucun chemin applicatif ne
     * peut produire, et un test écrit dessus prouverait un comportement de vivier
     * sur un état impossible. `$verifiedBy` choisit la voie : le curateur s'il est
     * passé, une certification FR non restrictive sinon.
     *
     * @param  int  $framesPerRound  `N`, 2 à 5 — garde de borne seule ici
     * @param  User|null  $verifiedBy  auteur de la coche ; à défaut, voie de la certification
     * @param  array<string, string>  $titles  locale de CATALOGUE => titre affiché
     * @param  array<string, list<string>>  $aliases  locale de CATALOGUE => variantes acceptées
     */
    public function playable(
        int $framesPerRound = 3,
        ?User $verifiedBy = null,
        array $titles = [],
        array $aliases = [],
    ): static {
        // Lève si `N` sort des bornes 2-5 : mieux vaut un échec de fixture qu'un
        // film de démonstration silencieusement inéligible au `N` demandé.
        self::expectedFrameLevels($framesPerRound);

        $factory = $verifiedBy instanceof User
            ? $this->contentVerifiedBy($verifiedBy)
            : $this->withCertification()->contentFlag(ContentFlag::Clear);

        return $factory->published()
            ->afterCreating(function (Movie $movie) use ($titles, $aliases): void {
                self::composeCatalogue($movie, $titles, $aliases);
            });
    }

    /**
     * Les niveaux d'images qu'un film doit couvrir pour un `N` donné —
     * l'échantillonnage de `CLAUDE.md` § 2, rappelé ici pour que les fixtures
     * nomment les niveaux au lieu de les deviner.
     *
     * **Provisoire et non normatif** : l'algorithme de tirage, repli de niveau
     * compris, appartient à la spec 30. Ce helper disparaît avec elle.
     *
     * @return list<FrameLevel>
     */
    public static function expectedFrameLevels(int $framesPerRound): array
    {
        return match ($framesPerRound) {
            2 => [FrameLevel::Level1, FrameLevel::Level5],
            3 => [FrameLevel::Level1, FrameLevel::Level3, FrameLevel::Level5],
            4 => [FrameLevel::Level1, FrameLevel::Level2, FrameLevel::Level4, FrameLevel::Level5],
            5 => FrameLevel::cases(),
            default => throw new InvalidArgumentException(
                "frames_per_round vaut [{$framesPerRound}] : les bornes du salon sont 2 à 5.",
            ),
        };
    }

    /**
     * Le projecteur synchrone du § 3.2 — **simple renvoi** vers
     * {@see MovieProjector}, implémentation unique du projet. Il recalcule
     * `levels_*`, `variants_total` et `title_locale_mask` sur l'état réel de la
     * base, pose `title_mask_version = Locale::MASK_VERSION` et
     * `recomputed_at`, et crée la ligne si elle manque.
     *
     * À rappeler par tout seeder qui ajoute des `frame` ou des `movie_title`
     * APRÈS la création du film.
     */
    public static function recomputeProjection(Movie $movie): MovieProjection
    {
        return (new MovieProjector)->recompute($movie);
    }

    /**
     * Create a new instance of the factory builder with the given mutated properties.
     *
     * `Factory::newInstance()` ne recopie que ses propres propriétés : sans cette
     * reprise, le moindre `->state()` posé après `withoutProjection()` ferait
     * silencieusement réapparaître la projection.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->withProjection = $this->withProjection;

        return $instance;
    }

    /**
     * Titres, alias, étiquette et clés de réponse d'un film de vivier.
     *
     * @param  array<string, string>  $titles
     * @param  array<string, list<string>>  $aliases
     */
    private static function composeCatalogue(Movie $movie, array $titles, array $aliases): void
    {
        // Un film de démonstration n'a jamais été lu chez TMDB : ses lignes de
        // contenu sont `curator`, et une ligne `curator` n'est jamais réécrite
        // par un réimport (§ 3.4).
        $origin = $movie->import_source === ImportSource::Demo
            ? ContentOrigin::Curator
            : ContentOrigin::Tmdb;

        if ($titles === []) {
            $titles = [
                Locale::English->value => $movie->title_original,
                Locale::French->value => self::inventedTitle(),
            ];
        }

        /** @var list<string> $covered */
        $covered = MovieTitle::query()->where('movie_id', $movie->id)->pluck('locale')->all();

        foreach ($titles as $locale => $title) {
            if (in_array($locale, $covered, true)) {
                continue;
            }

            MovieTitle::factory()->create([
                'movie_id' => $movie->id,
                'locale' => $locale,
                'title' => $title,
                'origin' => $origin,
            ]);
        }

        if ($aliases === [] && Alias::query()->where('movie_id', $movie->id)->doesntExist()) {
            $aliases = [Locale::French->value => [self::inventedTitle(2)]];
        }

        foreach ($aliases as $locale => $variants) {
            foreach ($variants as $variant) {
                Alias::factory()->create([
                    'movie_id' => $movie->id,
                    'locale' => $locale,
                    'alias' => $variant,
                    'origin' => ContentOrigin::Curator,
                ]);
            }
        }

        // Sans étiquette, aucun thème de genre ni de studio n'est testable : la
        // règle automatique n'a rien à évaluer (§ 3.6, exigence 2 du § 13.3).
        if (MovieTmdbTag::query()->where('movie_id', $movie->id)->doesntExist()) {
            MovieTmdbTag::factory()->create(['movie_id' => $movie->id]);
        }

        self::projectAnswerKeys($movie);
    }

    /**
     * La projection `answer_key` du film — **simple renvoi** vers
     * {@see AnswerKeyProjector}, implémentation unique du projet (arbitrage A5).
     *
     * Elle couvre le périmètre exact du § 3.5 : `title_original` et
     * `title_original_latin` inconditionnellement, `movie_title` et `alias` des
     * seules locales **activées**, plus les préfixes dérivés des seuls titres,
     * jamais d'un alias (décision 13) — et elle recompte l'ambiguïté des
     * préfixes touchés dans la foulée.
     */
    private static function projectAnswerKeys(Movie $movie): void
    {
        (new AnswerKeyProjector)->project($movie);
    }

    /**
     * Le recompte d'ambiguïté du § 3.5 — **simple renvoi** vers
     * {@see AnswerKeyProjector::recomputeAmbiguity()}, synchrone et borné.
     *
     * Reste exposé ici parce qu'un seeder peut publier un film hors du chemin
     * de cette factory et devoir recompter les préfixes qu'il rend ambigus.
     *
     * @param  list<string>  $normalizedValues
     */
    public static function recomputePrefixAmbiguity(array $normalizedValues): void
    {
        (new AnswerKeyProjector)->recomputeAmbiguity($normalizedValues);
    }

    /**
     * Un titre inventé. Aucune fixture n'emprunte un extrait de base de
     * production ; les titres réels du catalogue de démonstration sont passés
     * explicitement par le seeder.
     */
    private static function inventedTitle(int $words = 3): string
    {
        /** @var string $text */
        $text = fake()->unique()->words($words, true);

        return Str::title($text);
    }
}
