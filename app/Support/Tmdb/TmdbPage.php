<?php

namespace App\Support\Tmdb;

/**
 * Une page de `discover`, et de quoi savoir s'il en reste.
 *
 * `hasMore()` et `nextPage()` existent parce que la reprise d'un balayage est
 * une exigence de schéma : `import_run.tmdb_page_cursor` doit pouvoir être
 * réécrit après chaque page, un balayage de 500+ films interrompu par le quota
 * n'aboutissant jamais s'il n'est pas reprenable (§ 9.1).
 *
 * **TMDB plafonne `discover` à 500 pages**, quel que soit `total_pages` ; au-delà
 * il répond 400. {@see self::MAX_PAGE} rend ce plafond explicite plutôt que de
 * le laisser découvrir en production.
 */
final readonly class TmdbPage
{
    /** Plafond dur de pagination de `discover`, imposé par TMDB. */
    public const int MAX_PAGE = 500;

    /**
     * @param  list<TmdbMovieSummary>  $results
     */
    public function __construct(
        public int $page,
        public int $totalPages,
        public int $totalResults,
        public array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $context = 'discover'): self
    {
        $results = [];
        $index = 0;

        foreach (TmdbData::objectsAt($data, 'results', $context) as $result) {
            $results[] = TmdbMovieSummary::fromArray($result, $context.'.results['.$index.']');
            $index++;
        }

        return new self(
            page: max(1, TmdbData::counter($data, 'page', $context)),
            totalPages: TmdbData::counter($data, 'total_pages', $context),
            totalResults: TmdbData::counter($data, 'total_results', $context),
            results: $results,
        );
    }

    /**
     * Vrai s'il existe une page suivante ATTEIGNABLE — plafond TMDB compris.
     */
    public function hasMore(): bool
    {
        return $this->page < $this->totalPages && $this->page < self::MAX_PAGE;
    }

    /**
     * Numéro de la page suivante, ou `null` : c'est lui qui s'écrit dans
     * `import_run.tmdb_page_cursor`.
     */
    public function nextPage(): ?int
    {
        return $this->hasMore() ? $this->page + 1 : null;
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }
}
