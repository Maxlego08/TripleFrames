<?php

namespace App\Support\Catalog;

/**
 * La forme de `theme.rule_value` là où elle n'est pas une valeur unique :
 * la règle studio multi-valeurs (spec 30 § 12.1, D43 du 01/10).
 *
 * Pour `theme_kind = studio` **seulement**, `rule_value` est une liste
 * d'identifiants de société TMDB, entiers positifs en décimal, joints par des
 * virgules sans espace, sans doublon, triés croissants à l'écriture
 * (`"429,128064,184898"`) ; un seul identifiant reste une liste d'un élément
 * (`"420"`). Le film appartient au thème s'il porte l'une quelconque de ces
 * sociétés.
 *
 * Seul endroit du dépôt qui lise ou écrive cette forme : l'évaluateur, le
 * seeder de plateforme et la requête du back-office passent par ici.
 */
final class ThemeRules
{
    /** Au plus huit sociétés par thème studio (spec 30 § 12.1). */
    public const int STUDIO_MAX_COMPANIES = 8;

    /**
     * Largeur de la colonne `theme.rule_value` (spec 10 § 3.7). Les
     * identifiants TMDB atteignant sept chiffres, la longueur se valide pour
     * elle-même, jamais déduite du seul nombre d'identifiants.
     */
    public const int RULE_VALUE_MAX_LENGTH = 64;

    private const string SEPARATOR = ',';

    /**
     * Les identifiants de société d'une règle studio, dans l'ordre écrit.
     *
     * Rend une liste **vide** pour une règle nulle ou mal formée — un segment
     * vide, un signe, un zéro, un espace : une règle illisible ne désigne
     * aucune société, et l'évaluateur la traite comme une entrée qui ne
     * satisfait jamais le thème, niée ou non.
     *
     * @return list<int>
     */
    public static function studioCompanyIds(?string $ruleValue): array
    {
        if ($ruleValue === null || $ruleValue === '') {
            return [];
        }

        $ids = [];

        foreach (explode(self::SEPARATOR, $ruleValue) as $segment) {
            if (! ctype_digit($segment) || (int) $segment < 1 || $segment[0] === '0') {
                return [];
            }

            $ids[] = (int) $segment;
        }

        return $ids;
    }

    /**
     * La forme canonique d'une règle studio : identifiants dédoublonnés, triés
     * croissants, joints par des virgules sans espace.
     *
     * @param  iterable<int>  $companyIds
     */
    public static function studioRuleValue(iterable $companyIds): string
    {
        $ids = [];

        foreach ($companyIds as $id) {
            $ids[$id] = $id;
        }

        sort($ids);

        return implode(self::SEPARATOR, $ids);
    }

    /**
     * Vrai si la règle studio respecte ses deux bornes et sa forme canonique.
     */
    public static function isValidStudioRule(string $ruleValue): bool
    {
        $ids = self::studioCompanyIds($ruleValue);

        return $ids !== []
            && count($ids) <= self::STUDIO_MAX_COMPANIES
            && strlen($ruleValue) <= self::RULE_VALUE_MAX_LENGTH
            && self::studioRuleValue($ids) === $ruleValue;
    }
}
