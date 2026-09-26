<?php

namespace Tests\Support\Scoring;

use LogicException;
use Tests\Support\I18n\FrontSource;

/**
 * Lecture de `resources/js/types/scoring.ts` (spec 80 § 20, lot L80-3) : les
 * déclarations de types du fichier et leurs champs de premier niveau, pour
 * comparer le miroir client aux charges que le serveur compose.
 *
 * Lecteur volontairement étroit — déclarations `interface` et `type`, corps à
 * accolades, unions d'objets —, commentaires retirés par
 * {@see FrontSource::withoutComments()}. Une forme qu'il ne sait pas lire le
 * fait échouer, jamais passer.
 */
final class ScoringTypes
{
    public static function path(): string
    {
        return resource_path('js/types/scoring.ts');
    }

    public static function source(): string
    {
        return FrontSource::withoutComments((string) file_get_contents(self::path()));
    }

    /**
     * Chaque déclaration `interface` ou `type` du fichier, exportée ou non :
     * nom → texte de la déclaration, du mot-clé `interface` ou `type` à sa fin.
     *
     * @return array<string, string>
     */
    public static function declarations(?string $source = null): array
    {
        $source ??= self::source();
        $declarations = [];

        preg_match_all('/(?:^|\n)\s*(?:export\s+)?(interface|type)\s+([A-Za-z_]\w*)/', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[2] as $position => [$name]) {
            [$kind, $offset] = $matches[1][$position];
            $declarations[$name] = self::declarationAt($source, (int) $offset, $kind);
        }

        return $declarations;
    }

    /**
     * Les champs de premier niveau de chaque objet d'une déclaration, dans
     * l'ordre : un objet pour une interface, un par membre pour une union
     * d'objets.
     *
     * @return list<list<string>>
     */
    public static function objectFields(string $declaration): array
    {
        $objects = [];
        $depth = 0;
        $buffer = '';

        foreach (mb_str_split($declaration) as $character) {
            if ($character === '{') {
                $depth++;

                if ($depth === 1) {
                    $buffer = '';

                    continue;
                }
            }

            if ($character === '}') {
                $depth--;

                if ($depth === 0) {
                    $objects[] = self::fieldNames($buffer);

                    continue;
                }
            }

            if ($depth >= 1) {
                // Seul le premier niveau compte : un objet imbriqué garde ses
                // accolades dans le tampon, et ses champs sont écartés par
                // fieldNames(), qui ne lit que la profondeur nulle du tampon.
                $buffer .= $character;
            }
        }

        return $objects;
    }

    /**
     * @return list<string>
     */
    private static function fieldNames(string $body): array
    {
        $names = [];
        $depth = 0;
        $flat = '';

        foreach (mb_str_split($body) as $character) {
            if ($character === '{' || $character === '(' || $character === '[') {
                $depth++;
            }

            if ($depth === 0) {
                $flat .= $character;
            }

            if ($character === '}' || $character === ')' || $character === ']') {
                $depth--;
            }
        }

        preg_match_all('/(?:^|[;\n{])\s*([A-Za-z_]\w*)\??\s*:/', $flat, $matches);

        foreach ($matches[1] as $name) {
            $names[] = $name;
        }

        return $names;
    }

    private static function declarationAt(string $source, int $offset, string $kind): string
    {
        $depth = 0;
        $opened = false;
        $length = strlen($source);

        for ($index = $offset; $index < $length; $index++) {
            $character = $source[$index];

            if ($character === '{' || $character === '(' || $character === '[') {
                $depth++;
                $opened = true;
            }

            if ($character === '}' || $character === ')' || $character === ']') {
                $depth--;

                if ($kind === 'interface' && $opened && $depth === 0) {
                    return substr($source, $offset, $index - $offset + 1);
                }
            }

            if ($kind === 'type' && $character === ';' && $depth === 0) {
                return substr($source, $offset, $index - $offset + 1);
            }
        }

        throw new LogicException("Déclaration illisible à l'octet {$offset} de types/scoring.ts.");
    }
}
