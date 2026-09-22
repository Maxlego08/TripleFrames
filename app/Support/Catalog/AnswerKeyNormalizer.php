<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * La forme normalisée d'une chaîne, et le préfixe d'un titre (§ 3.5).
 *
 * **Implémentation UNIQUE du projet.** `Database\Factories\AnswerKeyFactory`
 * délègue ici : deux sites de normalisation divergent silencieusement au
 * premier changement d'algorithme (arbitrage A5), et une fixture projetée avec
 * un normaliseur distinct de celui qui appariera les réponses produit un
 * catalogue complet sur lequel aucune réponse ne valide jamais.
 *
 * **Provisoire quant à l'algorithme, définitif quant à l'adresse.** La
 * translittération fine, les chiffres romains et la tolérance de Levenshtein
 * appartiennent à `docs/specs/70-validation-des-reponses.md`, qui n'est pas
 * écrite : c'est le corps des méthodes qui bougera, jamais leur domicile.
 *
 * L'alphabet de sortie est réduit à `[a-z0-9 ]` : sur cet alphabet,
 * `utf8mb4_unicode_ci` et BINARY rendent le **même** verdict, et la portabilité
 * cesse d'être une propriété du moteur pour devenir une propriété de la donnée.
 *
 * `Str::ascii()` et non `transliterator_transliterate()` : `ext-intl` est
 * **absente** de cet environnement, et un normaliseur qui lèverait en CI ne
 * normaliserait rien du tout.
 */
final class AnswerKeyNormalizer
{
    /**
     * Longueur maximale d'une forme normalisée (§ 1.3) : la borne haute du
     * réglage « longueur maximale d'une réponse », donc aucune chaîne
     * saisissable n'est jamais tronquée.
     */
    public const int MAX_NORMALIZED_LENGTH = 200;

    /** Défaut de `config('catalog.min_prefix_length')`. */
    public const int DEFAULT_MIN_PREFIX_LENGTH = 4;

    /**
     * Défaut de `config('catalog.subtitle_separators')`.
     *
     * @var list<string>
     */
    public const array DEFAULT_SUBTITLE_SEPARATORS = [' : ', ': ', ' - ', ' – ', ' — '];

    /**
     * Articles de tête retirés à la normalisation. Liste provisoire, propriété
     * de la spec 70 : elle couvre les deux locales activées en v1.
     *
     * Elle n'est **pas** en configuration, contrairement aux deux réglages de
     * préfixe : ceux-là sont nommés par le § 3.5 comme réglables, celle-ci fait
     * partie de l'algorithme, et un algorithme de normalisation modifiable sans
     * reprojection produit un index incohérent avec la règle qui l'a produit.
     *
     * @var list<string>
     */
    private const array LEADING_ARTICLES = [
        'le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'the', 'a', 'an',
    ];

    /**
     * Minuscules, diacritiques translittérés, ponctuation retirée, article de
     * tête retiré, alphabet réduit à `[a-z0-9 ]`, borné à 200 caractères.
     *
     * La ponctuation est remplacée par une espace **avant** `Str::ascii()`, et
     * c'est mesuré, pas décoratif : `Str::ascii()` SUPPRIME ce qu'elle ne sait
     * pas translittérer au lieu de le remplacer. Sans cette passe, « WALL·E »
     * donne `walle` là où le joueur qui tape « WALL E » donne `wall e`, et la
     * bonne réponse est refusée pour toujours.
     *
     * Rend la chaîne vide si rien ne subsiste — un titre entièrement non latin
     * passe alors par `movie.title_original_latin`, jamais par une clé vide.
     */
    public static function normalize(string $text): string
    {
        $unpunctuated = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text) ?? $text;

        $folded = Str::lower(Str::ascii($unpunctuated));

        $spaced = preg_replace('/[^a-z0-9]+/', ' ', $folded) ?? '';
        $collapsed = trim(preg_replace('/\s+/', ' ', $spaced) ?? '');

        if ($collapsed === '') {
            return '';
        }

        return Str::substr(self::withoutLeadingArticle($collapsed), 0, self::MAX_NORMALIZED_LENGTH);
    }

    /**
     * Le préfixe **normalisé** d'un titre, découpé au premier séparateur de
     * sous-titre et retenu au-delà de la longueur minimale — ou `null` s'il n'y
     * a pas de sous-titre, si le préfixe est trop court, ou s'il ne se distingue
     * pas du titre entier.
     *
     * Ne s'applique **jamais** à un alias (décision 13) : c'est l'appelant qui
     * tient cette règle, la fonction ne sait pas d'où vient sa chaîne.
     */
    public static function prefixOf(string $title): ?string
    {
        $cut = null;

        foreach (self::subtitleSeparators() as $separator) {
            $position = mb_strpos($title, $separator);

            if ($position === false || $position === 0) {
                continue;
            }

            $cut = $cut === null ? $position : min($cut, $position);
        }

        if ($cut === null) {
            return null;
        }

        $prefix = self::normalize(mb_substr($title, 0, $cut));

        if (mb_strlen($prefix) < self::minPrefixLength() || $prefix === self::normalize($title)) {
            return null;
        }

        return $prefix;
    }

    /**
     * Les séparateurs de sous-titre configurés. Les entrées non textuelles et
     * les chaînes vides sont écartées : une chaîne vide ferait de `mb_strpos()`
     * un découpage à la position 0 sur tous les titres.
     *
     * @return list<string>
     */
    public static function subtitleSeparators(): array
    {
        $separators = [];

        foreach (Config::array('catalog.subtitle_separators', self::DEFAULT_SUBTITLE_SEPARATORS) as $separator) {
            if (is_string($separator) && $separator !== '') {
                $separators[] = $separator;
            }
        }

        return $separators === [] ? self::DEFAULT_SUBTITLE_SEPARATORS : $separators;
    }

    /**
     * Longueur minimale d'un préfixe retenu, jamais inférieure à 1.
     */
    public static function minPrefixLength(): int
    {
        return max(1, Config::integer('catalog.min_prefix_length', self::DEFAULT_MIN_PREFIX_LENGTH));
    }

    /**
     * Retire **un seul** article de tête, et jamais le dernier mot restant :
     * « The Thing » donne `thing`, « Le » seul reste `le`.
     */
    private static function withoutLeadingArticle(string $normalized): string
    {
        $words = explode(' ', $normalized);

        if (count($words) < 2 || ! in_array($words[0], self::LEADING_ARTICLES, true)) {
            return $normalized;
        }

        return implode(' ', array_slice($words, 1));
    }
}
