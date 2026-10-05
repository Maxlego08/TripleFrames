<?php

namespace App\Jobs\Curation;

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
 */
class ImportFrameBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $userId,
        public readonly string $token,
    ) {}

    public function handle(): void
    {
        $status = ImportSnapshotGuard::ordinaryPath(fn (): int => Artisan::call('curation:frame-batch', [
            '--batch' => $this->token,
            '--actor' => (string) $this->userId,
        ]));

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
