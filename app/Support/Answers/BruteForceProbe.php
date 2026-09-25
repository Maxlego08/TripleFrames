<?php

namespace App\Support\Answers;

use App\Enums\GuessSource;
use App\Enums\RoundStatus;
use App\Models\Guess;
use App\Models\Round;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;

/**
 * La sonde de force brute — spec 70 § 13.2, lot L70-11, défaut retenu Q70-5
 * (« accepter et sonder au J1, recalibrer avant l'onglet Avancé »).
 *
 * Elle compte les **manches gagnées au palier 1, en texte libre, après plus de
 * `K` tentatives fausses** : la trace d'un client scripté qui, connaissant le
 * catalogue, soumet des titres sans regarder l'image au palier le mieux payé.
 * Une ligne par bonne réponse, donc par siège gagnant (`guess_round_player_uq`).
 *
 * ```sql
 * SELECT COUNT(*) FROM guess g
 * JOIN round r ON r.id = g.round_id AND r.status <> 'cancelled'
 * JOIN round_player rp ON rp.round_id = g.round_id AND rp.player_id = g.player_id
 * WHERE g.tier_index = 1 AND g.source = 'text' AND rp.wrong_attempts > :k AND r.started_at >= :since
 * ```
 *
 * - `K` et le début de la fenêtre sont des **paramètres**, jamais des valeurs
 *   de ce fichier : le job `App\Jobs\Ops\ReportBruteForce` de 100 (L100-7)
 *   les lit dans sa configuration des sondes et journalise le compte en
 *   rapport hebdomadaire **non alertant**. `K` n'est jamais noté `N`, qui
 *   désigne `frames_per_round`.
 * - La jointure `round` et l'exclusion de `cancelled` appliquent
 *   l'invariant **L1** : une manche annulée n'a rien gagné.
 * - Aucune colonne ni aucun index nouveau : `guess_round_player_uq` et
 *   `round_player_round_player_uq` servent les jointures (10 § 15).
 * - **Aucune donnée de joueur** ne sort : un entier, rien d'autre.
 *
 * La lecture du compte et le recalibrage d'avant l'onglet Avancé (L70-13)
 * portent sur la **taille du vivier du salon**, jamais sur celle du
 * catalogue.
 */
final class BruteForceProbe
{
    /**
     * Le palier le plus cryptique, donc le mieux payé : `tier_index` va de 1
     * à `N` par définition du schéma (10 § 7.4). Un index, pas une valeur de
     * jeu.
     */
    private const int FIRST_TIER_INDEX = 1;

    /**
     * Le nombre de bonnes réponses au palier 1, en texte libre, dont le siège
     * a fait **strictement plus** de `$k` tentatives fausses dans la manche,
     * sur les manches non annulées démarrées à partir de `$since`.
     *
     * @throws InvalidArgumentException si `$k` est négatif
     */
    public function count(int $k, CarbonImmutable $since): int
    {
        if ($k < 0) {
            throw new InvalidArgumentException('Le seuil K de la sonde de force brute ne peut pas être négatif.');
        }

        return Guess::query()
            ->join('round', static function (JoinClause $join): void {
                $join->on('round.id', '=', 'guess.round_id')
                    ->where('round.status', '<>', RoundStatus::Cancelled->value);
            })
            ->join('round_player', static function (JoinClause $join): void {
                $join->on('round_player.round_id', '=', 'guess.round_id')
                    ->on('round_player.player_id', '=', 'guess.player_id');
            })
            ->where('guess.tier_index', self::FIRST_TIER_INDEX)
            ->where('guess.source', GuessSource::Text->value)
            ->where('round_player.wrong_attempts', '>', $k)
            // Au format EXACT de la colonne `timestamp(3)`, jamais à celui de la
            // grammaire, qui tronque à la seconde.
            ->where('round.started_at', '>=', (new Round)->fromDateTime($since))
            ->count();
    }
}
