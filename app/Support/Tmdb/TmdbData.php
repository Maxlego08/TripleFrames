<?php

namespace App\Support\Tmdb;

/**
 * Le seul endroit où du `mixed` décodé devient une valeur typée.
 *
 * PHPStan est au niveau 7 : `json_decode` rend `mixed`, et un `mixed` qui
 * traverse un constructeur de DTO rend le reste du domaine non vérifiable. Tout
 * accès à une charge utile TMDB passe donc par ces gardes, et toute forme hors
 * contrat lève un {@see TmdbException} de cas `Malformed` — jamais un `null`
 * silencieux, qui écrirait une ligne `movie` vide sans que rien n'échoue.
 *
 * `$context` nomme le champ fautif (`movie.release_dates[2].iso_3166_1`), jamais
 * son contenu : une charge utile entière dans un journal est une fuite.
 *
 * @internal aux classes du namespace `App\Support\Tmdb`.
 */
final class TmdbData
{
    /**
     * Objet JSON — un tableau à clés de chaîne, et rien d'autre.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $context): array
    {
        if (! is_array($value)) {
            throw TmdbException::malformed($context, 'objet attendu, '.get_debug_type($value).' reçu.');
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw TmdbException::malformed($context, 'clé de chaîne attendue.');
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * Objet imbriqué obligatoire.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function objectAt(array $data, string $key, string $context): array
    {
        if (! array_key_exists($key, $data)) {
            throw TmdbException::malformed($context.'.'.$key, 'champ obligatoire absent.');
        }

        return self::object($data[$key], $context.'.'.$key);
    }

    /**
     * Objet imbriqué facultatif. Absent ou `null` rend `null` — c'est le cas de
     * `belongs_to_collection`, nul pour l'immense majorité des films.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function nullableObjectAt(array $data, string $key, string $context): ?array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        return self::object($value, $context.'.'.$key);
    }

    /**
     * Liste d'objets. Un champ d'`append_to_response` absent rend une liste
     * VIDE et jamais une erreur : l'appelant décide s'il l'exigeait.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function objectsAt(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw TmdbException::malformed($context.'.'.$key, 'liste attendue, '.get_debug_type($value).' reçu.');
        }

        $objects = [];
        $index = 0;

        foreach ($value as $item) {
            $objects[] = self::object($item, $context.'.'.$key.'['.$index.']');
            $index++;
        }

        return $objects;
    }

    /**
     * Chaîne obligatoire et non vide.
     *
     * @param  array<string, mixed>  $data
     */
    public static function text(array $data, string $key, string $context): string
    {
        $value = self::optionalText($data, $key, $context);

        if ($value === null) {
            throw TmdbException::malformed($context.'.'.$key, 'chaîne non vide obligatoire.');
        }

        return $value;
    }

    /**
     * Chaîne facultative. Absente, `null` ou vide rendent `null` : TMDB écrit
     * `""` là où il n'a pas de valeur (`release_date`, `iso_639_1` d'une image),
     * et une chaîne vide en base ne serait pas la même information qu'un `NULL`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function optionalText(array $data, string $key, string $context): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw TmdbException::malformed($context.'.'.$key, 'chaîne attendue, '.get_debug_type($value).' reçu.');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Chaîne brute, vide comprise — pour `certification`, dont le `""` est une
     * information : TMDB connaît la sortie et ne lui associe aucun classement.
     *
     * @param  array<string, mixed>  $data
     */
    public static function rawText(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? '';

        if (! is_string($value)) {
            throw TmdbException::malformed($context.'.'.$key, 'chaîne attendue, '.get_debug_type($value).' reçu.');
        }

        return trim($value);
    }

    /**
     * Entier obligatoire.
     *
     * @param  array<string, mixed>  $data
     */
    public static function integer(array $data, string $key, string $context): int
    {
        $value = self::optionalInteger($data, $key, $context);

        if ($value === null) {
            throw TmdbException::malformed($context.'.'.$key, 'entier obligatoire.');
        }

        return $value;
    }

    /**
     * Entier facultatif. Une chaîne de chiffres est acceptée : TMDB sérialise
     * certains identifiants en chaîne selon le point d'entrée.
     *
     * @param  array<string, mixed>  $data
     */
    public static function optionalInteger(array $data, string $key, string $context): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw TmdbException::malformed($context.'.'.$key, 'entier attendu, '.get_debug_type($value).' reçu.');
    }

    /**
     * Compteur TMDB (`vote_count`, `total_pages`) : entier facultatif borné à
     * zéro, parce qu'aucun d'eux n'est négatif et que les colonnes qui les
     * reçoivent sont des `unsignedInteger`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function counter(array $data, string $key, string $context): int
    {
        $value = self::optionalInteger($data, $key, $context) ?? 0;

        return max(0, $value);
    }

    /**
     * Booléen, `false` par défaut. `adult` absent ne vaut jamais « inconnu » :
     * le filtre de contenu n'est contournable par aucune voie, et une valeur
     * hors type lève au lieu d'être repliée en silence.
     *
     * @param  array<string, mixed>  $data
     */
    public static function flag(array $data, string $key, string $context): bool
    {
        $value = $data[$key] ?? false;

        if (! is_bool($value)) {
            throw TmdbException::malformed($context.'.'.$key, 'booléen attendu, '.get_debug_type($value).' reçu.');
        }

        return $value;
    }

    /**
     * Décimal, `0.0` par défaut.
     *
     * @param  array<string, mixed>  $data
     */
    public static function decimal(array $data, string $key, string $context): float
    {
        $value = $data[$key] ?? 0;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw TmdbException::malformed($context.'.'.$key, 'décimal attendu, '.get_debug_type($value).' reçu.');
    }

    /**
     * Liste d'entiers — `genre_ids` d'un résultat de `discover`.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function integers(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw TmdbException::malformed($context.'.'.$key, 'liste attendue, '.get_debug_type($value).' reçu.');
        }

        $integers = [];
        $index = 0;

        foreach ($value as $item) {
            $integers[] = self::integer(['value' => $item], 'value', $context.'.'.$key.'['.$index.']');
            $index++;
        }

        return $integers;
    }

    /**
     * Année d'une date TMDB (`2013-10-17`, `2013-10-17T00:00:00.000Z`), ou
     * `null` si la date est absente ou hors format. Projection de donnée et non
     * règle métier : `movie.release_year` est un `smallint`, et aucune règle du
     * projet ne lit une date complète.
     */
    public static function year(?string $date): ?int
    {
        if ($date === null || preg_match('/^(\d{4})-\d{2}-\d{2}/', $date, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];

        return $year >= 1870 && $year <= 2200 ? $year : null;
    }

    /**
     * Partie date d'un horodatage TMDB, au format `Y-m-d`, ou `null`. C'est
     * elle qui alimente `movie_certification.released_on`, sans quoi « la plus
     * récente fait foi » ne serait pas une règle vérifiable.
     */
    public static function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
