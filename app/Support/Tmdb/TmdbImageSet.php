<?php

namespace App\Support\Tmdb;

/**
 * Les visuels d'un film, rendus tels que TMDB les classe.
 *
 * Les trois familles sont conservées parce que le client ne tranche rien. Ce
 * qu'il faut savoir en aval, et qui n'est PAS appliqué ici : la grille
 * d'exclusion de curation interdit affiche, jaquette, carton-titre, logo de
 * studio et générique — `posters` et `logos` ne sont donc candidats à aucune
 * `frame`, et seuls les `backdrops` le sont. Cette règle vit dans
 * `App\Support\Curation\ExclusionGrid`, versionnée, et se tranche sur l'image
 * réelle par un humain : la reproduire ici en dupliquerait la version.
 *
 * Aucune requête n'est faite avec un paramètre `language`, et aucun visuel
 * n'est retiré ici : TMDB filtrerait alors les visuels par langue sans que
 * personne sache combien il en a retenu. Les backdrops auxquels TMDB attache
 * une langue sont écartés en aval (D39 du 28/09), là où ils se comptent et où
 * leur ajout se refuse, sur {@see TmdbImage::isLanguageNeutral()}.
 */
final readonly class TmdbImageSet
{
    /**
     * @param  list<TmdbImage>  $backdrops  Seuls candidats possibles à une `frame`.
     * @param  list<TmdbImage>  $posters  Jamais candidats : exclus par la grille.
     * @param  list<TmdbImage>  $logos  Jamais candidats : exclus par la grille.
     */
    public function __construct(
        public int $tmdbId,
        public array $backdrops,
        public array $posters,
        public array $logos,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?int $tmdbId = null, string $context = 'images'): self
    {
        return new self(
            tmdbId: TmdbData::optionalInteger($data, 'id', $context) ?? $tmdbId ?? 0,
            backdrops: self::images($data, 'backdrops', $context),
            posters: self::images($data, 'posters', $context),
            logos: self::images($data, 'logos', $context),
        );
    }

    /**
     * Vrai si TMDB ne connaît aucun visuel de ce film — cas fréquent sur un
     * film peu connu, et qui n'est pas une erreur.
     */
    public function isEmpty(): bool
    {
        return $this->backdrops === [] && $this->posters === [] && $this->logos === [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<TmdbImage>
     */
    private static function images(array $data, string $key, string $context): array
    {
        $images = [];
        $index = 0;

        foreach (TmdbData::objectsAt($data, $key, $context) as $entry) {
            $images[] = TmdbImage::fromArray($entry, $context.'.'.$key.'['.$index.']');
            $index++;
        }

        return $images;
    }
}
