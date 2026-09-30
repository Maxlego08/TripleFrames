<?php

namespace App\Support\Catalog;

use JsonException;

/**
 * Le rapport d'ambiguïté que le curateur lit **avant confirmation** — spec 20
 * § 8.2, décision 13. Produit par {@see AmbiguityPreview}, en lecture seule.
 *
 * Une ligne par forme normalisée : la forme, les natures sous lesquelles CE
 * film la porte (`kinds`), et les films publiés qui la portent aussi, chacun
 * avec l'identité que l'écran affiche (`id`, `title_original`,
 * `release_year`) et la nature sous laquelle il la porte (`kind`).
 *
 * Les lignes sont **triées à la construction** — formes, natures, films —,
 * pour que {@see self::digest()} ne dépende que du contenu du rapport, jamais
 * de l'ordre des lectures : c'est l'empreinte que la confirmation poste, et
 * que la publication recalcule sous verrou. Un catalogue changé entre
 * l'aperçu et le clic change l'empreinte, et la publication est refusée
 * (`admin.movie.publish.preview_stale`) : l'avertissement n'est jamais
 * périmé.
 *
 * @phpstan-type AmbiguityMovie array{id: int, title_original: string, release_year: int|null, kind: string}
 * @phpstan-type AmbiguityLine array{form: string, kinds: list<string>, movies: list<AmbiguityMovie>}
 */
final readonly class AmbiguityReport
{
    /** @var list<AmbiguityLine> */
    public array $lines;

    /**
     * @param  list<AmbiguityLine>  $lines
     */
    public function __construct(array $lines)
    {
        $sorted = [];

        foreach ($lines as $line) {
            $kinds = array_values(array_unique($line['kinds']));
            sort($kinds);

            $movies = $line['movies'];
            usort($movies, static fn (array $a, array $b): int => [$a['id'], $a['kind']] <=> [$b['id'], $b['kind']]);

            $sorted[] = ['form' => $line['form'], 'kinds' => $kinds, 'movies' => $movies];
        }

        usort($sorted, static fn (array $a, array $b): int => strcmp($a['form'], $b['form']));

        $this->lines = $sorted;
    }

    /** Vrai quand aucune forme ne deviendra ambiguë : l'écran le dit explicitement. */
    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * SHA-256 des lignes triées, en hexadécimal (64 caractères) — la valeur
     * que la confirmation poste en `ambiguity_digest`.
     *
     * @throws JsonException
     */
    public function digest(): string
    {
        return hash('sha256', json_encode($this->lines, JSON_THROW_ON_ERROR));
    }

    /**
     * La prop `publication_preview` des écrans qui publient.
     *
     * @return array{lines: list<AmbiguityLine>, digest: string}
     *
     * @throws JsonException
     */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'digest' => $this->digest(),
        ];
    }
}
