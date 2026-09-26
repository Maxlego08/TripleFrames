<?php

namespace App\Jobs\Ops;

use App\Support\Answers\BruteForceProbe;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Le rapport hebdomadaire de force brute — spec 100 § 10.7 et § 15, lot L100-7
 * (second temps, D37 du 23/09), sonde de 70 § 13.2 (L70-11).
 *
 * Défaut retenu Q70-5 : « accepter et sonder au J1, recalibrer avant l'onglet
 * Avancé ». Planifié chaque lundi à `config('ops.brute_force.report_at')`
 * (UTC, `routes/console.php`), il exécute {@see BruteForceProbe} sur les
 * `config('ops.brute_force.window_days')` derniers jours avec le seuil
 * `K` = `config('ops.brute_force.k')`, et journalise **le compte et la
 * fenêtre** sur le canal `game`, en information : un rapport **non
 * alertant**, relu au recalibrage d'avant l'onglet Avancé (L70-13), jamais une
 * sonde de supervision. `K` n'est jamais noté `N`, qui désigne
 * `frames_per_round`.
 *
 * - **Aucune donnée de joueur** : la sonde rend un entier ; la ligne ne porte
 *   que `k`, `window_days`, `since`, `until` et `count`. Ni siège, ni pseudo,
 *   ni salon, ni manche, ni film. `count` compte des **bonnes réponses**, une
 *   par siège gagnant, pas des manches distinctes (E74-3) : une manche gagnée
 *   par deux sièges au-delà de `K` compte deux.
 * - `K` = 10 est la **valeur de départ** de 100 § 15, gardée à la lettre ;
 *   mais une victoire au palier 1 laisse au plus `d₁ + tier_grace_ms` pour
 *   soumettre, soit onze envois à 1/s au preset par défaut (`d₁` = 10 s) et
 *   au plus dix tentatives fausses : sous `> K`, le compte est **nul par
 *   construction** dès que `d₁` ≤ ~10,7 s. Question au porteur (E75-3).
 * - Les manches annulées sont exclues par la sonde elle-même (invariant L1,
 *   jointure `round`) : le job ne filtre rien, il ne fait que paramétrer.
 * - La fenêtre se termine à l'instant d'exécution (`until`) : la requête de 70
 *   n'a pas de borne haute. Les fenêtres sont donc contiguës en
 *   `started_at`, pas en ce qu'elles comptent : une manche démarrée moins de
 *   `d₁ + tier_grace_ms` avant `until` et gagnée après la lecture n'est
 *   comptée par aucun rapport, et une manche démarrée entre la capture de
 *   `until` et la requête l'est au-delà du `until` journalisé. Résidu assumé
 *   (E75-1) ; le remède exact, une borne haute dans la sonde, relève de 70.
 * - **File `default`, jamais `game`** (10 § 12) : rien de ce qui lit l'historique
 *   ne partage la file du temps réel. Lecture seule.
 *
 * Trois essais, espacés d'une minute : contrairement au battement ou à la
 * purge, le rapport suivant ne rejoue pas la semaine perdue — un échec
 * passager ne doit pas la faire disparaître. Un réessai lit sa fenêtre à son
 * propre instant d'exécution, qu'il journalise.
 */
final class ReportBruteForce implements ShouldQueue
{
    use Queueable;

    /** La file du job, et la seule : jamais `game`. */
    public const string QUEUE = 'default';

    /** Le canal du rapport (spec 100 § 10.9 : lignes JSON, 14 jours). */
    public const string LOG_CHANNEL = 'game';

    /** Libellé de la ligne de journal, stable pour la relecture. */
    public const string LOG_MESSAGE = 'ops.brute_force.report';

    /** Format des bornes de la fenêtre dans le journal : UTC, à la milliseconde. */
    private const string WINDOW_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @throws InvalidArgumentException si la fenêtre configurée ne couvre pas au
     *                                  moins un jour, ou si `K` est négatif
     */
    public function handle(BruteForceProbe $probe): void
    {
        $k = Config::integer('ops.brute_force.k');
        $windowDays = Config::integer('ops.brute_force.window_days');

        if ($windowDays < 1) {
            throw new InvalidArgumentException('La fenêtre du rapport de force brute doit couvrir au moins un jour.');
        }

        $until = CarbonImmutable::now();
        $since = $until->subDays($windowDays);

        $count = $probe->count($k, $since);

        Log::channel(self::LOG_CHANNEL)->info(self::LOG_MESSAGE, [
            'k' => $k,
            'window_days' => $windowDays,
            'since' => $since->utc()->format(self::WINDOW_FORMAT),
            'until' => $until->utc()->format(self::WINDOW_FORMAT),
            'count' => $count,
        ]);
    }
}
