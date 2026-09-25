<?php

namespace App\Console\Commands;

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Support\Answers\AnswerMatcher;
use App\Support\Answers\AnswerRules;
use App\Support\Catalog\AnswerKeyNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Le rapport de collisions — spec 70 § 13.1, contrat C12, lot L70-11.
 *
 * **Outil du porteur, jamais sur le chemin du curateur** (D10 du 23/09) :
 * au J1, aucun geste de curation ne dépend d'une commande artisan ; la
 * suppression d'un alias fautif passe par l'écran d'alias de 20, et
 * l'affichage du rapport en back-office est un lot J2 de 20. **En lecture
 * seule** : il n'écrit rien, nulle part.
 *
 * Sur les clés `answer_key` des seuls films **publiés** — le catalogue publié
 * entier, celui que lit la lecture O de {@see AnswerMatcher}, jamais le vivier
 * d'un salon —, il liste chaque couple de clés de deux films distincts :
 *
 * - (i) les formes **exactes** partagées : la même forme normalisée portée
 *   par au moins deux films publiés, dont l'une au moins de **nature exacte**
 *   ({@see AnswerKeyKind::isExact()}) — homonyme, remake, ou alias contre la
 *   clé dérivée identique d'un autre film —, **alias TMDB compris** :
 *   `answer_key` ne porte pas l'origine d'un alias, aucun n'est donc écarté
 *   (70 § 4.3, réponse à Q70-2 sans rouvrir la décision 13). Deux clés
 *   **dérivées** identiques (le préfixe commun d'une saga) n'y figurent pas :
 *   la règle de collision les refuse déjà toutes deux, il n'y a ni alias
 *   fautif à supprimer ni homonyme à accepter ;
 * - (ii) les couples de formes distinctes, à suite de chiffres identique
 *   ({@see AnswerKeyNormalizer::digits()}), à distance au plus
 *   `tolerance(kA) + tolerance(kB)`, entre clés **candidates à la
 *   tolérance** seulement — exactes, ou dérivées non ambiguës : une clé
 *   dérivée ambiguë n'est jamais candidate à l'étape (d) de
 *   {@see AnswerMatcher::decide()}, un couple qui la contient n'est ni direct
 *   ni pivot. Un couple à distance au plus `max(tolerance(kA),
 *   tolerance(kB))` est **direct** ; au-delà, jusqu'à la somme, c'est un
 *   **couple pivot**, qu'une chaîne forgée à mi-chemin couvre pour les deux
 *   films (§ 13.2). Le caractère direct ou pivot se lit de `distance` et des
 *   longueurs des deux formes, sans champ de plus.
 *
 * Une ligne par couple : `{ normalizedA, movieA: {id, titleOriginal}, kindA,
 * normalizedB, movieB, kindB, distance }`, `A` étant la clé de plus petite
 * `(forme, film)`. La sortie table reprend ces noms de champs comme en-têtes
 * (`movieA.id`, `movieA.titleOriginal` pour l'objet) et ne contient **aucune
 * phrase** : rien à traduire, les identifiants de film n'étant lus que par le
 * porteur. `--json` rend la même liste.
 *
 * **Coût** : seules sont comparées les clés dont les longueurs compactes
 * diffèrent d'au plus deux fois la plus grande tolérance du barème, la
 * distance étant au moins l'écart des longueurs compactes (chaque opération
 * d'édition change la longueur compacte d'au plus un) ; les clés sont
 * regroupées par longueur, triées, et parcourues par fenêtre glissante.
 * Quelques centaines de milliers de comparaisons au J1.
 *
 * **Procédure de calibrage** (70 § 13.1, avant la clôture du J1, rejouée à
 * l'ajout d'une langue) : chaque ligne (i) est corrigée par la curation ou
 * acceptée par écrit ; chaque ligne (ii) directe est corrigée ou signale un
 * barème trop large ; les lignes pivots mesurent le résidu de force brute et
 * ne se corrigent que par le barème, donc par une nouvelle
 * {@see AnswerRules::VERSION}.
 *
 * @phpstan-type CollisionKey array{movieId: int, titleOriginal: string, kind: string, exact: bool, tolerable: bool, normalized: string, length: int, digits: list<int>, tolerance: int}
 * @phpstan-type CollisionMovie array{id: int, titleOriginal: string}
 * @phpstan-type CollisionLine array{normalizedA: string, movieA: CollisionMovie, kindA: string, normalizedB: string, movieB: CollisionMovie, kindB: string, distance: int}
 */
class AnswersCollisionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'answers:collisions
        {--json : la même liste, en JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rapport de collisions des clés de réponse du catalogue publié, en lecture seule';

    /**
     * En-têtes de la sortie table : les noms de champs d'une ligne, l'objet
     * film aplati en deux colonnes.
     *
     * @var list<string>
     */
    private const array HEADERS = [
        'normalizedA',
        'movieA.id',
        'movieA.titleOriginal',
        'kindA',
        'normalizedB',
        'movieB.id',
        'movieB.titleOriginal',
        'kindB',
        'distance',
    ];

    public function handle(): int
    {
        $lines = $this->collisions();

        if ($this->option('json') === true) {
            // Brut : un titre qui contiendrait `<…>` ne doit pas être lu comme
            // une balise de mise en forme de la console.
            $this->output->writeln(
                json_encode($lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                OutputInterface::OUTPUT_RAW,
            );

            return self::SUCCESS;
        }

        $this->table(self::HEADERS, array_map(self::tableRow(...), $lines));

        return self::SUCCESS;
    }

    /**
     * Les couples, triés par distance, puis par formes, puis par films.
     *
     * @return list<CollisionLine>
     */
    private function collisions(): array
    {
        $keys = $this->publishedKeys();
        $window = self::lengthWindow();
        $count = count($keys);
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $a = $keys[$i];

            // Clés triées par longueur compacte croissante : au-delà de la
            // fenêtre, aucune clé suivante ne peut plus être assez proche.
            for ($j = $i + 1; $j < $count && $keys[$j]['length'] - $a['length'] <= $window; $j++) {
                $b = $keys[$j];

                if ($a['movieId'] === $b['movieId']) {
                    continue;
                }

                // (i) Même forme, l'une au moins de nature exacte, quelle que
                // soit la tolérance : c'est la forme que la garde (c) lit. Une
                // forme identique ne relève jamais de (ii).
                if ($a['normalized'] === $b['normalized']) {
                    if ($a['exact'] || $b['exact']) {
                        $lines[] = self::collisionLine($a, $b, 0);
                    }

                    continue;
                }

                // (ii) Deux clés candidates à la tolérance, sous la somme des
                // deux tolérances, à chiffres identiques.
                if (! $a['tolerable'] || ! $b['tolerable']) {
                    continue;
                }

                $threshold = $a['tolerance'] + $b['tolerance'];

                if ($threshold < $b['length'] - $a['length'] || $a['digits'] !== $b['digits']) {
                    continue;
                }

                $distance = AnswerKeyNormalizer::distance($a['normalized'], $b['normalized']);

                if ($distance <= $threshold) {
                    $lines[] = self::collisionLine($a, $b, $distance);
                }
            }
        }

        usort($lines, self::compareLines(...));

        return $lines;
    }

    /**
     * Toutes les clés des films publiés, mesurées une fois — nature exacte,
     * candidature à la tolérance, longueur compacte, suite de chiffres,
     * tolérance —, triées par longueur compacte.
     *
     * @return list<CollisionKey>
     */
    private function publishedKeys(): array
    {
        $rows = DB::table('answer_key')
            ->join('movie', 'movie.id', '=', 'answer_key.movie_id')
            ->where('movie.availability', ContentAvailability::Published->value)
            ->orderBy('answer_key.id')
            ->get([
                'answer_key.movie_id',
                'answer_key.key_kind',
                'answer_key.normalized',
                'answer_key.is_ambiguous',
                'movie.title_original',
            ]);

        $keys = [];

        foreach ($rows as $row) {
            $normalized = (string) $row->normalized;
            $length = strlen(AnswerKeyNormalizer::compact($normalized));
            $kind = AnswerKeyKind::from((string) $row->key_kind);

            $keys[] = [
                'movieId' => (int) $row->movie_id,
                'titleOriginal' => (string) $row->title_original,
                'kind' => $kind->value,
                'exact' => $kind->isExact(),
                // Le filtre de l'étape (d) du juge, à la lettre : le drapeau
                // dénormalisé, recompté sur le catalogue publié entier.
                'tolerable' => $kind->isExact() || ! (bool) $row->is_ambiguous,
                'normalized' => $normalized,
                'length' => $length,
                'digits' => AnswerKeyNormalizer::digits($normalized),
                'tolerance' => AnswerRules::tolerance($length),
            ];
        }

        // Tri stable : à longueur égale, l'ordre des identifiants de clé.
        usort($keys, static fn (array $a, array $b): int => $a['length'] <=> $b['length']);

        return $keys;
    }

    /**
     * L'écart de longueur compacte au-delà duquel deux clés ne peuvent jamais
     * former un couple : deux fois la plus grande tolérance du barème.
     */
    private static function lengthWindow(): int
    {
        return 2 * max(AnswerRules::MAX_TOLERANCE, ...array_values(AnswerRules::TOLERANCE_STEPS));
    }

    /**
     * Une ligne du rapport, orientée : `A` est la clé de plus petite
     * `(forme, film)`, pour qu'un même couple s'écrive toujours de la même
     * façon.
     *
     * @param  CollisionKey  $first
     * @param  CollisionKey  $second
     * @return CollisionLine
     */
    private static function collisionLine(array $first, array $second, int $distance): array
    {
        [$a, $b] = self::compareKeys($first, $second) <= 0 ? [$first, $second] : [$second, $first];

        return [
            'normalizedA' => $a['normalized'],
            'movieA' => ['id' => $a['movieId'], 'titleOriginal' => $a['titleOriginal']],
            'kindA' => $a['kind'],
            'normalizedB' => $b['normalized'],
            'movieB' => ['id' => $b['movieId'], 'titleOriginal' => $b['titleOriginal']],
            'kindB' => $b['kind'],
            'distance' => $distance,
        ];
    }

    /**
     * Ordre des deux clés d'un couple : la forme en ordre d'octets — jamais
     * `<=>`, qui comparerait `1917` et `300` comme des nombres —, puis le film.
     *
     * @param  CollisionKey  $a
     * @param  CollisionKey  $b
     */
    private static function compareKeys(array $a, array $b): int
    {
        return strcmp($a['normalized'], $b['normalized']) ?: $a['movieId'] <=> $b['movieId'];
    }

    /**
     * Ordre des lignes : distance, formes `A` puis `B`, films `A` puis `B`.
     *
     * @param  CollisionLine  $a
     * @param  CollisionLine  $b
     */
    private static function compareLines(array $a, array $b): int
    {
        return $a['distance'] <=> $b['distance']
            ?: strcmp($a['normalizedA'], $b['normalizedA'])
            ?: strcmp($a['normalizedB'], $b['normalizedB'])
            ?: $a['movieA']['id'] <=> $b['movieA']['id']
            ?: $a['movieB']['id'] <=> $b['movieB']['id'];
    }

    /**
     * Une ligne aplatie pour la table, chaque cellule échappée : un titre
     * n'est jamais lu comme une balise de mise en forme.
     *
     * @param  CollisionLine  $line
     * @return list<string>
     */
    private static function tableRow(array $line): array
    {
        return array_map(OutputFormatter::escape(...), [
            $line['normalizedA'],
            (string) $line['movieA']['id'],
            $line['movieA']['titleOriginal'],
            $line['kindA'],
            $line['normalizedB'],
            (string) $line['movieB']['id'],
            $line['movieB']['titleOriginal'],
            $line['kindB'],
            (string) $line['distance'],
        ]);
    }
}
