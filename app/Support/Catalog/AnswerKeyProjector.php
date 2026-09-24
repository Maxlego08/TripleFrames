<?php

namespace App\Support\Catalog;

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\MovieTitle;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Le projecteur d'`answer_key` — unique propriétaire des formes normalisées et
 * des préfixes (§ 3.5).
 *
 * **Reconstruction par différence, jamais par purge et réinsertion.** Une ligne
 * qui existe encore garde son identifiant : `guess` conserve douze mois
 * l'identifiant de la clé retenue, et une reconstruction destructive ferait
 * danser tous les instantanés du journal à chaque publication.
 *
 * **Périmètre exact des clés d'un film** : `title_original` et
 * `title_original_latin` **inconditionnellement**, sans filtre de locale ;
 * `movie_title` et `alias` des seules locales **activées** ; plus les préfixes
 * dérivés des seuls **titres — jamais d'un alias** (décision 13), découpés au
 * premier séparateur de `config('catalog.subtitle_separators')` et retenus
 * au-delà de `config('catalog.min_prefix_length')`.
 *
 * **Précédence sur `(movie_id, normalized)`** : toute nature exacte l'emporte
 * sur `prefix`, donc une chaîne à la fois alias et préfixe reste toujours
 * acceptée. Sans ce dédoublonnage, l'UNIQUE `answer_key_norm_movie_uq` ferait
 * échouer l'import.
 *
 * **Synchrone et borné, jamais un job de fond.** Un job de fond laisserait une
 * fenêtre pendant laquelle un préfixe ambigu resterait accepté, et rendrait
 * impossible l'avertissement nominatif que le back-office doit au curateur
 * avant qu'il publie.
 *
 * Ce qui n'est **pas** ici et qui appartient à la spec 70 : l'invalidation du
 * cache de manche des chaînes d'un film (invariant L2). Aucun cache de
 * validation n'existe encore ; le jour où il existera, il s'invalide depuis
 * {@see self::touchedMovieIds()}, qui porte déjà l'ensemble borné.
 */
final class AnswerKeyProjector
{
    /**
     * Les films dont les clés ont changé au dernier passage — l'ensemble borné
     * que l'invalidation de cache de la spec 70 consommera.
     *
     * @var list<int>
     */
    private array $touchedMovieIds = [];

    /**
     * Reprojette les clés d'un film et recompte l'ambiguïté des préfixes
     * touchés. À appeler dans la **même transaction** que l'écriture des titres
     * et des alias.
     *
     * @return list<string> Les valeurs normalisées touchées — ajoutées, retirées ou requalifiées.
     */
    public function project(Movie $movie): array
    {
        $desired = $this->desiredKeys($movie);

        /** @var EloquentCollection<int, AnswerKey> $existing */
        $existing = AnswerKey::query()->where('movie_id', $movie->id)->get();

        $touched = [];

        foreach ($existing as $row) {
            if (! array_key_exists($row->normalized, $desired)) {
                $touched[] = $row->normalized;
                $row->delete();

                continue;
            }

            $target = $desired[$row->normalized];
            unset($desired[$row->normalized]);

            if ($row->key_kind === $target['key_kind'] && $row->source_locale === $target['source_locale']) {
                continue;
            }

            // Requalification : la chaîne reste acceptée, son identifiant reste
            // stable, seule sa nature change. C'est le cas d'un titre curé qui
            // reprend mot pour mot un préfixe déjà projeté — et la nature
            // décide de la soumission ou non à la règle de collision.
            $touched[] = $row->normalized;
            $row->key_kind = $target['key_kind'];
            $row->source_locale = $target['source_locale'];
            $row->save();
        }

        foreach ($desired as $normalized => $target) {
            $touched[] = $normalized;

            $row = new AnswerKey;
            $row->movie_id = $movie->id;
            $row->key_kind = $target['key_kind'];
            $row->source_locale = $target['source_locale'];
            $row->normalized = $normalized;
            $row->is_ambiguous = false;
            $row->save();
        }

        if (! in_array($movie->id, $this->touchedMovieIds, true)) {
            $this->touchedMovieIds[] = $movie->id;
        }

        $touched = array_values(array_unique($touched));

        $this->recomputeAmbiguity($touched);

        return $touched;
    }

    /**
     * Recompte `is_ambiguous` pour chaque valeur normalisée touchée : le nombre
     * de films `published` qui la portent, puis le drapeau posé sur **toutes**
     * les clés `prefix` égales — celles du film qu'on vient d'écrire comme
     * celles des films déjà en base.
     *
     * L'ambiguïté se mesure sur le catalogue `published` **entier**, jamais sur
     * le vivier du salon : sinon accepter un préfixe révélerait combien
     * d'épisodes de la saga sont dans le tirage. Elle n'est jamais rétroactive,
     * `guess` portant l'instantané de la règle appliquée.
     *
     * @param  list<string>  $normalizedValues
     */
    public function recomputeAmbiguity(array $normalizedValues): void
    {
        foreach (array_unique($normalizedValues) as $normalized) {
            $published = AnswerKey::query()
                ->join('movie', 'movie.id', '=', 'answer_key.movie_id')
                ->where('answer_key.normalized', $normalized)
                ->where('movie.availability', ContentAvailability::Published->value)
                ->distinct()
                ->count('answer_key.movie_id');

            $ambiguous = $published > 1;

            // Seules les lignes dont le drapeau CHANGE sont écrites : un recompte
            // sans effet ne réécrit rien, `updated_at` compris, et c'est ce qui
            // rend `catalog:reproject` idempotente jusqu'à l'horodatage.
            AnswerKey::query()
                ->where('normalized', $normalized)
                ->where('key_kind', AnswerKeyKind::Prefix->value)
                ->where('is_ambiguous', '!=', $ambiguous)
                ->update(['is_ambiguous' => $ambiguous]);
        }
    }

    /**
     * Les films touchés depuis la construction du projecteur.
     *
     * @return list<int>
     */
    public function touchedMovieIds(): array
    {
        return $this->touchedMovieIds;
    }

    /**
     * L'ensemble complet des clés que ce film **doit** porter, indexé par forme
     * normalisée pour que la précédence se tienne par construction : les
     * natures exactes sont posées d'abord et ne sont jamais écrasées par un
     * préfixe.
     *
     * @return array<string, array{key_kind: AnswerKeyKind, source_locale: string|null}>
     */
    private function desiredKeys(Movie $movie): array
    {
        /** @var list<array{string, AnswerKeyKind, string|null}> $exact */
        $exact = [[$movie->title_original, AnswerKeyKind::TitleOriginal, null]];

        if ($movie->title_original_latin !== null) {
            $exact[] = [$movie->title_original_latin, AnswerKeyKind::TitleLatin, null];
        }

        /** @var EloquentCollection<int, MovieTitle> $titles */
        $titles = MovieTitle::query()->where('movie_id', $movie->id)->get();

        /** @var list<string> $prefixSources */
        $prefixSources = [$movie->title_original];

        if ($movie->title_original_latin !== null) {
            $prefixSources[] = $movie->title_original_latin;
        }

        foreach ($titles as $title) {
            // Seules les locales ACTIVÉES entrent dans `answer_key` : une ligne
            // `movie_title` en `ko` reste un titre affichable, elle n'est pas une
            // chaîne acceptée. `title_original` et `title_original_latin`, eux,
            // entrent inconditionnellement — c'est l'asymétrie du § 3.5.
            if (Locale::tryFrom($title->locale) === null) {
                continue;
            }

            $exact[] = [$title->title, AnswerKeyKind::Title, $title->locale];
            $prefixSources[] = $title->title;
        }

        /** @var EloquentCollection<int, Alias> $aliases */
        $aliases = Alias::query()->where('movie_id', $movie->id)->get();

        foreach ($aliases as $alias) {
            if (Locale::tryFrom($alias->locale) === null) {
                continue;
            }

            $exact[] = [$alias->alias, AnswerKeyKind::Alias, $alias->locale];
        }

        /** @var array<string, array{key_kind: AnswerKeyKind, source_locale: string|null}> $desired */
        $desired = [];

        foreach ($exact as [$raw, $kind, $locale]) {
            $normalized = AnswerKeyNormalizer::normalize($raw);

            if ($normalized === '' || array_key_exists($normalized, $desired)) {
                continue;
            }

            $desired[$normalized] = ['key_kind' => $kind, 'source_locale' => $locale];
        }

        foreach ($prefixSources as $source) {
            $prefix = AnswerKeyNormalizer::prefixOf($source);

            if ($prefix === null || array_key_exists($prefix, $desired)) {
                continue;
            }

            // `source_locale` reste nulle sur un préfixe : elle est purement
            // traçante (§ 3.5) et une même chaîne peut naître de deux titres de
            // locales différentes. Lui inventer une locale mentirait sur la
            // provenance sans servir aucune requête.
            $desired[$prefix] = ['key_kind' => AnswerKeyKind::Prefix, 'source_locale' => null];
        }

        return $desired;
    }
}
