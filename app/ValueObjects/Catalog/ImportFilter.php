<?php

namespace App\ValueObjects\Catalog;

use App\Support\Tmdb\TmdbDiscoverQuery;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Le filtre de **goût** de l'import — notoriété, langue originale, profondeur
 * historique (§ 9.1, décision 11).
 *
 * Il gouverne **uniquement** le balayage `discover`. La voie d'exception
 * l'ignore entièrement (§ 9.2), et c'est l'asymétrie la plus structurante de
 * tout l'import : les filtres de goût sont contournables et **tracés** ; les
 * filtres de contenu ne le sont **jamais, par aucune voie**, et ils ne vivent
 * donc pas ici mais dans {@see \App\Support\Catalog\RestrictiveCertifications}.
 *
 * **Aucune persistance propre** : les colonnes typées `import_run.filter_*`
 * portent la copie figée qui permet de rejouer un balayage, et
 * {@see self::fromColumns()} la relit. Les valeurs par défaut vivent en
 * configuration, jamais en littéral dans une migration ni dans un FormRequest
 * (règle 2).
 *
 * Deux méthodes portent tout le poids normatif :
 *
 * - {@see self::isWiderThanDefault()} pose `import_run.is_widened`, **figé au
 *   démarrage**, sans quoi le marquage d'exception devient arbitraire ;
 * - {@see self::exceptionMotivesFor()} pose les trois booléens de `movie`.
 *
 * **Les motifs se mesurent toujours contre le filtre PAR DÉFAUT**, jamais
 * contre le filtre appliqué : sur un balayage élargi, un motif dit pourquoi le
 * film ne serait pas entré par la voie ordinaire, et c'est cette phrase-là que
 * le back-office compte. Mesurés contre le filtre appliqué, les trois motifs
 * d'un balayage élargi seraient tous faux par construction, et la colonne ne
 * vaudrait plus rien.
 */
final readonly class ImportFilter
{
    /** Décision 11 : un film que personne ne reconnaît est une manche gâchée. */
    public const int DEFAULT_MIN_VOTE_COUNT = 500;

    /** Décision 11 : les trois corpus du produit, jamais une liste ouverte. */
    public const string DEFAULT_LANGUAGES = 'fr,en,ja';

    /** Décision 11 : la borne qui ampute l'âge d'or Disney, et l'assume. */
    public const int DEFAULT_MIN_RELEASE_YEAR = 1970;

    /** Préfixe de configuration, miroir de `config/catalog.php`. */
    private const string CONFIG_PREFIX = 'catalog.import_filter.';

    /**
     * @param  int  $minVoteCount  Notoriété brute TMDB minimale.
     * @param  list<string>  $languages  Codes de langue originale acceptés, minuscules, dédoublonnés.
     * @param  int  $minReleaseYear  Année de sortie minimale.
     */
    private function __construct(
        public int $minVoteCount,
        public array $languages,
        public int $minReleaseYear,
    ) {}

    /**
     * Le filtre par défaut du site, seul étalon de `is_widened` et des trois
     * motifs.
     */
    public static function default(): self
    {
        return new self(
            minVoteCount: Config::integer(self::CONFIG_PREFIX.'min_vote_count', self::DEFAULT_MIN_VOTE_COUNT),
            languages: self::normalizeLanguages(
                Config::array(self::CONFIG_PREFIX.'languages', explode(',', self::DEFAULT_LANGUAGES)),
            ),
            minReleaseYear: Config::integer(self::CONFIG_PREFIX.'min_release_year', self::DEFAULT_MIN_RELEASE_YEAR),
        );
    }

    /**
     * Un filtre composé à la main, chaque axe retombant sur le défaut quand il
     * n'est pas fourni. C'est la voie de l'option de commande `--min-votes`,
     * `--languages` et `--min-year`.
     *
     * @param  list<string>|null  $languages
     */
    public static function make(
        ?int $minVoteCount = null,
        ?array $languages = null,
        ?int $minReleaseYear = null,
    ): self {
        $default = self::default();

        return new self(
            minVoteCount: max(0, $minVoteCount ?? $default->minVoteCount),
            languages: $languages === null ? $default->languages : self::normalizeLanguages($languages),
            minReleaseYear: $minReleaseYear ?? $default->minReleaseYear,
        );
    }

    /**
     * Le filtre figé d'un balayage, relu depuis les trois colonnes
     * `import_run.filter_*` — c'est ce qui rend un balayage **rejouable**.
     *
     * `filter_languages` est une chaîne jointe par virgules, jamais interrogée
     * (§ 9.1) : elle se relit, elle ne se requête pas.
     */
    public static function fromColumns(
        ?int $minVoteCount,
        ?string $languages,
        ?int $minReleaseYear,
    ): self {
        return self::make(
            minVoteCount: $minVoteCount,
            languages: $languages === null ? null : self::normalizeLanguages(explode(',', $languages)),
            minReleaseYear: $minReleaseYear,
        );
    }

    /**
     * Les trois colonnes de `import_run`, prêtes à être écrites au démarrage.
     *
     * @return array{filter_min_vote_count: int, filter_languages: string, filter_min_release_year: int}
     */
    public function toColumns(): array
    {
        return [
            'filter_min_vote_count' => $this->minVoteCount,
            'filter_languages' => $this->languagesColumn(),
            'filter_min_release_year' => $this->minReleaseYear,
        ];
    }

    /**
     * La chaîne jointe par virgules de `import_run.filter_languages`, bornée à
     * la largeur de la colonne — `string(64)`.
     */
    public function languagesColumn(): string
    {
        return mb_substr(implode(',', $this->languages), 0, 64);
    }

    /**
     * Le filtre appliqué est-il plus large que le filtre par défaut ?
     *
     * Plus large sur **au moins un** des trois axes : un seuil de votes abaissé,
     * une langue ajoutée, une année de départ reculée. Rétrécir un axe tout en
     * élargissant un autre reste un élargissement — un balayage qui va chercher
     * du coréen à 800 votes fait bien entrer des films que le filtre par défaut
     * n'aurait jamais vus.
     *
     * Le résultat est **figé au démarrage** dans `import_run.is_widened`, et
     * jamais recalculé : le défaut du site peut changer après coup, la preuve
     * qu'un balayage a été élargi, non.
     */
    public function isWiderThanDefault(): bool
    {
        $default = self::default();

        if ($this->minVoteCount < $default->minVoteCount) {
            return true;
        }

        if ($this->minReleaseYear < $default->minReleaseYear) {
            return true;
        }

        return array_diff($this->languages, $default->languages) !== [];
    }

    /**
     * Le film satisfait-il ce filtre ? Vrai lorsque les trois axes passent.
     *
     * Un film **sans année de sortie connue** échoue l'axe historique : TMDB
     * rend une chaîne vide pour une œuvre annoncée ou mal renseignée, et un
     * blindtest ne tire pas un film dont personne ne sait quand il est sorti.
     * Il reste récupérable par la voie d'exception, comme tout le reste.
     */
    public function accepts(string $originalLanguage, int $voteCount, ?int $releaseYear): bool
    {
        return ! $this->exceptionMotivesFor($originalLanguage, $voteCount, $releaseYear)->any();
    }

    /**
     * Les trois motifs d'exception de `movie`, cumulables.
     *
     * Appelée avec le filtre **par défaut** partout où elle sert à écrire les
     * colonnes : voir l'en-tête de classe.
     */
    public function exceptionMotivesFor(
        string $originalLanguage,
        int $voteCount,
        ?int $releaseYear,
    ): ExceptionMotives {
        return new ExceptionMotives(
            forLanguage: ! in_array(mb_strtolower($originalLanguage), $this->languages, true),
            forVoteCount: $voteCount < $this->minVoteCount,
            forReleaseYear: $releaseYear === null || $releaseYear < $this->minReleaseYear,
        );
    }

    /**
     * La requête de balayage d'une des langues du filtre.
     *
     * `with_original_language` n'accepte **qu'une** langue : un filtre à trois
     * langues est trois séries d'appels paginées indépendamment, et c'est
     * précisément ce qui oblige `import_run.tmdb_page_cursor` à porter une
     * position composite ({@see \App\Support\Catalog\DiscoverCursor}).
     */
    public function discoverQueryFor(string $language): TmdbDiscoverQuery
    {
        if (! in_array($language, $this->languages, true)) {
            throw new InvalidArgumentException(
                'Langue ['.$language.'] absente du filtre ['.$this->languagesColumn().'].',
            );
        }

        return new TmdbDiscoverQuery(
            originalLanguage: $language,
            minVoteCount: $this->minVoteCount,
            minReleaseYear: $this->minReleaseYear,
        );
    }

    /**
     * Minuscules, vides retirés, doublons retirés, indices réindexés.
     *
     * @param  array<array-key, mixed>  $languages
     * @return list<string>
     */
    private static function normalizeLanguages(array $languages): array
    {
        $normalized = [];

        foreach ($languages as $language) {
            if (! is_string($language)) {
                continue;
            }

            $trimmed = mb_strtolower(trim($language));

            if ($trimmed !== '' && ! in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Un filtre d’import sans aucune langue n’a aucun sens.');
        }

        return $normalized;
    }
}
