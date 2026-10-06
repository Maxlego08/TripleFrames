<?php

namespace App\Jobs\Curation;

use App\Console\Commands\CurationFrameBatchCommand;
use App\Support\Catalog\ImportSnapshotGuard;
use App\Support\Curation\FrameBatchImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Importe un lot d'images ouvert par le back-office — spec 20 § 5.10, D57 du
 * 05/10.
 *
 * Le moteur est la commande `curation:frame-batch --batch`, seule à parler à
 * TMDB (`TmdbBoundaryTest`) ; le job la lance sur la file par DÉFAUT —
 * jamais une file de jeu, aucun `onQueue()` — sur le **chemin ordinaire** du
 * curateur ({@see ImportSnapshotGuard::ordinaryPath()}) : comme le balayage,
 * il ajoute des images et ne détruit rien, et un instantané complet à chaque
 * lot placerait un geste d'exploitation sur le chemin du curateur.
 *
 * **Par passages** (amendé le 06/10) : chaque passage travaille au plus
 * {@see self::BUDGET_SECONDS} secondes, puis se relance sur les films
 * restants — un seul passage tué à 900 s laissait un lot de 200 films à
 * moitié importé.
 */
class ImportFrameBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /**
     * Secondes de travail d'un passage, bien sous `$timeout` : le film en
     * cours au moment du budget (jusqu'à 40 images) doit finir avant que le
     * worker ne tue le job (amendé le 06/10).
     */
    public const int BUDGET_SECONDS = 600;

    public function __construct(
        public readonly int $userId,
        public readonly string $token,
    ) {}

    public function handle(): void
    {
        $status = ImportSnapshotGuard::ordinaryPath(fn (): int => Artisan::call('curation:frame-batch', [
            '--batch' => $this->token,
            '--actor' => (string) $this->userId,
            '--budget' => (string) self::BUDGET_SECONDS,
        ]));

        // Budget atteint : les films traités sont enregistrés, un nouveau
        // passage reprend au premier film non traité.
        if ($status === CurationFrameBatchCommand::PARTIAL) {
            self::dispatch($this->userId, $this->token);

            return;
        }

        // La commande marque elle-même le lot dans les cas qu'elle connaît ;
        // ce filet couvre un refus d'entrée qu'elle n'aurait pas nommé. Un
        // lot déjà terminé n'est jamais réécrit.
        if ($status !== 0) {
            FrameBatchImport::fail($this->userId, $this->token);
        }
    }

    public function failed(?Throwable $exception): void
    {
        FrameBatchImport::fail($this->userId, $this->token);
    }
}
