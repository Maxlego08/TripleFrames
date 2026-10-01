<?php

namespace App\Support\Admin;

use App\Enums\ThemeKind;
use App\Enums\TmdbTagKind;
use App\Models\Collection;
use App\Models\Movie;
use App\Models\MovieTmdbTag;
use App\Models\Theme;
use stdClass;

/**
 * Les valeurs de règle **présentes au catalogue** pour une nature de thème —
 * spec 20 § 9.6 (D43 du 01/10). Jamais un appel TMDB : une saga se crée sur
 * une collection déjà importée, un studio sur des sociétés portées par au
 * moins un film.
 *
 * Servies en prop `Inertia::optional` au seul rechargement partiel du
 * formulaire, **une nature à la fois** et en un nombre fixe de requêtes par
 * nature (deux au plus) : jamais une requête par valeur. Chaque option porte
 * son nombre de films et la clé du thème de même nature qui la désigne déjà —
 * une collection ou une société désignée ne se choisit plus (refus de
 * l'action sinon).
 *
 * Les sociétés sont nommées par `tmdb_company` (« Marvel Studios ») ; une
 * société sans ligne, et tout genre, restent nommés par leur identifiant :
 * les noms de genre TMDB ne sont pas stockés.
 */
final class ThemeRuleOptions
{
    /**
     * @return list<array{value: string, name: string|null, films: int, taken_by: string|null}>
     */
    public static function for(ThemeKind $kind): array
    {
        return match ($kind) {
            ThemeKind::Saga => self::collections(),
            ThemeKind::Studio => self::companies(),
            ThemeKind::Genre => self::genres(),
            ThemeKind::Decade => self::decades(),
            ThemeKind::Language => self::languages(),
            // Les cinq cas de `MovieDifficulty`, connus du front : rien à lire.
            ThemeKind::Difficulty => [],
        };
    }

    /**
     * @return list<array{value: string, name: string|null, films: int, taken_by: string|null}>
     */
    private static function collections(): array
    {
        $owners = ThemeDesignation::collectionOwners();
        $options = [];

        $collections = Collection::query()
            ->withCount('movies')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($collections as $collection) {
            $options[] = [
                'value' => (string) $collection->id,
                'name' => $collection->name,
                'films' => self::count($collection->getAttribute('movies_count')),
                'taken_by' => $owners[$collection->id] ?? null,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, name: string|null, films: int, taken_by: string|null}>
     */
    private static function companies(): array
    {
        $owners = ThemeDesignation::companyOwners();
        $options = [];

        $rows = MovieTmdbTag::query()
            ->toBase()
            ->leftJoin('tmdb_company', 'tmdb_company.tmdb_id', '=', 'movie_tmdb_tag.tmdb_tag_id')
            ->where('movie_tmdb_tag.tag_kind', TmdbTagKind::Company->value)
            ->groupBy('movie_tmdb_tag.tmdb_tag_id', 'tmdb_company.name')
            ->selectRaw('movie_tmdb_tag.tmdb_tag_id AS tag_id, tmdb_company.name AS name, COUNT(DISTINCT movie_tmdb_tag.movie_id) AS films')
            ->orderByDesc('films')
            ->orderBy('movie_tmdb_tag.tmdb_tag_id')
            ->get();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $id = self::count($row->tag_id);

            $options[] = [
                'value' => (string) $id,
                'name' => is_string($row->name) ? $row->name : null,
                'films' => self::count($row->films),
                'taken_by' => $owners[$id] ?? null,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, name: string|null, films: int, taken_by: string|null}>
     */
    private static function genres(): array
    {
        $owners = self::ruleOwners(ThemeKind::Genre);
        $options = [];

        $rows = MovieTmdbTag::query()
            ->toBase()
            ->where('tag_kind', TmdbTagKind::Genre->value)
            ->groupBy('tmdb_tag_id')
            ->selectRaw('tmdb_tag_id AS tag_id, COUNT(DISTINCT movie_id) AS films')
            ->orderBy('tmdb_tag_id')
            ->get();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $value = (string) self::count($row->tag_id);

            $options[] = [
                'value' => $value,
                'name' => null,
                'films' => self::count($row->films),
                'taken_by' => $owners[$value] ?? null,
            ];
        }

        return $options;
    }

    /**
     * Les décennies des années de sortie présentes, repliées en PHP : une
     * seule requête groupée par année, sans expression dans le `GROUP BY`.
     *
     * @return list<array{value: string, name: string|null, films: int, taken_by: string|null}>
     */
    private static function decades(): array
    {
        $owners = self::ruleOwners(ThemeKind::Decade);
        $films = [];

        $rows = Movie::query()
            ->toBase()
            ->whereNotNull('release_year')
            ->groupBy('release_year')
            ->selectRaw('release_year, COUNT(*) AS films')
            ->get();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $year = self::count($row->release_year);
            $decade = $year - $year % 10;
            $films[$decade] = ($films[$decade] ?? 0) + self::count($row->films);
        }

        ksort($films);
        $options = [];

        foreach ($films as $decade => $count) {
            $options[] = [
                'value' => (string) $decade,
                'name' => null,
                'films' => $count,
                'taken_by' => $owners[(string) $decade] ?? null,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, name: string|null, films: int, taken_by: string|null}>
     */
    private static function languages(): array
    {
        $owners = self::ruleOwners(ThemeKind::Language);
        $options = [];

        $rows = Movie::query()
            ->toBase()
            ->whereNotNull('original_language')
            ->groupBy('original_language')
            ->selectRaw('original_language, COUNT(*) AS films')
            ->orderByDesc('films')
            ->orderBy('original_language')
            ->get();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $value = is_string($row->original_language) ? $row->original_language : '';

            if (preg_match('/^[a-z]{2}$/', $value) !== 1) {
                continue;
            }

            $options[] = [
                'value' => $value,
                'name' => null,
                'films' => self::count($row->films),
                'taken_by' => $owners[$value] ?? null,
            ];
        }

        return $options;
    }

    /**
     * Valeur de règle → clé du premier thème de la nature qui la porte (une
     * valeur décimale devient une clé entière : PHP la convertit).
     *
     * @return array<array-key, string>
     */
    private static function ruleOwners(ThemeKind $kind): array
    {
        $owners = [];

        $themes = Theme::query()
            ->where('theme_kind', $kind)
            ->whereNotNull('rule_value')
            ->orderBy('id')
            ->get(['key', 'rule_value']);

        foreach ($themes as $theme) {
            if ($theme->rule_value !== null) {
                $owners[$theme->rule_value] ??= $theme->key;
            }
        }

        return $owners;
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
