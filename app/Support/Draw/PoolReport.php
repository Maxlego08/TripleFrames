<?php

namespace App\Support\Draw;

use App\Enums\PoolFault;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * Le rapport de vivier, en données (spec 30 § 4.2, contrat C2).
 *
 * Produit par {@see PoolReporter::report()} seul. {@see self::toArray()} est
 * **la forme unique** au lobby, au refus de lancement et en solo (R-11) : clés
 * camelCase, entiers, booléens et codes. Aucune chaîne à afficher (règle 4 :
 * la spec 50 rend `room.pool.cause.*` et `room.pool.remedy.*` dans la langue de
 * chaque joueur), aucune donnée de joueur, aucun titre, **aucun identifiant
 * interne** — `themesPruned` est un booléen, jamais une liste de thèmes — et
 * rien du tirage : il ne révèle aucune bonne réponse (règle 3).
 *
 * Sémantique : un vivier non bloqué n'a ni cause, ni remède, ni `N` jouable le
 * plus proche ; un vivier bloqué porte ses causes et ses remèdes dans l'ordre
 * fixe de {@see PoolFault} et de `PoolRemedyKind`, chaque remède débloquant à
 * lui seul. Bloqué sans cause : aucun réglage ne suffit, le catalogue est
 * insuffisant (texte `room.pool.no_remedy`, spec 50).
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PoolReport implements Arrayable
{
    /**
     * @param  int  $count  Vivier du périmètre, en œuvres (= `game.draw_pool_size` au lancement).
     * @param  int  $framesPerRound  `N` du périmètre.
     * @param  int  $roundsCount  `M` demandé.
     * @param  list<PoolFault>  $causes  Réglages fautifs, dans l'ordre fixe.
     * @param  list<PoolRemedy>  $remedies  Remèdes, dans l'ordre fixe.
     * @param  int|null  $nearestPlayableFramesPerRound  Plus grand `N' < N` atteignant `M`, s'il est bloqué.
     * @param  bool  $themesPruned  Un thème demandé n'est plus publié (élagué à la lecture).
     *
     * @throws InvalidArgumentException Un vivier non bloqué porte une cause, un remède ou un `N` proposé.
     */
    public function __construct(
        public int $count,
        public int $framesPerRound,
        public int $roundsCount,
        public array $causes,
        public array $remedies,
        public ?int $nearestPlayableFramesPerRound,
        public bool $themesPruned,
    ) {
        if (! $this->blocked()
            && ($causes !== [] || $remedies !== [] || $nearestPlayableFramesPerRound !== null)) {
            throw new InvalidArgumentException(
                'PoolReport : un vivier non bloqué n’a ni cause, ni remède, ni N jouable proposé.',
            );
        }
    }

    /**
     * Vrai si le vivier ne tient pas `M` œuvres : la borne croisée 3 refuse le
     * lancement.
     */
    public function blocked(): bool
    {
        return $this->count < $this->roundsCount;
    }

    /**
     * @return array{count: int, framesPerRound: int, roundsCount: int, blocked: bool, causes: list<string>, remedies: list<array{kind: string, value: int|null, count: int}>, nearestPlayableFramesPerRound: int|null, themesPruned: bool}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'framesPerRound' => $this->framesPerRound,
            'roundsCount' => $this->roundsCount,
            'blocked' => $this->blocked(),
            'causes' => array_map(static fn (PoolFault $cause): string => $cause->value, $this->causes),
            'remedies' => array_map(static fn (PoolRemedy $remedy): array => $remedy->toArray(), $this->remedies),
            'nearestPlayableFramesPerRound' => $this->nearestPlayableFramesPerRound,
            'themesPruned' => $this->themesPruned,
        ];
    }
}
