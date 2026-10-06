<?php

namespace App\Support\Answers;

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Models\AnswerKey;
use App\Models\Round;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\ValueObjects\Answers\MatchResult;

/**
 * L'appariement d'une saisie texte au film de la manche (spec 70 § 6,
 * contrat C10) : deux lectures **inconditionnelles**, puis une décision pure.
 *
 * **Travail constant (invariant L4).** Pour toute saisie, dans cet ordre et
 * sans aucune branche qui dépende de la proximité :
 *
 * - (K) toutes les clés du film de la manche, par `answer_key_movie_idx` —
 *   sans filtre de disponibilité : un film dépublié en pleine manche garde ses
 *   clés et sa manche reste jugeable (10 § 3.5) ;
 * - (O) les films **publiés** qui portent exactement la forme soumise, sous
 *   toute nature, par `answer_key_norm_movie_uq` puis clé primaire ;
 * - (L) les films **publiés** qui portent un **début** de la saisie en mots
 *   entiers ({@see self::leadingForms()}, D61 du 06/10), lue pour toute
 *   saisie ;
 * - la distance à **toutes** les clés de K, sans sortie anticipée, y compris
 *   celles que les chiffres ou l'ambiguïté écarteront ({@see self::decide()}).
 *
 * Un refus pour préfixe ambigu qui ferait une lecture de plus qu'un refus
 * franc deviendrait chronométrable et confirmerait la saga, exactement ce que
 * la décision 13 cache.
 *
 * **Aucun cache au J1** (invariant L2, E10-16) : les clés du film et le test
 * d'homonymie sont relus à **chaque** soumission. C'est la réalisation de
 * « l'instant serveur de réception » de la décision 13 : un film publié en
 * pleine manche rend un préfixe ou un sous-titre partagé refusable dès la
 * soumission suivante, et rien n'est jamais réévalué après coup — `guess` fige
 * l'instantané (§ 6.5, § 9.3).
 *
 * **La locale du joueur n'entre dans aucune décision** (05 § Acceptation
 * multilingue) : `answer_key.source_locale` n'apparaît dans aucun `WHERE`.
 */
final class AnswerMatcher
{
    /**
     * Rang de départage des natures à distance égale (étape d) : toute nature
     * exacte, puis le préfixe, puis le sous-titre. Un ordre de tri, pas une
     * valeur de jeu.
     */
    private const int RANK_EXACT = 0;

    private const int RANK_PREFIX = 1;

    private const int RANK_SUBTITLE = 2;

    /**
     * Nombre de débuts de saisie lus par (L), toujours le même : la requête
     * garde un texte et un nombre de paramètres constants (invariant L4),
     * complétés par {@see self::LEADING_FILLER}. Seize mots couvrent tout
     * titre du catalogue ; au-delà, un début plus long ne désigne aucun film.
     */
    public const int LEADING_SLOTS = 16;

    /** Remplissage de (L) : une forme normalisée ne commence jamais par une espace. */
    private const string LEADING_FILLER = ' ';

    /**
     * Juge une saisie **déjà normalisée** (étape S5) contre le film de la
     * manche : les deux lectures K et O, toujours exécutées, puis
     * {@see self::decide()}.
     *
     * La saisie n'est jamais renormalisée ici : {@see AnswerKeyNormalizer::normalize()}
     * n'est pas idempotente (§ 5.5).
     */
    public function match(Round $round, string $submittedNormalized): MatchResult
    {
        // (K) Toutes les clés du film de la manche, disponibilité ignorée. Aucun
        // tri : le départage de `decide()` ne dépend pas de l'ordre des lignes.
        $targetKeys = array_values(
            AnswerKey::query()
                ->where('movie_id', $round->movie_id)
                ->get(['id', 'normalized', 'key_kind', 'is_ambiguous'])
                ->all(),
        );

        // (O) Les films PUBLIÉS qui portent exactement la forme soumise, sous
        // quelque nature que ce soit — le catalogue publié entier, jamais le
        // vivier du salon.
        $carriers = array_values(
            AnswerKey::query()
                ->join('movie', 'movie.id', '=', 'answer_key.movie_id')
                ->where('answer_key.normalized', $submittedNormalized)
                ->where('movie.availability', ContentAvailability::Published->value)
                ->toBase()
                ->pluck('answer_key.movie_id')
                ->map(static fn (mixed $movieId): int => (int) $movieId)
                ->all(),
        );

        // (L) Les films PUBLIÉS qui portent l'un des débuts de la saisie, en
        // mots entiers (D61 du 06/10) — lue pour toute saisie, même d'un seul
        // mot (liste vide) : le travail ne dépend pas de la forme soumise.
        $leadingCarriers = [];

        foreach (AnswerKey::query()
            ->join('movie', 'movie.id', '=', 'answer_key.movie_id')
            ->whereIn('answer_key.normalized', array_pad(self::leadingForms($submittedNormalized), self::LEADING_SLOTS, self::LEADING_FILLER))
            ->where('movie.availability', ContentAvailability::Published->value)
            ->toBase()
            ->get(['answer_key.normalized as normalized', 'answer_key.movie_id as movie_id']) as $row) {
            $leadingCarriers[(string) $row->normalized][] = (int) $row->movie_id;
        }

        return self::decide($submittedNormalized, $round->movie_id, $targetKeys, $carriers, $leadingCarriers);
    }

    /**
     * Les débuts de la saisie en mots entiers, du plus long au plus court, la
     * saisie entière exclue, sur ses {@see self::LEADING_SLOTS} premiers mots
     * au plus : « solo a star wars » → « solo a star », « solo a », « solo ».
     *
     * @return list<string>
     */
    public static function leadingForms(string $submittedNormalized): array
    {
        $words = $submittedNormalized === '' ? [] : explode(' ', $submittedNormalized);
        $forms = [];

        for ($count = min(count($words) - 1, self::LEADING_SLOTS); $count >= 1; $count--) {
            $forms[] = implode(' ', array_slice($words, 0, $count));
        }

        return $forms;
    }

    /**
     * La précédence du verdict texte (§ 6.2), fonction **pure** : elle ne lit
     * aucune table, et le verdict ne dépend que de `(s, K, O)`.
     *
     * | Étape | Condition | Issue |
     * |---|---|---|
     * | (a) | `s` égale une clé de nature exacte de la cible | acceptée, distance 0, homonymie publiée journalisée |
     * | (b) | `s` égale une clé dérivée de la cible, aucun autre film publié ne porte `s` | acceptée, distance 0 |
     * | (c) | un autre film publié porte `s` | refusée, même sous le seuil de tolérance |
     * | (d) | tolérance : clés exactes et dérivées non ambiguës, chiffres identiques, `distance ≤ tolerance(clé)` | acceptée, plus petite distance, puis nature, puis identifiant |
     * | (d′) | début en mots entiers (D61 du 06/10) : le **plus long** début de `s` porté par un film — cible ou publié — est un titre, un alias ou un préfixe de la cible ; préfixe porté par aucun autre film publié ; mêmes chiffres que `s` | acceptée, distance de `s` à la clé |
     * | (e) | sinon | refusée |
     *
     * Toutes les mesures — distance, chiffres, tolérance — sont prises pour
     * **toutes** les clés avant la première étape, sans sortie anticipée : le
     * travail ne dépend ni de la proximité, ni des chiffres, ni de
     * l'ambiguïté (§ 6.1, arbitrage 2 du § 18).
     *
     * @param  list<AnswerKey>  $targetKeys  le résultat de K : les clés du film de la manche
     * @param  list<int>  $publishedMovieIdsCarryingSubmitted  le résultat de O : les films publiés qui portent `s`
     * @param  array<string, list<int>>  $leadingCarriers  le résultat de L : début de `s` → films publiés qui le portent
     */
    public static function decide(
        string $submittedNormalized,
        int $targetMovieId,
        array $targetKeys,
        array $publishedMovieIdsCarryingSubmitted,
        array $leadingCarriers = [],
    ): MatchResult {
        // O≠ : un AUTRE film publié porte exactement la forme soumise.
        $carriedByOtherPublished = array_filter(
            $publishedMovieIdsCarryingSubmitted,
            static fn (int $movieId): bool => $movieId !== $targetMovieId,
        ) !== [];

        $submittedDigits = AnswerKeyNormalizer::digits($submittedNormalized);

        /** @var list<array{key: AnswerKey, equal: bool, distance: int, sameDigits: bool, tolerance: int}> $measured */
        $measured = [];

        foreach ($targetKeys as $key) {
            $normalized = (string) $key->normalized;

            $measured[] = [
                'key' => $key,
                'equal' => $normalized === $submittedNormalized,
                'distance' => AnswerKeyNormalizer::distance($submittedNormalized, $normalized),
                'sameDigits' => AnswerKeyNormalizer::digits($normalized) === $submittedDigits,
                'tolerance' => AnswerRules::tolerance(strlen(AnswerKeyNormalizer::compact($normalized))),
            ];
        }

        // (a) Un titre complet ou un alias de la cible est TOUJOURS accepté,
        // homonyme publié ou non : l'homonymie est seulement journalisée.
        $exact = self::best(array_filter(
            $measured,
            static fn (array $measure): bool => $measure['equal'] && $measure['key']->key_kind->isExact(),
        ));

        if ($exact !== null) {
            return self::accepted($submittedNormalized, $exact['key'], 0, $carriedByOtherPublished);
        }

        // (b) Une clé dérivée de la cible, acceptée seulement si aucun autre film
        // publié ne porte la forme — ambiguïté FRAÎCHE, lue par O, jamais le
        // drapeau dénormalisé.
        $derived = self::best(array_filter(
            $measured,
            static fn (array $measure): bool => $measure['equal'] && $measure['key']->key_kind->isCollisionChecked(),
        ));

        if ($derived !== null && ! $carriedByOtherPublished) {
            return self::accepted($submittedNormalized, $derived['key'], 0, false);
        }

        // (c) La garde exacte : une saisie qui désigne exactement un autre film
        // publié est refusée, préfixe ou sous-titre ambigu compris, et même sous
        // le seuil de tolérance de la cible.
        if ($carriedByOtherPublished) {
            return MatchResult::rejected($submittedNormalized);
        }

        // (d) La tolérance. Une clé dérivée ambiguë n'est jamais candidate : une
        // faute de frappe ne passerait pas là où la forme exacte est refusée.
        // Une suite de chiffres différente n'est jamais tolérée.
        $tolerated = self::best(array_filter(
            $measured,
            static fn (array $measure): bool => ($measure['key']->key_kind->isExact() || ! $measure['key']->is_ambiguous)
                && $measure['sameDigits']
                && $measure['distance'] <= $measure['tolerance'],
        ));

        if ($tolerated !== null) {
            return self::accepted($submittedNormalized, $tolerated['key'], $tolerated['distance'], false);
        }

        // (d′) Le titre, l'alias ou le préfixe de la cible suivi d'autres mots
        // (D61 du 06/10) : « solo a star wars movie » désigne « Solo ». Seul
        // le PLUS LONG début porté par un film compte — « alien covenant le
        // film » désigne « Alien: Covenant », jamais « Alien » ; un préfixe
        // partagé par un autre film publié n'est pas candidat ; une suite de
        // chiffres en plus (« rocky 2 … ») n'est jamais tolérée.
        $leading = self::leading($submittedNormalized, $targetMovieId, $targetKeys, $leadingCarriers, $submittedDigits);

        if ($leading !== null) {
            return self::accepted(
                $submittedNormalized,
                $leading,
                AnswerKeyNormalizer::distance($submittedNormalized, (string) $leading->normalized),
                false,
            );
        }

        // (e)
        return MatchResult::rejected($submittedNormalized);
    }

    /**
     * La clé de la cible désignée par le plus long début de la saisie, ou
     * `null` — étape (d′), fonction pure.
     *
     * @param  list<AnswerKey>  $targetKeys
     * @param  array<string, list<int>>  $leadingCarriers
     * @param  list<int>  $submittedDigits
     */
    private static function leading(
        string $submittedNormalized,
        int $targetMovieId,
        array $targetKeys,
        array $leadingCarriers,
        array $submittedDigits,
    ): ?AnswerKey {
        /** @var array<string, list<AnswerKey>> $byForm */
        $byForm = [];

        foreach ($targetKeys as $key) {
            $byForm[(string) $key->normalized][] = $key;
        }

        foreach (self::leadingForms($submittedNormalized) as $form) {
            $own = $byForm[$form] ?? [];
            $others = array_values(array_filter(
                $leadingCarriers[$form] ?? [],
                static fn (int $movieId): bool => $movieId !== $targetMovieId,
            ));

            if ($own === [] && $others === []) {
                continue;
            }

            // Le plus long début porté par un film décide, et lui seul.
            $candidates = array_values(array_filter(
                $own,
                static fn (AnswerKey $key): bool => ($key->key_kind->isExact()
                        || ($key->key_kind === AnswerKeyKind::Prefix && $others === []))
                    && AnswerKeyNormalizer::digits($form) === $submittedDigits,
            ));

            if ($candidates === []) {
                return null;
            }

            usort($candidates, static fn (AnswerKey $a, AnswerKey $b): int => [$a->key_kind->isExact() ? 0 : 1, $a->id] <=> [$b->key_kind->isExact() ? 0 : 1, $b->id]);

            return $candidates[0];
        }

        return null;
    }

    /**
     * L'instantané d'une acceptation : la clé retenue, sa forme, sa nature
     * d'appariement dérivée par {@see AnswerKeyKind::toMatchKind()}, la
     * distance et l'homonymie publiée (§ 6.4, § 9.3).
     */
    private static function accepted(
        string $submittedNormalized,
        AnswerKey $key,
        int $editDistance,
        bool $prefixWasAmbiguous,
    ): MatchResult {
        return new MatchResult(
            accepted: true,
            submittedNormalized: $submittedNormalized,
            answerKeyId: $key->id,
            answerKeyNormalized: (string) $key->normalized,
            matchKind: $key->key_kind->toMatchKind(),
            editDistance: $editDistance,
            prefixWasAmbiguous: $prefixWasAmbiguous,
        );
    }

    /**
     * Le meilleur candidat, ou `null` : la plus petite distance, puis la nature
     * exacte, puis `prefix`, puis `subtitle`, puis `answer_key.id` croissant
     * (arbitrage 8 du § 18). Déterministe quel que soit l'ordre de K.
     *
     * @param  array<int, array{key: AnswerKey, equal: bool, distance: int, sameDigits: bool, tolerance: int}>  $candidates
     * @return array{key: AnswerKey, equal: bool, distance: int, sameDigits: bool, tolerance: int}|null
     */
    private static function best(array $candidates): ?array
    {
        $best = null;

        foreach ($candidates as $candidate) {
            if ($best === null || self::order($candidate) < self::order($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * La clé de tri d'un candidat, comparée terme à terme.
     *
     * @param  array{key: AnswerKey, equal: bool, distance: int, sameDigits: bool, tolerance: int}  $candidate
     * @return array{int, int, int}
     */
    private static function order(array $candidate): array
    {
        // Exhaustif, sans bras par défaut : une nature ajoutée à l'enum devra
        // recevoir son rang ici plutôt qu'hériter en silence de celui d'une autre.
        $rank = match ($candidate['key']->key_kind) {
            AnswerKeyKind::TitleOriginal,
            AnswerKeyKind::TitleLatin,
            AnswerKeyKind::Title,
            AnswerKeyKind::Alias => self::RANK_EXACT,
            AnswerKeyKind::Prefix => self::RANK_PREFIX,
            AnswerKeyKind::Subtitle => self::RANK_SUBTITLE,
        };

        return [$candidate['distance'], $rank, $candidate['key']->id];
    }
}
