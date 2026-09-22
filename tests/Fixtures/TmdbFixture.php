<?php

namespace Tests\Fixtures;

use RuntimeException;

/**
 * Accès aux charges utiles TMDB figées du dépôt.
 *
 * Aucun test ne touche le réseau : les appels sont simulés par `Http::fake()`
 * et nourris par ces fichiers. Ils sont ANONYMISÉS — films inventés,
 * identifiants inventés, chemins de visuels inventés. Aucune image de jeu
 * réelle, aucun extrait de base de production n'entre ici (`100`, § 13.3).
 */
final class TmdbFixture
{
    /**
     * Corps JSON brut d'une réponse, tel qu'il partirait sur le réseau.
     */
    public static function json(string $name): string
    {
        $path = __DIR__.'/Tmdb/'.$name.'.json';

        if (! is_file($path)) {
            throw new RuntimeException('Fixture TMDB absente : ['.$name.'].');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Fixture TMDB illisible : ['.$name.'].');
        }

        return $contents;
    }

    /**
     * Charge utile décodée, pour éprouver un `fromArray()` sans passer par HTTP.
     *
     * @return array<string, mixed>
     */
    public static function array(string $name): array
    {
        $decoded = json_decode(self::json($name), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Fixture TMDB hors forme : ['.$name.'].');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
