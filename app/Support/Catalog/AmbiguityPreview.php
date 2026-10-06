<?php

namespace App\Support\Catalog;

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Models\AnswerKey;
use App\Models\Movie;

/**
 * L'avertissement nominatif d'ambiguïté — spec 20 § 8.2, décision 13, E10-09.
 *
 * Avant qu'un curateur confirme une publication, l'écran lui montre les
 * formes que ce geste rendra ambiguës, et **à quels films elles
 * appartenaient**. Deux sens, une seule lecture :
 *
 * - (a) les clés **soumises à collision** du film — préfixe et sous-titre,
 *   {@see AnswerKeyKind::isCollisionChecked()} — dont la forme est portée par
 *   un autre film publié, sous quelque nature que ce soit : ce préfixe ne
 *   sera pas accepté pour ce film ;
 * - (b) les clés soumises à collision d'**autres** films publiés dont ce film
 *   porte la forme, quelle qu'en soit la nature : leur préfixe cessera d'être
 *   accepté dès la publication.
 *
 * Une forme portée des deux côtés sous une nature exacte ne figure pas : un
 * titre complet ou un alias qui désigne le film de la manche est toujours
 * accepté, homonyme publié ou non (spec 70).
 *
 * **L'ambiguïté se mesure sur le catalogue publié ENTIER**, jamais sur un
 * vivier : ni thème, ni `N`, ni drapeau de contenu, ni couverture n'entrent
 * dans la lecture — sinon accepter un préfixe révélerait combien d'épisodes
 * d'une saga sont dans le tirage (spec 10 § 3.5). Le même compte que
 * {@see AnswerKeyProjector::recomputeAmbiguity()} : `movie.availability =
 * published`, et rien d'autre.
 *
 * **Lecture seule, sans exception** (B5, invariant L2) : un projecteur qui
 * écrirait avant confirmation rendrait un préfixe refusé puis accepté en
 * pleine manche. Rien n'est écrit ici — le recompte appartient à la
 * publication, dans sa transaction ; l'aperçu est protégé par son empreinte
 * ({@see AmbiguityReport::digest()}).
 */
final class AmbiguityPreview
{
    /**
     * Ce que la publication de ce film rendra ambigu : toutes ses clés
     * projetées, confrontées au catalogue publié.
     *
     * `$alsoPublishing` : les films publiés **par le même geste** (« Publier
     * les films prêts », D59 du 06/10), comptés comme déjà publiés — sans
     * quoi l'aperçu du lot tairait les formes que ses films se disputent.
     *
     * @param  list<int>  $alsoPublishing
     */
    public function forPublication(Movie $movie, array $alsoPublishing = []): AmbiguityReport
    {
        /** @var array<string, AnswerKeyKind> $forms */
        $forms = [];

        $keys = AnswerKey::query()
            ->where('movie_id', $movie->id)
            ->get(['normalized', 'key_kind']);

        foreach ($keys as $key) {
            $forms[(string) $key->normalized] = $key->key_kind;
        }

        return $this->report($movie, $forms, $alsoPublishing);
    }

    /**
     * Même calcul pour un texte saisi sur un film publié (§ 9.1, § 9.2) : un
     * titre donne sa forme exacte, son préfixe et son sous-titre, dérivés par
     * {@see AnswerKeyNormalizer::prefixOf()} et
     * {@see AnswerKeyNormalizer::subtitleOf()} ; un alias, sa seule forme
     * exacte.
     *
     * La précédence du projecteur est tenue : une forme que le film porte
     * déjà sous une nature exacte le reste, et une forme déjà retenue par le
     * texte n'est pas reprise par une nature dérivée.
     */
    public function forText(Movie $movie, string $text, TextTarget $target): AmbiguityReport
    {
        /** @var array<string, AnswerKeyKind> $existing */
        $existing = [];

        foreach (AnswerKey::query()->where('movie_id', $movie->id)->get(['normalized', 'key_kind']) as $key) {
            $existing[(string) $key->normalized] = $key->key_kind;
        }

        /** @var list<array{string|null, AnswerKeyKind}> $candidates */
        $candidates = [[
            AnswerKeyNormalizer::normalize($text),
            $target === TextTarget::Title ? AnswerKeyKind::Title : AnswerKeyKind::Alias,
        ]];

        if ($target === TextTarget::Title) {
            $candidates[] = [AnswerKeyNormalizer::prefixOf($text), AnswerKeyKind::Prefix];
            $candidates[] = [AnswerKeyNormalizer::subtitleOf($text), AnswerKeyKind::Subtitle];
        }

        /** @var array<string, AnswerKeyKind> $forms */
        $forms = [];

        foreach ($candidates as [$form, $kind]) {
            if ($form === null || $form === '' || array_key_exists($form, $forms)) {
                continue;
            }

            $current = $existing[$form] ?? null;

            $forms[$form] = $current !== null && $current->isExact() ? $current : $kind;
        }

        return $this->report($movie, $forms);
    }

    /**
     * Confronte des formes du film au catalogue publié, hors ce film.
     *
     * @param  array<string, AnswerKeyKind>  $forms  forme normalisée → nature sous laquelle CE film la porte
     * @param  list<int>  $alsoPublishing  films comptés comme publiés (lot du même geste)
     */
    private function report(Movie $movie, array $forms, array $alsoPublishing = []): AmbiguityReport
    {
        if ($forms === []) {
            return new AmbiguityReport([]);
        }

        // Une forme purement numérique (« 1917 ») devient une clé entière en
        // PHP : chaque clé est rendue à sa chaîne avant d'aller en base.
        $normalized = array_map(static fn (int|string $form): string => (string) $form, array_keys($forms));

        $rows = AnswerKey::query()
            ->join('movie', 'movie.id', '=', 'answer_key.movie_id')
            ->whereIn('answer_key.normalized', $normalized)
            ->where('answer_key.movie_id', '!=', $movie->id)
            ->where(static function ($query) use ($alsoPublishing): void {
                $query->where('movie.availability', ContentAvailability::Published->value);

                if ($alsoPublishing !== []) {
                    $query->orWhereIn('movie.id', $alsoPublishing);
                }
            })
            ->toBase()
            ->get([
                'answer_key.normalized as normalized',
                'answer_key.key_kind as key_kind',
                'movie.id as carrier_id',
                'movie.title_original as title_original',
                'movie.release_year as release_year',
            ]);

        /** @var array<string, list<array{id: int, title_original: string, release_year: int|null, kind: AnswerKeyKind}>> $carriers */
        $carriers = [];

        foreach ($rows as $row) {
            $carriers[(string) $row->normalized][] = [
                'id' => (int) $row->carrier_id,
                'title_original' => (string) $row->title_original,
                'release_year' => $row->release_year === null ? null : (int) $row->release_year,
                'kind' => AnswerKeyKind::from((string) $row->key_kind),
            ];
        }

        $lines = [];

        foreach ($forms as $form => $kind) {
            $form = (string) $form;
            $movies = [];

            foreach ($carriers[$form] ?? [] as $carrier) {
                // (a) la clé de CE film est soumise à collision : tout
                // porteur publié la rend ambiguë. (b) sinon, seuls comptent
                // les porteurs dont la clé est elle-même soumise à collision.
                if (! $kind->isCollisionChecked() && ! $carrier['kind']->isCollisionChecked()) {
                    continue;
                }

                $movies[] = [...$carrier, 'kind' => $carrier['kind']->value];
            }

            if ($movies === []) {
                continue;
            }

            $lines[] = ['form' => $form, 'kinds' => [$kind->value], 'movies' => $movies];
        }

        return new AmbiguityReport($lines);
    }
}
