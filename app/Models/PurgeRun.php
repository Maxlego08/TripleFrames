<?php

namespace App\Models;

use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use Carbon\CarbonImmutable;
use Database\Factories\PurgeRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Le journal d'exécution de la purge — la sonde de la seule panne du projet
 * dont la conséquence est juridique : une purge silencieusement arrêtée rend
 * FAUSSE une durée annoncée publiquement (§ 11.3).
 *
 * Une ligne par couple (exécution, périmètre). `finished_at` NULL signifie « en
 * cours OU plantée » : c'est la même absence, et c'est voulu — une sonde qui
 * distinguerait les deux aurait besoin d'un battement que personne n'écrirait.
 * `ran_at` porte l'auto-purge à 13 mois, une fenêtre de plus que la plus longue
 * durée que la table atteste.
 *
 * **Aucune clé étrangère, aucune donnée personnelle**, donc aucune relation et
 * aucune colonne cachée : la table ne dit rien d'une personne, seulement d'un
 * balayage. `error` est un message d'exploitation destiné à l'administrateur
 * qui lit la sonde ; rien d'autre n'a de raison d'être sérialisé.
 *
 * `timestamps` conventionnels MALGRÉ les colonnes datées métier (`started_at`,
 * `finished_at`, `ran_at`) : le § 1.7 n'admet aucun troisième cas, et
 * `$timestamps = false` est réservé nommément à `frame_review` et `seen_frame`.
 *
 * `#[Fillable]` vide : la ligne est écrite par le job de purge seul, et `status`
 * est une colonne d'autorité. Les quatre colonnes NOT NULL sans défaut
 * (`scope`, `status`, `started_at`, `ran_at`) font échouer bruyamment (1364)
 * toute écriture par tableau de requête.
 *
 * Le job est résilient ligne à ligne — une transaction par ligne, compteur
 * d'échecs, reprise au suivant, jamais un lot entier annulé —, et l'ordre de
 * suppression lui est imposé par les `restrictOnDelete` : `guess` →
 * `round_choice_set` → `round_tier` → `round_player` → `round` → `game_player`
 * → `game` → `player` → `room`.
 *
 * `scope` est `string(32)` et non 20 : deux cas de {@see PurgeScope} repris du
 * tableau du § 11.1 dépassent 20 caractères (`framework_failed_jobs`, 21 ;
 * `framework_reset_tokens`, 22). MySQL strict aurait levé 1406 à l'insertion là
 * où SQLite tronque en silence. Arbitré le 22/09, spec § 11.3.
 *
 * @property int $id
 * @property PurgeScope $scope
 * @property PurgeRunStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int $rows_deleted
 * @property int $batches
 * @property int|null $duration_ms
 * @property string|null $error
 * @property CarbonImmutable $ran_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('purge_run')]
#[Fillable([])]
class PurgeRun extends Model
{
    /** @use HasFactory<PurgeRunFactory> */
    use HasFactory;

    /**
     * Largeur de `error` (`string(500)`) : tout écrivain du journal y tronque
     * son message — le moteur de purge de `100` et le balayage `stale_lobby`
     * de `50`, jamais deux constantes pour une colonne.
     */
    public const int ERROR_LENGTH = 500;

    /**
     * Largeur de `batches` (`unsignedSmallInteger`) : tout écrivain du journal
     * y plafonne son compte de lots.
     */
    public const int MAX_BATCHES = 65_535;

    /**
     * Miroir EXACT des défauts SQL de `purge_run` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'rows_deleted' => 0,
        'batches' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => PurgeScope::class,
            'status' => PurgeRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'ran_at' => 'datetime',
        ];
    }

    /**
     * Ce qui se consigne d'une exception, dans `error` comme au journal : sa
     * classe et son code (l'état SQL d'une exception de requête), jamais son
     * message, qui porte les valeurs liées de la requête — une adresse
     * électronique, l'identifiant d'une session. Règle commune à tous les
     * écrivains du journal.
     *
     * @return array{exception: class-string, code: string}
     */
    public static function describeFailure(Throwable $failure): array
    {
        return [
            'exception' => $failure::class,
            'code' => (string) $failure->getCode(),
        ];
    }

    /** {@see self::describeFailure()} en une ligne, pour la colonne `error`. */
    public static function summarizeFailure(Throwable $failure): string
    {
        $described = self::describeFailure($failure);

        return "{$described['exception']} (code {$described['code']})";
    }
}
