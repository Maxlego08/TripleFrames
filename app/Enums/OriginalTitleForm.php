<?php

namespace App\Enums;

use App\Models\Movie;
use App\Support\I18n\DisplayTitleResolver;
use Illuminate\Support\Str;

/**
 * Forme du titre original d'un film (spec 70 § 10.3, contrat C11) : la
 * dimension que le masque de locales ne porte pas.
 *
 * Quand les quatre propositions du QCM sortent de `title_original` — film cible
 * sans titre dans aucune locale activée, ou mode dégradé —, l'écriture et la
 * présence d'une translittération deviennent visibles : un seul titre en kanji
 * parmi trois titres latins désignerait la cible. Les leurres partagent donc la
 * forme de la cible.
 *
 * Classement **en PCRE, sans `ext-intl`** (absente du poste comme de la CI) :
 * - `Latin` : `title_original` ne contient que des caractères des écritures
 *   `Latin`, `Common` (chiffres, ponctuation, espaces, pleine chasse commune) et
 *   `Inherited` (diacritiques combinants) ;
 * - `Transliterated` : sinon, et `title_original_latin` existe ;
 * - `Native` : sinon.
 *
 * Aucune colonne ne la stocke : elle se dérive des deux colonnes invariantes de
 * `movie`, et {@see DisplayTitleResolver::original()} lit la même règle pour
 * choisir la chaîne affichée.
 */
enum OriginalTitleForm: string
{
    /** Titre original entièrement latin : il s'affiche tel quel. */
    case Latin = 'latin';

    /** Titre original non latin, affiché par sa translittération latine. */
    case Transliterated = 'transliterated';

    /** Titre original non latin sans translittération : affiché tel quel. */
    case Native = 'native';

    /**
     * La forme du titre original du film, lue sur `title_original` et
     * `title_original_latin` seuls — jamais sur un alias ni sur `movie_title`.
     */
    public static function of(Movie $movie): self
    {
        if (self::isLatin($movie->title_original)) {
            return self::Latin;
        }

        return self::hasTransliteration($movie->title_original_latin)
            ? self::Transliterated
            : self::Native;
    }

    /**
     * Vrai si le texte ne porte que des écritures `Latin`, `Common` et
     * `Inherited`. Une chaîne invalide en UTF-8 (`preg_match` rend `false`)
     * n'est jamais tenue pour latine : l'échec tombe du côté visible.
     */
    private static function isLatin(string $title): bool
    {
        return preg_match('/\A[\p{Latin}\p{Common}\p{Inherited}]*\z/u', $title) === 1;
    }

    /**
     * Une translittération « existe » quand la colonne est renseignée et non
     * blanche : une chaîne vide affichée à la place du titre serait pire que le
     * titre natif.
     */
    private static function hasTransliteration(?string $latin): bool
    {
        return $latin !== null && Str::trim($latin) !== '';
    }
}
