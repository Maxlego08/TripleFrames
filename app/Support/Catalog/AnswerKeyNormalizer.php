<?php

namespace App\Support\Catalog;

use App\Support\Answers\AnswerRules;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * La forme normalisée d'une chaîne, ses clés dérivées et les primitives de
 * l'appariement (spec 70 § 5, contrat C12).
 *
 * **Implémentation UNIQUE du projet** (10 A5). Les clés et les saisies passent
 * par la **même** fonction ; `Database\Factories\AnswerKeyFactory` et le
 * projecteur y délèguent. Deux normaliseurs divergent silencieusement au
 * premier changement d'algorithme, et un catalogue projeté par l'un et apparié
 * par l'autre est complet sans qu'aucune réponse n'y valide jamais.
 *
 * **Agnostique de la langue** : aucune locale en paramètre, jamais. La saisie
 * n'a pas de langue déclarée et la locale du joueur n'arbitre aucune
 * acceptation (05 § Acceptation multilingue).
 *
 * L'alphabet de sortie de {@see self::normalize()} est `[a-z0-9 ]` (10 A6) :
 * sur cet alphabet, `utf8mb4_unicode_ci` et SQLite rendent le **même**
 * verdict, et la portabilité cesse d'être une propriété du moteur pour
 * devenir une propriété de la donnée.
 *
 * **Translittération par `Str::transliterate()`, `strict = false`** : la table
 * pur PHP de `voku/portable-ascii`, sans `ext-intl` (absente ici, et jamais
 * requise). Avec ces défauts, la bibliothèque n'emprunte jamais
 * `transliterator_transliterate()`, donc ajouter un jour `ext-intl` au serveur
 * ne change aucune sortie (§ 5.2).
 *
 * **Tout changement** d'étape, de liste d'articles, de règle romaine, de
 * séparateurs ou de longueur minimale — et toute mise à jour de bibliothèque
 * qui change une sortie des fixtures — incrémente
 * {@see AnswerRules::VERSION}, puis impose `catalog:reproject` (§ 12). Les
 * fixtures `tests/Fixtures/answers/normalizer-v{n}.php` et l'empreinte
 * {@see AnswerRules::fingerprint()} en sont les gardes.
 */
final class AnswerKeyNormalizer
{
    /**
     * Longueur maximale d'une forme normalisée : la largeur des colonnes
     * `answer_key.normalized` et `guess.*_normalized` (10 § 1.3). La
     * translittération peut allonger une chaîne (`ß` → `ss`) : clés et saisies
     * sont tronquées au même endroit, et aucune chaîne n'excède la colonne
     * (§ 5.5, E10-12).
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
     * Articles de tête retirés à la normalisation (§ 5.4) : l'**union** des
     * locales activées, appliquée sans notion de langue. Les articles d'autres
     * langues de catalogue (`el`, `il`, `der`, `die`, `das`) sont exclus :
     * « die » est un mot anglais (« Die Hard »).
     *
     * Elle n'est **pas** en configuration, contrairement aux deux réglages de
     * clé dérivée : un algorithme de normalisation modifiable sans
     * reprojection produit un index incohérent avec la règle qui l'a produit.
     * Elle entre dans l'empreinte par {@see self::leadingArticles()}.
     *
     * @var list<string>
     */
    private const array LEADING_ARTICLES = [
        'le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'the', 'a', 'an',
    ];

    /**
     * Notation romaine **stricte** sur les seules lettres `i`, `v`, `x`
     * (§ 5.3) : `m`, `d`, `c` et `l` ne sont jamais convertis. Nommée dans
     * l'empreinte par {@see AnswerRules::ROMAN_RULE}.
     */
    private const string ROMAN_NUMERAL_PATTERN = '/^(x{0,3})(ix|iv|v?i{0,3})$/';

    /** Plus petite valeur romaine convertie. */
    private const int ROMAN_MIN_VALUE = 1;

    /** Plus grande valeur romaine convertie (`xxxix`). */
    private const int ROMAN_MAX_VALUE = 39;

    /**
     * Longueur à partir de laquelle un jeton romain est converti en toute
     * position ; en dessous, une lettre seule ne l'est qu'en dernier jeton.
     */
    private const int ROMAN_ANYWHERE_MIN_LENGTH = 2;

    /**
     * La forme normalisée d'une clé **ou** d'une saisie (§ 5.1). Étapes, dans
     * cet ordre :
     *
     * 1. ponctuation et symboles → une espace, **avant** la translittération :
     *    sans cette passe, « WALL·E » donnerait `walle` quand « WALL E » donne
     *    `wall e` ;
     * 2. `Str::transliterate()` avec ses défauts (inconnu → `?`,
     *    `strict = false`) ;
     * 3. minuscules ;
     * 4. tout ce qui n'est pas `[a-z0-9]` → une espace, bords coupés ; `''` si
     *    rien ne subsiste ;
     * 5. chiffres romains, jeton par jeton ({@see self::withArabicNumerals()}) ;
     * 6. retrait d'**un seul** article de tête, jamais le dernier mot ;
     * 7. troncature **symétrique** à {@see self::MAX_NORMALIZED_LENGTH}.
     *
     * **Non idempotente** : « The The Thing » donne `the thing`, qui
     * renormalisé donnerait `thing`. Elle ne s'applique donc **jamais** à une
     * forme déjà normalisée.
     */
    public static function normalize(string $text): string
    {
        $unpunctuated = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text) ?? $text;

        $lowered = strtolower(Str::transliterate($unpunctuated));

        $spaced = trim(preg_replace('/[^a-z0-9]+/', ' ', $lowered) ?? '');

        if ($spaced === '') {
            return '';
        }

        $normalized = self::withoutLeadingArticle(self::withArabicNumerals($spaced));

        // La coupe peut tomber juste après un mot : l'espace finale est retirée,
        // sans quoi `utf8mb4_unicode_ci` (PAD SPACE) et SQLite jugeraient
        // différemment deux formes égales au blanc près (10 A6).
        return rtrim(substr($normalized, 0, self::MAX_NORMALIZED_LENGTH), ' ');
    }

    /**
     * La forme repliée d'un texte **qui n'est pas une réponse** (§ 5.9) — la
     * primitive sur laquelle 40 bâtit `NicknameNormalizer` (contrat C5).
     *
     * `Str::transliterate()`, minuscules, blancs compressés en une espace,
     * bords coupés. **Sans** retrait de ponctuation (`-` et `_` conservés),
     * **sans** article, **sans** chiffres romains, **sans** troncature :
     * idempotente et totale sur de l'UTF-8 valide. L'appelant refuse une forme
     * vide ou plus longue que sa colonne.
     *
     * Elle **n'entre pas** dans `validation_version` : elle ne décide d'aucune
     * acceptation.
     */
    public static function fold(string $text): string
    {
        $lowered = strtolower(Str::transliterate($text));

        return trim(preg_replace('/\s+/', ' ', $lowered) ?? $lowered);
    }

    /**
     * Le préfixe **normalisé** d'un titre : la partie avant le premier
     * séparateur de sous-titre (§ 5.7) — ou `null` s'il n'y a pas de
     * séparateur, si le préfixe est plus court que {@see self::minPrefixLength()},
     * ou s'il ne se distingue pas du titre entier.
     *
     * Ne s'applique **jamais** à un alias (décision 13) : c'est l'appelant qui
     * tient cette règle, la fonction ne sait pas d'où vient sa chaîne.
     */
    public static function prefixOf(string $title): ?string
    {
        $split = self::splitAtFirstSeparator($title);

        if ($split === null) {
            return null;
        }

        return self::derivedKey($split[0], $title);
    }

    /**
     * Le sous-titre **normalisé** d'un titre : la partie après le premier
     * séparateur, jusqu'au bout de la chaîne (§ 5.7, D23 du 23/09) — ou
     * `null` s'il n'y a pas de séparateur, s'il est trop court, s'il ne se
     * distingue pas du titre entier ou s'il égale le préfixe.
     *
     * Le découpage se fait au **premier** séparateur, et à lui seul : dans
     * « Star Wars : Épisode V - L'Empire contre-attaque », le sous-titre est
     * `episode v l empire contre attaque`. Même règle que
     * {@see self::prefixOf()} : jamais appliquée à un alias, par l'appelant.
     */
    public static function subtitleOf(string $title): ?string
    {
        $split = self::splitAtFirstSeparator($title);

        if ($split === null) {
            return null;
        }

        $subtitle = self::derivedKey($split[1], $title);

        if ($subtitle === null || $subtitle === self::normalize($split[0])) {
            return null;
        }

        return $subtitle;
    }

    /**
     * Une forme normalisée sans ses espaces (§ 5.8) : elle absorbe sans règle
     * spéciale `walle` contre `wall e` et `alien 3` contre `alien3`.
     */
    public static function compact(string $normalized): string
    {
        return str_replace(' ', '', $normalized);
    }

    /**
     * La suite des nombres d'une forme normalisée, **comparés comme entiers**
     * (`007` = `7`) : la règle des chiffres stricts (§ 6.3) n'admet la
     * tolérance qu'entre deux suites identiques.
     *
     * @return list<int>
     */
    public static function digits(string $normalized): array
    {
        preg_match_all('/\d+/', $normalized, $matches);

        return array_map(intval(...), $matches[0]);
    }

    /**
     * Distance d'édition entre deux formes normalisées (§ 5.8) : la plus petite
     * des distances de Levenshtein, coûts 1/1/1, sur les formes telles
     * quelles et sur leurs formes compactes.
     *
     * Sur l'alphabet ASCII de sortie, octet = caractère ; `levenshtein()` natif
     * parcourt toujours la matrice entière, sans sortie anticipée. C'est aussi
     * la primitive publique que 20 emploie pour suggérer des candidats
     * `movie_group`.
     */
    public static function distance(string $normalizedA, string $normalizedB): int
    {
        return min(
            levenshtein($normalizedA, $normalizedB),
            levenshtein(self::compact($normalizedA), self::compact($normalizedB)),
        );
    }

    /**
     * La liste fermée des articles de tête, lue par l'empreinte de la règle.
     *
     * @return list<string>
     */
    public static function leadingArticles(): array
    {
        return self::LEADING_ARTICLES;
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
     * Longueur minimale d'une clé dérivée retenue, préfixe comme sous-titre,
     * jamais inférieure à 1.
     */
    public static function minPrefixLength(): int
    {
        return max(1, Config::integer('catalog.min_prefix_length', self::DEFAULT_MIN_PREFIX_LENGTH));
    }

    /**
     * Le titre brut coupé au séparateur **le plus à gauche**, tous séparateurs
     * confondus — `[avant, après]`, ou `null` sans séparateur. Une occurrence
     * en position 0 est ignorée : un titre qui commence par un séparateur n'a
     * pas de préfixe. À position égale, le séparateur le plus long l'emporte,
     * pour que l'ordre de la configuration reste sans effet.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function splitAtFirstSeparator(string $title): ?array
    {
        if ($title === '') {
            return null;
        }

        $cut = null;
        $length = 0;

        foreach (self::subtitleSeparators() as $separator) {
            $position = mb_strpos($title, $separator, 1);

            if ($position === false) {
                continue;
            }

            $separatorLength = mb_strlen($separator);

            if ($cut === null || $position < $cut || ($position === $cut && $separatorLength > $length)) {
                $cut = $position;
                $length = $separatorLength;
            }
        }

        if ($cut === null) {
            return null;
        }

        return [mb_substr($title, 0, $cut), mb_substr($title, $cut + $length)];
    }

    /**
     * Une partie de titre normalisée, retenue comme clé dérivée seulement si
     * elle atteint la longueur minimale et se distingue du titre entier.
     */
    private static function derivedKey(string $part, string $title): ?string
    {
        $normalized = self::normalize($part);

        if (strlen($normalized) < self::minPrefixLength() || $normalized === self::normalize($title)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Convertit en chiffres arabes les jetons romains stricts de valeur 1 à 39
     * (§ 5.3) : en toute position à partir de deux lettres, et, pour une
     * lettre seule (`i`, `v`, `x`), **seulement** en dernier jeton d'une chaîne
     * d'au moins deux jetons.
     *
     * Couvre « Final Fantasy VII », « Rocky V », « Saw X » ; épargne « X-Men »,
     * « I, Robot » et le `v` d'« Épisode V » placé au milieu d'un titre. Une
     * conversion « fausse » (« Malcolm X » → `malcolm 10`) ne change aucun
     * appariement, puisqu'elle frappe la clé et la saisie à l'identique.
     */
    private static function withArabicNumerals(string $spaced): string
    {
        $tokens = explode(' ', $spaced);
        $lastIndex = count($tokens) - 1;

        foreach ($tokens as $index => $token) {
            $convertible = strlen($token) >= self::ROMAN_ANYWHERE_MIN_LENGTH
                || ($lastIndex >= 1 && $index === $lastIndex);

            if (! $convertible) {
                continue;
            }

            $value = self::romanValue($token);

            if ($value !== null) {
                $tokens[$index] = (string) $value;
            }
        }

        return implode(' ', $tokens);
    }

    /**
     * La valeur d'un jeton en notation romaine stricte, ou `null` s'il n'en
     * est pas un ou sort des bornes.
     */
    private static function romanValue(string $token): ?int
    {
        if (preg_match(self::ROMAN_NUMERAL_PATTERN, $token, $matches) !== 1) {
            return null;
        }

        $units = match ($matches[2]) {
            'ix' => 9,
            'iv' => 4,
            default => (str_starts_with($matches[2], 'v') ? 5 : 0) + substr_count($matches[2], 'i'),
        };

        $value = strlen($matches[1]) * 10 + $units;

        return $value >= self::ROMAN_MIN_VALUE && $value <= self::ROMAN_MAX_VALUE ? $value : null;
    }

    /**
     * Retire **un seul** article de tête, et jamais le dernier mot restant :
     * « The Thing » donne `thing`, « Le » seul reste `le`. Jamais après un
     * séparateur : retirer un article par segment casserait la saisie tapée
     * sans le deux-points.
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
