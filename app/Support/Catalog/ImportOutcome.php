<?php

namespace App\Support\Catalog;

use App\Models\Movie;
use App\ValueObjects\Catalog\ExceptionMotives;

/**
 * Le sort d'une fiche TMDB, rendu en **données** et jamais en phrase
 * pré-formatée (règle 4) : le motif voyage en clé de traduction et
 * substitutions, la commande et l'écran de back-office le rendent chacun dans
 * leur langue.
 */
final readonly class ImportOutcome
{
    /**
     * @param  array<string, string|int>  $reasonReplacements
     */
    private function __construct(
        public int $tmdbId,
        public ImportDecision $decision,
        public ?Movie $movie = null,
        public ?string $reasonKey = null,
        public array $reasonReplacements = [],
        public ?ExceptionMotives $motives = null,
        public bool $isImportException = false,
    ) {}

    public static function imported(Movie $movie, ExceptionMotives $motives, bool $isImportException): self
    {
        return new self(
            tmdbId: $movie->tmdb_id ?? 0,
            decision: ImportDecision::Imported,
            movie: $movie,
            motives: $motives,
            isImportException: $isImportException,
        );
    }

    public static function resynchronized(Movie $movie): self
    {
        return new self(
            tmdbId: $movie->tmdb_id ?? 0,
            decision: ImportDecision::Resynchronized,
            movie: $movie,
            isImportException: $movie->is_import_exception,
        );
    }

    public static function duplicate(Movie $movie): self
    {
        return new self(
            tmdbId: $movie->tmdb_id ?? 0,
            decision: ImportDecision::Duplicate,
            movie: $movie,
            reasonKey: 'admin.catalog.import.skipped.duplicate',
            isImportException: $movie->is_import_exception,
        );
    }

    public static function skippedByFilter(int $tmdbId, ExceptionMotives $motives): self
    {
        return new self(
            tmdbId: $tmdbId,
            decision: ImportDecision::SkippedByFilter,
            reasonKey: 'admin.catalog.import.skipped.filter',
            motives: $motives,
        );
    }

    /**
     * @param  array<string, string|int>  $replacements
     */
    public static function refusedContent(int $tmdbId, string $reasonKey, array $replacements = []): self
    {
        return new self(
            tmdbId: $tmdbId,
            decision: ImportDecision::RefusedContent,
            reasonKey: $reasonKey,
            reasonReplacements: $replacements,
        );
    }

    /**
     * Le motif, l'auteur et l'horodatage du retrait sont déjà consignés sur la
     * ligne `movie` : la refuser n'exige aucune table de bannissement.
     */
    public static function refusedWithdrawn(Movie $movie): self
    {
        return new self(
            tmdbId: $movie->tmdb_id ?? 0,
            decision: ImportDecision::RefusedWithdrawn,
            movie: $movie,
            reasonKey: 'admin.catalog.import.refused.withdrawn',
            reasonReplacements: ['reason' => $movie->availability_reason ?? ''],
        );
    }

    public static function notFound(int $tmdbId): self
    {
        return new self(
            tmdbId: $tmdbId,
            decision: ImportDecision::NotFound,
            reasonKey: 'admin.catalog.import.skipped.not_found',
        );
    }

    public static function simulated(int $tmdbId, ExceptionMotives $motives, bool $isImportException): self
    {
        return new self(
            tmdbId: $tmdbId,
            decision: ImportDecision::Simulated,
            motives: $motives,
            isImportException: $isImportException,
        );
    }

    /**
     * Les incréments des quatre compteurs de `import_run`.
     *
     * `total_refused_content` est le nombre de lignes refusées par le filtre de
     * **contenu**, et lui seul : c'est le chiffre qu'il faut pouvoir produire
     * devant une mise en demeure (§ 9.1). Un film refusé parce qu'un retrait
     * juridique a déjà été prononcé n'y entre pas — il n'a pas été écarté par
     * le filtre, il a été écarté par une décision déjà prise et déjà tracée sur
     * sa propre ligne — et compte donc comme écarté.
     *
     * @return array{total_seen: int, total_imported: int, total_skipped: int, total_refused_content: int}
     */
    public function countersFor(): array
    {
        return [
            'total_seen' => 1,
            'total_imported' => match ($this->decision) {
                ImportDecision::Imported, ImportDecision::Resynchronized, ImportDecision::Simulated => 1,
                default => 0,
            },
            'total_skipped' => match ($this->decision) {
                ImportDecision::Duplicate,
                ImportDecision::SkippedByFilter,
                ImportDecision::NotFound,
                ImportDecision::RefusedWithdrawn => 1,
                default => 0,
            },
            'total_refused_content' => $this->decision === ImportDecision::RefusedContent ? 1 : 0,
        ];
    }
}
