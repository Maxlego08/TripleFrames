<?php

namespace Tests\Support\I18n;

use App\Support\I18n\TranslationDomains;

/**
 * Lecture statique des clés de traduction d'un fichier du front.
 *
 * Aucun DOM au jalon 1, ni en Pest ni en Vitest (C18 § 2.4) : ce que la page
 * appelle se prouve sur la source. Les clés sont des littéraux — `t()` est
 * typé par `TranslationKey`, et une clé construite à l'exécution est
 * interdite (spec 90 § 6.7) —, qu'elles soient passées à `t()`, écrites dans
 * une table `Record<…, TranslationKey>` ou transmises comme clé de coquille
 * (`Page.layout`, fil d'Ariane). Tout littéral de la forme `domaine.chemin`,
 * dont le domaine appartient à la liste close, est donc une clé appelée.
 *
 * Les commentaires sont retirés d'abord : un docblock qui cite une clé d'un
 * autre domaine (« le back-office porte `admin.footer.*` ») n'est pas un
 * appel.
 */
final class FrontSource
{
    /**
     * La source sans ses commentaires `//`, `/* … *\/` et `{/* … *\/}` ; les
     * chaînes, elles, sont gardées telles quelles, même si elles contiennent
     * `//` (une URL) ou `/*`.
     */
    public static function withoutComments(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $quote = null;

        for ($index = 0; $index < $length; $index++) {
            $char = $source[$index];
            $next = $source[$index + 1] ?? '';

            if ($quote !== null) {
                $out .= $char;

                if ($char === '\\') {
                    $out .= $next;
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '/' && $next === '/') {
                $end = strpos($source, "\n", $index);

                if ($end === false) {
                    break;
                }

                $index = $end - 1;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $index + 2);

                if ($end === false) {
                    break;
                }

                $index = $end + 1;

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Clés complètes écrites en littéral (guillemets simples ou doubles),
     * dédoublonnées, dans l'ordre d'apparition.
     *
     * @return list<string>
     */
    public static function literalKeys(string $source): array
    {
        preg_match_all(
            '/([\'"])((?:'.self::domainPattern().')(?:\.[a-z0-9_]+)+)\1/',
            self::withoutComments($source),
            $matches,
        );

        return array_values(array_unique($matches[2]));
    }

    /**
     * Domaines de toutes les clés du fichier, gabarits compris : un
     * `` `common.avatar.preset.${key}` `` interdit n'en désigne pas moins un
     * domaine appelé.
     *
     * @return list<string>
     */
    public static function referencedDomains(string $source): array
    {
        preg_match_all(
            '/[\'"`]('.self::domainPattern().')\.[a-z0-9_$]/',
            self::withoutComments($source),
            $matches,
        );

        $domains = array_values(array_unique($matches[1]));
        sort($domains);

        return $domains;
    }

    private static function domainPattern(): string
    {
        return implode('|', array_map(
            static fn (string $domain): string => preg_quote($domain, '/'),
            TranslationDomains::KNOWN,
        ));
    }
}
