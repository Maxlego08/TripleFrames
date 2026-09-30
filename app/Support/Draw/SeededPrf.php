<?php

namespace App\Support\Draw;

use App\Models\Game;
use Generator;
use InvalidArgumentException;
use LogicException;

/**
 * PRF HMAC-SHA256 en mode compteur, réduction sans biais par rejet (spec 30
 * § 5, contrat C3, E10-40) — **seule source d'aléa dérivé de la graine**.
 *
 * Pourquoi une PRF et une graine de 256 bits : l'implémentation naïve
 * (`mt_srand(crc32($seed))` puis `shuffle`) n'a qu'un état de 32 bits ; le
 * tricheur qui observe le film de la manche 1 à la révélation essaie les 2³²
 * graines hors ligne et connaît la suite du tirage (spec 10 § 7.2). Et la même
 * graine sert un usage secret (le tirage) et un usage révélé (l'ordre du QCM) :
 * seule une PRF empêche les sorties publiques d'exposer le secret.
 *
 * Algorithme **normatif**, figé par les vecteurs de référence de
 * `SeededPrfTest` (§ 5.4) — une implémentation qui en diverge est fausse :
 *
 * - bloc `b = 0, 1, 2…` : `hash_hmac('sha256', $context->value.'#'.$b, $seed, true)`,
 *   la clé étant `game.draw_seed` **tel que stocké** (chaîne hexadécimale) ;
 * - chaque bloc est découpé par `unpack('N8')` en huit mots de 32 bits,
 *   consommés dans l'ordre ;
 * - `uniform(bound)` : `limit = 2³² − (2³² mod bound)`, tout mot `≥ limit` est
 *   rejeté, sinon `mot mod bound` — un `mod` nu favoriserait les petites
 *   valeurs dès que `bound` ne divise pas 2³² ;
 * - {@see self::index()} : premier `uniform(count)` du flux du contexte ;
 * - {@see self::permutation()} : Durstenfeld, `i` de `count − 1` à 1,
 *   `j = uniform(i + 1)`, tirages consommés successivement dans le flux.
 *
 * Fonction pure de (graine, contexte, `count`) : ni base, ni configuration, ni
 * horloge. Chaque appel repart du bloc 0 de son contexte ; deux appels
 * identiques rendent la même valeur.
 *
 * **Interdits** dans `App\Support\Draw` et dans tout chemin qui dépend de la
 * graine (§ 5.5) : `mt_srand`, `srand`, `rand`, `mt_rand`, `random_int`,
 * `array_rand`, `shuffle`, `str_shuffle`, `crc32`, `Arr::shuffle`,
 * `Arr::random`, `Collection::shuffle()` et `->random()` — prouvé par
 * `DrawBoundaryTest`. La graine est une propriété privée, masquée des traces
 * (`#[\SensitiveParameter]`) ; la classe n'est ni `Arrayable` ni
 * `JsonSerializable` (règle 3).
 */
final readonly class SeededPrf
{
    /** Octets de la graine : constante de sécurité de la spec 10 § 7.2. */
    public const int SEED_BYTES = 32;

    /** Taille de l'espace d'un mot : 2³². */
    private const int WORD_SPACE = 1 << 32;

    /** Borne haute de `count` : 2³¹. */
    private const int MAX_COUNT = 1 << 31;

    /**
     * @param  string  $seed  `game.draw_seed` tel que stocké : exactement
     *                        `2 × SEED_BYTES` caractères `[0-9a-f]`.
     *
     * @throws InvalidArgumentException Graine mal formée. Le message ne la cite jamais.
     */
    public function __construct(#[\SensitiveParameter] private string $seed)
    {
        if (preg_match('/\A[0-9a-f]{'.(self::SEED_BYTES * 2).'}\z/', $seed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'SeededPrf : la graine doit compter exactement %d caractères hexadécimaux minuscules.',
                self::SEED_BYTES * 2,
            ));
        }
    }

    /**
     * Une graine CSPRNG de {@see self::SEED_BYTES} octets, en hexadécimal
     * minuscule (§ 5.1).
     *
     * **Seul appel à `random_bytes` dans `App\Support\Draw` et
     * `App\Support\Answers`, et seule source de la graine** : `OpenGame`
     * (contrat C6, étape O4) l'appelle, jamais `bin2hex(random_bytes(32))` en
     * ligne.
     */
    public static function generateSeed(): string
    {
        return bin2hex(random_bytes(self::SEED_BYTES));
    }

    /** La PRF d'une partie lancée, sur `game.draw_seed` tel que stocké. */
    public static function forGame(Game $game): self
    {
        return new self($game->draw_seed);
    }

    /**
     * Un entier uniforme dans `[0, count)`, premier tirage du flux du contexte.
     *
     * @throws InvalidArgumentException `count` hors de `[1, 2³¹]`.
     */
    public function index(DrawContext $context, int $count): int
    {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException(sprintf(
                'SeededPrf::index : count = %d hors de [1, %d].',
                $count,
                self::MAX_COUNT,
            ));
        }

        return self::uniform($this->words($context), $count);
    }

    /**
     * Une permutation de `0..count − 1` (Durstenfeld), tirages consommés
     * successivement dans le flux du contexte. `count = 0` rend la liste vide
     * sans rien consommer.
     *
     * @return list<int>
     *
     * @throws InvalidArgumentException `count < 0` ou `count > 2³¹` (borne de la
     *                                  réduction : `i + 1 ≤ 2³¹`). En pratique,
     *                                  `count` est une taille de vivier : au-delà
     *                                  de la taille maximale d'un tableau PHP,
     *                                  `range()` lève `ValueError` — la borne
     *                                  2³¹ n'est pas une garantie d'usage.
     */
    public function permutation(DrawContext $context, int $count): array
    {
        if ($count < 0 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException(sprintf(
                'SeededPrf::permutation : count = %d hors de [0, %d].',
                $count,
                self::MAX_COUNT,
            ));
        }

        $items = $count === 0 ? [] : range(0, $count - 1);
        $words = $this->words($context);

        for ($i = $count - 1; $i >= 1; $i--) {
            $j = self::uniform($words, $i + 1);

            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        // Les échanges ne touchent que les clés 0..count − 1 : la liste reste
        // une liste, ce que l'analyse statique ne peut déduire des indices.
        return array_values($items);
    }

    /**
     * Le flux de mots de 32 bits d'un contexte, bloc après bloc, sans fin.
     *
     * @return Generator<int, int>
     */
    private function words(DrawContext $context): Generator
    {
        for ($block = 0; ; $block++) {
            $words = unpack('N8', hash_hmac('sha256', $context->value.'#'.$block, $this->seed, true));

            if ($words === false) {
                throw new LogicException('SeededPrf : un bloc HMAC-SHA256 compte toujours 32 octets.');
            }

            foreach ($words as $word) {
                yield (int) $word;
            }
        }
    }

    /**
     * Réduction sans biais par rejet : un entier uniforme dans `[0, bound)`,
     * consommé dans le flux.
     *
     * @param  Generator<int, int>  $words
     */
    private static function uniform(Generator $words, int $bound): int
    {
        $limit = self::WORD_SPACE - (self::WORD_SPACE % $bound);

        while (true) {
            $word = $words->current();
            $words->next();

            if ($word < $limit) {
                return $word % $bound;
            }
        }
    }
}
