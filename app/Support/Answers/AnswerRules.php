<?php

namespace App\Support\Answers;

use App\Support\Catalog\AnswerKeyNormalizer;

/**
 * La règle de validation d'une réponse, versionnée (spec 70 § 6.3 et § 12,
 * contrat C12).
 *
 * **Constantes de code, jamais des réglages d'hôte.** Une bonne réponse est
 * acceptée « quel que soit le joueur et quel que soit le salon » : la règle
 * d'acceptation est une propriété de l'instance (§ 1.2). Un hôte qui pourrait
 * élargir la tolérance ferait de la manche un jeu de vocabulaire et rendrait
 * incomparables les journaux de deux salons ; `guess` fige la distance, jamais
 * le seuil, et c'est `game.validation_version` qui dit sous quelle règle la
 * distance a été acceptée.
 *
 * **Tout changement** d'étape du normaliseur, de liste d'articles, de règle
 * romaine ou des chiffres, de barème de tolérance, de dérivation du
 * sous-titre, de séparateurs, de longueur minimale ou de marge de quasi-juste
 * — et toute mise à jour de bibliothèque qui change une sortie des fixtures —
 * incrémente {@see self::VERSION}, **sans exception**, puis impose
 * `catalog:reproject`. L'empreinte et les fixtures d'une version antérieure ne
 * sont jamais réécrites : on en ajoute de nouvelles
 * (`tests/Fixtures/answers/fingerprints.php`, `normalizer-v{n}.php`).
 *
 * **Ce que la version ne couvre pas** : le plancher de palier du QCM, qui
 * relève de `scoring_version` (contrat C13), et
 * {@see AnswerKeyNormalizer::fold()}, qui ne décide d'aucune acceptation.
 */
final class AnswerRules
{
    /** Écrite dans `game.validation_version` au lancement (contrat C6). */
    public const int VERSION = 2;

    /**
     * Barème de tolérance : longueur compacte maximale de la clé visée →
     * distance maximale admise. Au-delà du dernier palier,
     * {@see self::MAX_TOLERANCE}.
     *
     * @var array<int, int>
     */
    public const array TOLERANCE_STEPS = [4 => 0, 8 => 1, 15 => 2];

    /** Distance maximale admise au-delà du dernier palier du barème. */
    public const int MAX_TOLERANCE = 3;

    /**
     * Marge de quasi-juste au-delà de la tolérance (§ 11). Lue au J2
     * seulement ; déclarée dès la version 1 pour que l'arrivée de la mécanique
     * ne change ni une acceptation ni la version.
     */
    public const int NEAR_MISS_MARGIN = 2;

    /**
     * Noms de règle pour l'empreinte (§ 12). Une chaîne de règle ne décrit pas
     * l'algorithme, elle le **nomme** : un changement de code sans changement
     * de nom est attrapé par les fixtures, un changement de paramètre par
     * l'empreinte.
     */
    public const string ROMAN_RULE = 'ivx;1-39;len>=2|last-of->=2';

    public const string DIGITS_RULE = 'strict-int-sequence';

    public const string SUBTITLE_RULE = 'after-first-separator;titles-only;collision';

    public const string TRANSLITERATION = 'Str::transliterate;strict=false';

    /**
     * Étape (d′) du verdict, version 2 (D61 du 06/10) : un titre, un alias ou
     * un préfixe non partagé de la cible, suivi d'autres mots, le plus long
     * début porté par un film décidant, chiffres identiques.
     */
    public const string LEADING_RULE = 'leading-words;exact+unshared-prefix;longest-carried;same-digits';

    /**
     * Distance maximale admise pour une clé de longueur compacte donnée : la
     * valeur du premier palier dont la borne couvre la longueur, sinon
     * {@see self::MAX_TOLERANCE}. Les titres courts (« Up », « Ran », « It »)
     * restent stricts.
     *
     * **La longueur est celle de la clé, jamais celle de la saisie** : une
     * saisie longue et fausse n'achète aucune tolérance.
     */
    public static function tolerance(int $compactKeyLength): int
    {
        $steps = self::TOLERANCE_STEPS;
        ksort($steps);

        foreach ($steps as $maxLength => $tolerance) {
            if ($compactKeyLength <= $maxLength) {
                return $tolerance;
            }
        }

        return self::MAX_TOLERANCE;
    }

    /**
     * SHA-256 (hexadécimal) du JSON canonique des paramètres de la version
     * courante (§ 12). Le test d'empreinte fige, par version, la valeur
     * attendue : un changement de configuration ou de constante sans nouvelle
     * version fait échouer la CI.
     *
     * Canonique, pour que deux développeurs produisent la même empreinte :
     * clés d'objet triées récursivement en ordre de chaîne (le barème de
     * tolérance, à clés entières, est un objet), listes gardées dans leur
     * ordre, UTF-8 et barres obliques non échappés. Les séparateurs et la
     * longueur minimale sont les valeurs **effectives** du normaliseur, lues
     * de `config/catalog.php`.
     */
    public static function fingerprint(): string
    {
        $parameters = self::canonical([
            'version' => self::VERSION,
            'separators' => AnswerKeyNormalizer::subtitleSeparators(),
            'minLength' => AnswerKeyNormalizer::minPrefixLength(),
            'articles' => AnswerKeyNormalizer::leadingArticles(),
            'roman' => self::ROMAN_RULE,
            'digits' => self::DIGITS_RULE,
            'tolerance' => self::TOLERANCE_STEPS,
            'maxTolerance' => self::MAX_TOLERANCE,
            'subtitle' => self::SUBTITLE_RULE,
            'nearMissMargin' => self::NEAR_MISS_MARGIN,
            'maxNormalizedLength' => AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH,
            'transliteration' => self::TRANSLITERATION,
            'leading' => self::LEADING_RULE,
        ]);

        return hash('sha256', json_encode(
            $parameters,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Trie récursivement les clés des tableaux associatifs ; une liste garde
     * son ordre.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }
}
