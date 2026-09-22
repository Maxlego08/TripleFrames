<?php

namespace App\ValueObjects\Catalog;

/**
 * Les trois motifs d'exception d'un film, tels que `movie` les porte (§ 3.1).
 *
 * Trois booléens plutôt qu'un enum ou du JSON : un film cumule souvent
 * plusieurs motifs — Blanche-Neige est à la fois hors année et sous le seuil de
 * votes —, le back-office doit **compter par motif**, et un tableau JSON serait
 * interdit de requête (§ 1.6).
 *
 * Ce value object ne dit **rien** de `is_import_exception`, et c'est la raison
 * d'être de la quatrième colonne : un film collé qui satisfait tout le filtre
 * reste marqué entré par exception alors que ses trois motifs sont faux
 * (§ 9.2). `is_import_exception` n'est jamais dérivable d'ici.
 */
final readonly class ExceptionMotives
{
    public function __construct(
        public bool $forLanguage,
        public bool $forVoteCount,
        public bool $forReleaseYear,
    ) {}

    /**
     * Aucun motif — le film satisfait le filtre par défaut de bout en bout.
     */
    public static function none(): self
    {
        return new self(false, false, false);
    }

    /**
     * Vrai dès qu'un motif est posé, donc vrai exactement quand le filtre par
     * défaut aurait écarté ce film d'un balayage `discover`.
     */
    public function any(): bool
    {
        return $this->forLanguage || $this->forVoteCount || $this->forReleaseYear;
    }

    /**
     * Les trois colonnes de `movie`, prêtes à être écrites.
     *
     * @return array{exception_for_language: bool, exception_for_vote_count: bool, exception_for_release_year: bool}
     */
    public function toAttributes(): array
    {
        return [
            'exception_for_language' => $this->forLanguage,
            'exception_for_vote_count' => $this->forVoteCount,
            'exception_for_release_year' => $this->forReleaseYear,
        ];
    }
}
