<?php

namespace App\Jobs\Room;

use App\Actions\Room\ArchiveRoom;
use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Models\PurgeRun;
use App\Models\Room;
use App\Support\Room\RoomExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le balayage des échéances de salon — spec 50 § 16.2 ; 10 § 11.1 ; 100 § 14.
 *
 * Déposé toutes les {@see RoomExpiry::SWEEP_EVERY_MINUTES} par la commande
 * `room:archive-idle`, planifiée dans `routes/console.php`. **File `default`,
 * jamais `game`** : un balayage par lots peut tenir la file, et rien de ce
 * qui dure ne partage la file du temps réel.
 *
 * Deux passes, `$now` pris une fois au début, à la seconde (les colonnes
 * pilotes sont à la seconde), chacune par lots bornés de
 * {@see RoomExpiry::BATCH_SIZE}, du plus ancien au plus récent, sur
 * `room_archived_activity_idx (archived_at, last_activity_at)`, par un curseur
 * `(last_activity_at, id)` — un salon que l'action refuse (redevenu actif,
 * partie non figée) n'est jamais resélectionné, et la passe progresse :
 *
 * 1. **`stale_lobby`** — archivage anticipé des lobbies jamais lancés,
 *    `ArchiveRoom($room, $now − LOBBY_IDLE_MINUTES, lobbyOnly: true)`. Ce
 *    balayage est le **seul exécutant** du périmètre : `RetentionPurger` ne
 *    l'exécute jamais. Il écrit **une ligne `purge_run` par passage**, même à
 *    zéro salon archivé — l'absence de ligne est une panne, que la sonde
 *    `purge` de `100` doit distinguer d'un balayage sans travail. Même
 *    sémantique que le moteur de purge (10 § 11.3) : écrite `running` avant
 *    le premier lot, close `completed` (même avec des salons en échec, dont
 *    le compte et la classe de la dernière exception vont dans `error`) ou
 *    `failed` (exception hors d'un salon, `finished_at` et `duration_ms`
 *    NULL) ; `rows_deleted` porte le nombre de salons archivés, compteur
 *    générique du journal ;
 * 2. **archivage à 24 h** — `ArchiveRoom($room, $now − ROOM_IDLE_MINUTES)` :
 *    une action, pas une purge (10 § 11.1), qui n'écrit aucune ligne
 *    `purge_run`.
 *
 * **Résilient salon par salon** : l'action ouvre une transaction par salon ;
 * un salon en échec est compté et journalisé, et la passe continue. Aucune
 * donnée personnelle au journal : la classe et le code de l'exception,
 * jamais son message, qui porterait les valeurs liées de la requête.
 *
 * La ligne `purge_run` suit les règles communes du journal, lues sur
 * {@see PurgeRun} (largeurs de `error` et `batches`, exception réduite à sa
 * classe et son code) ; son cycle `running` → `completed` / `failed` est celui
 * du moteur de purge de `100` : tout changement de 10 § 11.3 touche les deux
 * écrivains.
 *
 * `ShouldBeUnique` : tant qu'un balayage attend en file ou s'exécute sous son
 * verrou, le dépôt suivant est abandonné — deux balayages ne se chevauchent
 * pas, sauf un balayage plus long que `$uniqueFor`, sans risque (l'action
 * relit tout sous le verrou du salon). Le verrou est libéré à la fin du
 * balayage ; sinon, il expire à `$uniqueFor`, posé au dépôt et fixé
 * **strictement sous la cadence**, avec une marge d'une minute (la résolution
 * du planificateur) : un verrou orphelin — worker tué, file arrêtée — est
 * toujours expiré au dépôt suivant, qui n'est jamais abandonné pour lui, et
 * l'archivage reste au plus une cadence après l'échéance. Une file `default`
 * arrêtée accumule donc au plus un balayage par cadence ; rejoués à la
 * reprise, ils sont idempotents (une ligne `purge_run` `stale_lobby` chacun).
 * Un seul essai : le passage suivant rejoue tout ce qui reste échu.
 */
final class ArchiveIdleRooms implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** La file du balayage, et la seule : jamais `game`. */
    public const string QUEUE = 'default';

    public int $tries = 1;

    /**
     * Durée du verrou d'unicité, en secondes : une cadence moins une minute,
     * strictement sous la cadence pour qu'un verrou orphelin soit toujours
     * expiré au dépôt suivant, et jamais nulle (un verrou à 0 s n'expirerait
     * pas).
     */
    public int $uniqueFor = (RoomExpiry::SWEEP_EVERY_MINUTES - 1) * 60;

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ArchiveRoom $archive): void
    {
        $now = Date::now()->toImmutable()->startOfSecond();

        $this->sweepStaleLobbies($archive, $now);

        $tally = self::emptyTally();
        $this->pass($archive, RoomExpiry::roomIdleBefore($now), false, $tally);

        if ($tally['lastFailure'] !== null) {
            Log::warning('Archivage des salons inactifs : salons en échec, repris au passage suivant.', [
                'failures' => $tally['failures'],
                ...PurgeRun::describeFailure($tally['lastFailure']),
            ]);
        }
    }

    /**
     * Passe 1, `stale_lobby`, et sa ligne `purge_run`, écrite à chaque
     * passage.
     */
    private function sweepStaleLobbies(ArchiveRoom $archive, CarbonImmutable $now): void
    {
        $clock = hrtime(true);

        $run = new PurgeRun;
        $run->forceFill([
            'scope' => PurgeScope::StaleLobby,
            'status' => PurgeRunStatus::Running,
            'started_at' => $now,
            'ran_at' => $now,
        ])->save();

        $tally = self::emptyTally();

        try {
            $this->pass($archive, RoomExpiry::lobbyIdleBefore($now), true, $tally);
        } catch (Throwable $failure) {
            Log::error('Archivage anticipé des lobbies : passage interrompu.', PurgeRun::describeFailure($failure));

            $run->forceFill([
                'status' => PurgeRunStatus::Failed,
                'rows_deleted' => $tally['archived'],
                'batches' => min(PurgeRun::MAX_BATCHES, $tally['batches']),
                'error' => mb_substr('Passage interrompu : '.PurgeRun::summarizeFailure($failure), 0, PurgeRun::ERROR_LENGTH),
            ])->save();

            return;
        }

        $lastFailure = $tally['lastFailure'];

        if ($lastFailure !== null) {
            Log::warning('Archivage anticipé des lobbies : salons en échec, repris au passage suivant.', [
                'failures' => $tally['failures'],
                ...PurgeRun::describeFailure($lastFailure),
            ]);
        }

        $run->forceFill([
            'status' => PurgeRunStatus::Completed,
            'finished_at' => Date::now()->toImmutable(),
            'duration_ms' => intdiv(hrtime(true) - $clock, 1_000_000),
            'rows_deleted' => $tally['archived'],
            'batches' => min(PurgeRun::MAX_BATCHES, $tally['batches']),
            'error' => $lastFailure === null
                ? null
                : mb_substr("{$tally['failures']} salon(s) en échec ; dernier : ".PurgeRun::summarizeFailure($lastFailure), 0, PurgeRun::ERROR_LENGTH),
        ])->save();
    }

    /**
     * Une passe : les salons non archivés dont la dernière activité précède
     * strictement `$idleBefore` (jamais lancés seulement, pour `$lobbyOnly`),
     * par lots bornés, chacun confié à l'action, qui relit le critère sous
     * verrou. Le décompte est tenu dans `$tally` au fil de la passe, pour
     * survivre à une exception hors d'un salon.
     *
     * @param  array{archived: int, batches: int, failures: int, lastFailure: Throwable|null}  $tally
     *
     * @param-out array{archived: int, batches: int, failures: int, lastFailure: Throwable|null}  $tally
     */
    private function pass(ArchiveRoom $archive, CarbonImmutable $idleBefore, bool $lobbyOnly, array &$tally): void
    {
        $afterAt = null;
        $afterId = 0;

        do {
            $query = Room::query()
                ->select(['id', 'last_activity_at'])
                ->whereNull('archived_at')
                ->where('last_activity_at', '<', $idleBefore);

            if ($lobbyOnly) {
                $query->whereNull('launched_at');
            }

            if ($afterAt !== null) {
                $query->where(static fn (Builder $query) => $query
                    ->where('last_activity_at', '>', $afterAt)
                    ->orWhere(static fn (Builder $query) => $query
                        ->where('last_activity_at', $afterAt)
                        ->where('id', '>', $afterId)));
            }

            $rooms = $query
                ->orderBy('last_activity_at')
                ->orderBy('id')
                ->limit(RoomExpiry::BATCH_SIZE)
                ->get();

            if ($rooms->isEmpty()) {
                return;
            }

            $tally['batches']++;

            foreach ($rooms as $room) {
                $afterAt = $room->last_activity_at;
                $afterId = $room->id;

                try {
                    if ($archive->handle($room, $idleBefore, $lobbyOnly)) {
                        $tally['archived']++;
                    }
                } catch (Throwable $failure) {
                    $tally['failures']++;
                    $tally['lastFailure'] = $failure;
                }
            }
        } while ($rooms->count() === RoomExpiry::BATCH_SIZE);
    }

    /**
     * @return array{archived: int, batches: int, failures: int, lastFailure: Throwable|null}
     */
    private static function emptyTally(): array
    {
        return ['archived' => 0, 'batches' => 0, 'failures' => 0, 'lastFailure' => null];
    }
}
