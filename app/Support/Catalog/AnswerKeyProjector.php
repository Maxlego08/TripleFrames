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
 * des clés dérivées, préfixes et sous-titres (10 § 3.5, spec 70 § 5.7).
 *
 * **Reconstruction par différence, jamais par purge et réinsertion.** Une ligne
 * qui existe encore garde son identifiant : `guess` conserve douze mois
 * l'identifiant de la clé retenue, et une reconstruction destructive ferait
 * danser tous les instantanés du journal à chaque publication.
 *
 * **Périmètre exact des clés d'un film** : `title_original` et
 * `title_original_latin` **inconditionnellement**, sans filtre de locale ;
 * `movie_title` et `alias` des seules locales **activées** ; plus les clés
 * dérivées des seuls **titres — jamais d'un alias** (décision 13, D23 du
 * 23/09), découpées au premier séparateur de
 * `config('catalog.subtitle_separators')` : le **préfixe** (partie avant) et
 * le **sous-titre** (partie après), chacun retenu au-delà de
 * `config('catalog.min_prefix_length')` s'il diffère du titre entier, le
 * sous-titre différant aussi du préfixe ({@see AnswerKeyNormalizer::prefixOf()},
 * {@see AnswerKeyNormalizer::subtitleOf()}).
 *
 * **Précédence sur `(movie_id, normalized)`** : toute nature exacte l'emporte
 * sur `prefix`, qui l'emporte sur `subtitle`. Une chaîne à la fois alias et
 * clé dérivée reste donc toujours acceptée, et une chaîne à la fois préfixe
 * d'un titre et sous-titre d'un autre relève de la règle du préfixe. Sans ce
 * dédoublonnage, l'UNIQUE `answer_key_norm_movie_uq` ferait échouer l'import.
 *
 * **Synchrone et borné, jamais un job de fond.** Un job de fond laisserait une
 * fenêtre pendant laquelle une clé dérivée ambiguë resterait acceptée, et
 * rendrait impossible l'avertissement nominatif que le back-office doit au
 * curateur avant qu'il publie.
 *
 * Aucun cache de validation n'existe au J1 : `AnswerMatcher` relit les clés du
 * film et le test d'homonymie à **chaque** soumission (spec 70 § 6.1, E10-16),
 * et l'invariant L2 est tenu par construction.
 */
final class AnswerKeyProjector
{
    /**
     * Les films dont les clés ont été reprojetées par cette instance.
     *
     * @var list<int>
     */
    private array $touchedMovieIds = [];

    /**
     * Reprojette les clés d'un film et recompte l'ambiguïté des clés dérivées
     * touchées. À appeler dans la **même transaction** que l'écriture des
     * titres et des alias.
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
            // reprend mot pour mot une clé dérivée déjà projetée, ou d'un
            // sous-titre qui devient le préfixe d'un autre titre — et la nature
            // décide de la soumission ou non à la règle de collision.
            $touched[] = $row->normalized;
            $row->key_kind = $target['key_kind'];
            $row->source_locale = $target['source_locale'];

            // Une nature exacte n'est jamais ambiguë (10 § 3.5) : le drapeau
            // d'une ancienne clé dérivée ne lui survit pas. Le sens inverse est
            // tenu par le recompte ci-dessous, la valeur étant touchée.
            if (! $target['key_kind']->isCollisionChecked()) {
                $row->is_ambiguous = false;
            }

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
     * Recompte `is_ambiguous` pour chaque valeur normalisée touchée : les films
     * `published` qui la portent, **sous quelque nature que ce soit**, puis le
     * drapeau posé sur **toutes** les clés dérivées égales, `prefix` comme
     * `subtitle` — celles du film qu'on vient d'écrire comme celles des films
     * déjà en base.
     *
     * Le drapeau se lit **clé par clé** (10 § 3.5) : vrai si **un autre** film
     * `published` porte la forme. Une clé d'un film publié l'est donc dès que
     * deux films publiés la portent ; une clé d'un film non publié — brouillon,
     * ou dépublié en pleine manche, dont les clés restent jugeables (spec 70
     * § 6.1, lecture K) — l'est dès qu'un seul film publié la porte. Sans cette
     * distinction, le sous-titre d'un film dépublié que porte un film publié
     * resterait candidat à la tolérance (étape d) alors que sa forme exacte est
     * refusée par la garde (c).
     *
     * L'ambiguïté se mesure sur le catalogue `published` **entier**, jamais sur
     * le vivier du salon : sinon accepter une clé dérivée révélerait combien
     * d'épisodes de la saga sont dans le tirage. Elle n'est jamais rétroactive,
     * `guess` portant l'instantané de la règle appliquée. Le drapeau ne sert
     * qu'à la tolérance (spec 70 § 6.2, étape d) : l'acceptation exacte d'une
     * clé dérivée relit l'homonymie fraîche à chaque soumission.
     *
     * @param  list<string>  $normalizedValues
     */
    public function recomputeAmbiguity(array $normalizedValues): void
    {
        $derivedKinds = AnswerKeyKind::collisionCheckedValues();

        foreach (array_unique($normalizedValues) as $normalized) {
            // L'UNIQUE `answer_key_norm_movie_uq (normalized, movie_id)` garantit
            // qu'un film y figure au plus une fois : aucun `DISTINCT` n'est utile.
            /** @var list<int> $carriers */
            $carriers = AnswerKey::query()
                ->join('movie', 'movie.id', '=', 'answer_key.movie_id')
                ->where('answer_key.normalized', $normalized)
                ->where('movie.availability', ContentAvailability::Published->value)
                ->pluck('answer_key.movie_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            $published = count($carriers);

            // Seules les lignes dont le drapeau CHANGE sont écrites : un recompte
            // sans effet ne réécrit rien, `updated_at` compris, et c'est ce qui
            // rend `catalog:reproject` idempotente jusqu'à l'horodatage.
            //
            // Clés des films publiés qui la portent : ambiguës si un AUTRE film
            // publié la porte aussi.
            $this->flagDerivedKeys($normalized, $derivedKinds, $carriers, true, $published > 1);

            // Clés des films non publiés : ambiguës dès qu'UN film publié la porte.
            $this->flagDerivedKeys($normalized, $derivedKinds, $carriers, false, $published >= 1);
        }
    }

    /**
     * Pose `is_ambiguous` sur les clés dérivées d'une forme, portées par les
     * films publiés qui la portent (`$amongCarriers`) ou par tous les autres.
     *
     * @param  list<string>  $derivedKinds
     * @param  list<int>  $carriers
     */
    private function flagDerivedKeys(
        string $normalized,
        array $derivedKinds,
        array $carriers,
        bool $amongCarriers,
        bool $ambiguous,
    ): void {
        $query = AnswerKey::query()
            ->where('normalized', $normalized)
            ->whereIn('key_kind', $derivedKinds)
            ->where('is_ambiguous', '!=', $ambiguous);

        if ($amongCarriers) {
            $query->whereIn('movie_id', $carriers);
        } else {
            $query->whereNotIn('movie_id', $carriers);
        }

        $query->update(['is_ambiguous' => $ambiguous]);
    }

    /**
     * Les films reprojetés depuis la construction du projecteur.
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
     * natures exactes sont posées d'abord, puis les préfixes de **tous** les
     * titres, puis leurs sous-titres, et une forme déjà posée n'est jamais
     * écrasée.
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

        // Les titres dont dérivent préfixes et sous-titres — jamais un alias
        // (décision 13, D23 du 23/09).
        /** @var list<string> $derivationSources */
        $derivationSources = [$movie->title_original];

        if ($movie->title_original_latin !== null) {
            $derivationSources[] = $movie->title_original_latin;
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
            $derivationSources[] = $title->title;
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

        // Une passe par nature dérivée, et non une par titre : le préfixe d'un
        // titre doit l'emporter sur le sous-titre d'un autre, quel que soit
        // l'ordre des titres.
        /** @var list<array{AnswerKeyKind, callable(string): (string|null)}> $derivations */
        $derivations = [
            [AnswerKeyKind::Prefix, AnswerKeyNormalizer::prefixOf(...)],
            [AnswerKeyKind::Subtitle, AnswerKeyNormalizer::subtitleOf(...)],
        ];

        foreach ($derivations as [$kind, $derive]) {
            foreach ($derivationSources as $source) {
                $derived = $derive($source);

                if ($derived === null || array_key_exists($derived, $desired)) {
                    continue;
                }

                // `source_locale` reste nulle sur une clé dérivée : elle est
                // purement traçante (§ 3.5) et une même chaîne peut naître de
                // deux titres de locales différentes. Lui inventer une locale
                // mentirait sur la provenance sans servir aucune requête.
                $desired[$derived] = ['key_kind' => $kind, 'source_locale' => null];
            }
        }

        return $desired;
    }
}
