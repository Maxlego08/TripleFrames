<?php

namespace App\Support\Draw;

/**
 * Le tirage d'une partie, figé au lancement (spec 30 § 6.3, contrat C3).
 *
 * `rounds` compte `K = min(M + marge, W)` manches au plus (moins si des œuvres à
 * projection périmée ont été sautées, jamais moins de `M`), réserve comprise.
 * `poolSize` est `W`, le vivier **en œuvres** au moment du tirage : c'est
 * l'unité de `PoolReport::$count` et de `game.draw_pool_size` (E10-37), et
 * `DrawResult::$poolSize === PoolReporter::report($scope, M)->count` sur le même
 * `PoolScope` dans la même transaction (§ 6.5).
 *
 * La matérialisation (`MaterializeDraw`, spec 60) l'écrit en `round` et
 * `round_tier` ; **aucun rejeu de production ne recalcule le tirage** : il relit
 * ces lignes (spec 10 § 7.4).
 *
 * Jamais sérialisé vers un client : films, variantes, niveaux et taille du
 * vivier ne quittent pas le serveur (§ 6.5, règle 3).
 */
final readonly class DrawResult
{
    /**
     * @param  list<DrawnRound>  $rounds  Par `sequenceIndex` croissant, `1..K`.
     * @param  int  $poolSize  `W`, œuvres du vivier.
     * @param  int  $roundsCount  `M` demandé.
     */
    public function __construct(
        public array $rounds,
        public int $poolSize,
        public int $roundsCount,
    ) {}
}
