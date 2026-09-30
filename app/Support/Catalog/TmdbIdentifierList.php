<?php

namespace App\Support\Catalog;

/**
 * La lecture d'un collage — identifiants nus ou URL TMDB, mêlés.
 *
 * C'est l'entrée de la **voie d'exception** (§ 9.2, décision 11) : la liste
 * d'amorçage d'environ 200 identifiants est un fichier livré au dépôt, collé
 * ici en un geste — ni table, ni colonne.
 *
 * Ce que la lecture accepte, parce que c'est ce qu'un curateur colle vraiment :
 * un identifiant par ligne, plusieurs par ligne séparés par des virgules ou des
 * espaces, une URL `themoviedb.org/movie/1234-un-slug`, la même en `www.`, avec
 * ou sans préfixe de langue, une ligne de commentaire commençant par `#`.
 *
 * L'ordre du collage est **conservé** et les doublons retirés au premier
 * passage : le curateur doit retrouver sa liste, et un identifiant collé deux
 * fois ne doit pas coûter deux appels de détail.
 */
final class TmdbIdentifierList
{
    /**
     * Les identifiants d'un collage, dans l'ordre, dédoublonnés.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function parse(array $lines): array
    {
        /** @var list<int> $identifiers */
        $identifiers = [];

        foreach ($lines as $line) {
            foreach (self::identifiersIn($line) as $identifier) {
                if (! in_array($identifier, $identifiers, true)) {
                    $identifiers[] = $identifier;
                }
            }
        }

        return $identifiers;
    }

    /**
     * Les fragments d'une ligne qui ne sont ni un commentaire ni du vide, et
     * dont on sait extraire un identifiant.
     *
     * @return list<int>
     */
    private static function identifiersIn(string $line): array
    {
        $stripped = trim(preg_replace('/#.*$/u', '', $line) ?? '');

        if ($stripped === '') {
            return [];
        }

        /** @var list<int> $identifiers */
        $identifiers = [];

        foreach (preg_split('/[\s,;]+/u', $stripped) ?: [] as $token) {
            $identifier = self::identifierIn($token);

            if ($identifier !== null) {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;
    }

    /**
     * Un identifiant, ou `null` si le fragment n'en porte aucun.
     *
     * L'URL TMDB d'un film est `…/movie/<id>-<slug>` : c'est le segment qui
     * suit `/movie/` qui fait foi, et le slug est ignoré — il contient le titre,
     * qui n'a aucune valeur d'identité et change à chaque renommage.
     */
    private static function identifierIn(string $token): ?int
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        if (ctype_digit($token)) {
            return self::positive((int) $token);
        }

        if (preg_match('#/movie/(\d+)#', $token, $matches) === 1) {
            return self::positive((int) $matches[1]);
        }

        return null;
    }

    private static function positive(int $identifier): ?int
    {
        return $identifier > 0 ? $identifier : null;
    }
}
