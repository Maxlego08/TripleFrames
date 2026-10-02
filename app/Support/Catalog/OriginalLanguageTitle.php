<?php

namespace App\Support\Catalog;

use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\Movie;
use App\Models\MovieTitle;

/**
 * Le titre de la langue originale — spec 10 § 3.4, D52 du 02/10.
 *
 * TMDB rend une traduction **vide** pour la langue originale d'un film : un
 * film anglophone n'avait donc aucune ligne `movie_title` en `en`, son masque
 * de titres se réduisait à `fr` (ou à rien), et le joueur `en` lisait le titre
 * français. Quand la langue originale est une locale **activée** et qu'aucune
 * ligne n'existe pour elle, cette classe écrit `title_original` comme titre de
 * cette locale, en `origin = 'tmdb'` : c'est le titre que TMDB déclare dans
 * cette langue.
 *
 * **Exception étroite** à « aucun titre n'est jamais recopié d'une langue vers
 * une autre » : le titre original EST le titre dans sa propre langue. Rien
 * d'autre n'est jamais créé ici — une langue originale non activée (`ko`,
 * `ja`) n'en reçoit aucune, et une ligne existante, traduction TMDB non vide
 * ou correction `curator`, n'est jamais écrasée.
 *
 * Seul écrivain de cette règle, partagé par {@see MovieImporter} (import et
 * resynchronisation) et par la commande de rattrapage `catalog:original-titles`.
 * Elle n'écrit que la ligne : la reprojection (`movie_projection`,
 * `answer_key`) reste à l'appelant, dans sa transaction.
 */
final class OriginalLanguageTitle
{
    /**
     * La locale activée de la langue originale du film, ou `null` quand elle
     * n'est pas activée.
     */
    public static function locale(Movie $movie): ?Locale
    {
        return Locale::tryFrom(mb_strtolower(trim($movie->original_language)));
    }

    /**
     * Vrai quand le film attend sa ligne de langue originale : langue activée,
     * titre original non vide, aucune ligne pour cette locale.
     */
    public static function isMissing(Movie $movie): bool
    {
        $locale = self::locale($movie);

        if ($locale === null || trim($movie->title_original) === '') {
            return false;
        }

        return ! MovieTitle::query()
            ->where('movie_id', $movie->id)
            ->where('locale', $locale->value)
            ->exists();
    }

    /**
     * Écrit la ligne si elle manque. Rend vrai quand une ligne a été créée.
     * Idempotent : rejoué, il ne crée rien.
     */
    public static function write(Movie $movie): bool
    {
        if (! self::isMissing($movie)) {
            return false;
        }

        $locale = self::locale($movie);

        if ($locale === null) {
            return false;
        }

        $title = new MovieTitle;
        $title->movie_id = $movie->id;
        $title->locale = $locale->value;
        $title->title = mb_substr(trim($movie->title_original), 0, 255);
        $title->origin = ContentOrigin::Tmdb;
        $title->save();

        return true;
    }
}
