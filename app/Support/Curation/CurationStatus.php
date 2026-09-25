<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Models\MovieProjection;

/**
 * Les trois états de curation d'un film que le back-office compte et filtre
 * (spec 20 § 4.2, § 6.6, § 8.6) — **dérivés, jamais stockés** : aucune colonne
 * ne les porte, ils se lisent sur `movie` et `movie_projection`.
 *
 * - **Prêt à publier** : brouillon, contenu `clear`, niveaux 1, 3 et 5
 *   couverts — les deux gardes de transition lisibles sans calcul (§ 8.1). La
 *   garde de devinabilité et l'aperçu d'ambiguïté restent au geste lui-même,
 *   qui les rejoue sous verrou : ce compteur désigne le travail à finir, il
 *   n'autorise rien.
 * - **Incomplet** : publié, mais la couverture 1/3/5 est perdue (E10-23, § 6.6).
 *   Il reste publié, jouable avec repli de niveau ; le back-office l'affiche et
 *   le compte, il ne le dépublie jamais seul.
 * - **Écarté** (`set_aside`, AN20-7) : `unpublished` et jamais publié
 *   (`first_published_at` NULL, EN20-1) — sorti de la file de curation sans
 *   être publié (§ 4.2).
 *
 * **Un seul texte de prédicat**, consommé deux fois : filtre du catalogue
 * ({@see self::condition()} dans un `whereRaw`) et compteurs du tableau de
 * bord (le même texte dans un `sum(case when …)`). Deux écritures finiraient
 * par diverger, et un compteur ne mènerait plus à la liste qu'il annonce.
 *
 * Le prédicat suppose `movie` et `movie_projection` joint **à gauche** sous ces
 * noms : un film sans ligne de projection (restauré avant `catalog:reproject`,
 * spec 10 § 3.2) a un masque nul — jamais prêt, toujours incomplet s'il est
 * publié, c'est-à-dire visible du côté qui alarme. Le masque 1/3/5 vient de
 * {@see MovieProjection::publishableLevelsMask()} : aucun 21 en dur, et
 * l'arithmétique bit à bit est portable entre SQLite et MySQL, là où
 * `BIT_COUNT` n'existe pas en SQLite.
 */
enum CurationStatus: string
{
    case ReadyToPublish = 'ready_to_publish';

    case Incomplete = 'incomplete';

    case SetAside = 'set_aside';

    /**
     * Le prédicat SQL de l'état et ses liaisons, sur `movie` et
     * `movie_projection` joint à gauche. Aucune valeur d'origine utilisateur
     * n'y entre : le texte est littéral, les liaisons sont des valeurs
     * d'énumération et un masque calculé.
     *
     * @return array{0: literal-string, 1: list<int|string>}
     */
    public function condition(): array
    {
        $mask = MovieProjection::publishableLevelsMask();

        return match ($this) {
            self::ReadyToPublish => [
                'movie.availability = ? and movie.content_flag = ? and (coalesce(movie_projection.levels_mask, 0) & ?) = ?',
                [ContentAvailability::Draft->value, ContentFlag::Clear->value, $mask, $mask],
            ],
            self::Incomplete => [
                'movie.availability = ? and (coalesce(movie_projection.levels_mask, 0) & ?) <> ?',
                [ContentAvailability::Published->value, $mask, $mask],
            ],
            self::SetAside => [
                'movie.availability = ? and movie.first_published_at is null',
                [ContentAvailability::Unpublished->value],
            ],
        };
    }
}
