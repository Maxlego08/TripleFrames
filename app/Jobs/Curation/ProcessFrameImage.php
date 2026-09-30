<?php

namespace App\Jobs\Curation;

use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameProcessingState;
use App\Models\Frame;
use App\Support\Frames\FrameImageProcessor;
use App\Support\Frames\FrameProcessingException;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Le traitement Imagick d'UNE frame — contrat C9, spec 20 § 5.5 et § 5.6.
 *
 * **File `default`, jamais `game`** : rien de ce qui peut durer une minute ne
 * partage la file du temps réel, et le worker `default` porte les plafonds
 * (`MemoryMax`, `--timeout`) contre lesquels `catalog.curation.imagick.*` est
 * calibré. **Une image par job**, unique par frame tant qu'il attend ou tourne.
 * Distribué **après le commit** de l'écriture qui l'appelle : le job ne lit
 * jamais une ligne qu'une transaction annulerait.
 *
 * **Échecs.** Un échec connu ({@see FrameProcessingException}) est définitif
 * pour ce job : `processing_state = failed` et la clé de l'enum, jamais un
 * message brut — le détail technique part au journal applicatif. Toute autre
 * panne est transitoire : la file la rejoue ({@see self::$tries},
 * {@see self::$backoff}), puis {@see self::failed()} la consigne en
 * `unexpected`. « Relancer » n'est offert qu'à un échec rejouable
 * (`FrameProcessingFailure::isRetryable()`).
 *
 * **Deux exceptions, et elles sont voulues.** Une frame `published` n'est
 * JAMAIS réécrite, pas même pour y consigner l'échec : poser `failed` sur une
 * frame en jeu la ferait sortir du prédicat de variante jouable (n° 16). Le
 * refus est seulement journalisé. Une frame `withdrawn` reçoit l'état d'échec,
 * mais aucun fichier n'est ni écrit ni supprimé : ses fichiers relèvent du
 * retrait juridique (spec 20 § 11.3).
 *
 * **Octets provisoires.** Un échec non rejouable au PREMIER traitement —
 * `game_path` encore NULL, `master_path` portant encore l'original non
 * dépouillé — supprime ce fichier après le commit de l'état d'échec : « aucun
 * original n'est conservé » ne dépend pas du succès du job. `master_path`
 * reste posé (colonne UNIQUE, jamais réaffectée). Un échec rejouable garde les
 * octets, que « Relancer » réutilise.
 */
final class ProcessFrameImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** La file du job, et la seule : jamais `game`. */
    public const string QUEUE = 'default';

    /** Deux réessais pour une panne transitoire, puis `unexpected`. */
    public int $tries = 3;

    /**
     * Attente avant chaque réessai, en secondes.
     *
     * @var list<int>
     */
    public array $backoff = [30, 120];

    /**
     * Durée de vie du verrou d'unicité, en secondes : au-delà des essais et de
     * leurs attentes. Sans elle, un worker tué par son plafond de mémoire — qui
     * n'appelle jamais `failed()` — laisserait la frame intraitable.
     */
    public int $uniqueFor = 3600;

    public function __construct(public int $frameId)
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    /**
     * L'identifiant de la frame : Laravel préfixe déjà la clé du verrou par la
     * classe du job, et une frame n'a jamais deux traitements en vol.
     */
    public function uniqueId(): string
    {
        return (string) $this->frameId;
    }

    public function handle(FrameImageProcessor $processor): void
    {
        $frame = Frame::query()->find($this->frameId);

        if ($frame === null) {
            Log::warning('Traitement d’image : frame introuvable.', ['frame_id' => $this->frameId]);

            return;
        }

        try {
            $processor->process($frame);
        } catch (FrameProcessingException $exception) {
            $this->recordFailure($exception->failure, $exception);
        }
    }

    /**
     * Les essais d'une panne transitoire sont épuisés.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordFailure(FrameProcessingFailure::Unexpected, $exception);
    }

    private function recordFailure(FrameProcessingFailure $failure, ?Throwable $cause): void
    {
        Log::warning('Traitement d’image en échec.', [
            'frame_id' => $this->frameId,
            'failure' => $failure->value,
            'exception' => $cause !== null ? $cause::class : null,
            'detail' => $cause?->getMessage(),
        ]);

        // Un traitement ne réécrit jamais une frame en jeu, pas même son état.
        if ($failure === FrameProcessingFailure::Published) {
            return;
        }

        $provisional = null;

        DB::transaction(function () use ($failure, &$provisional): void {
            $frame = Frame::query()->lockForUpdate()->find($this->frameId);

            if ($frame === null || $frame->availability === ContentAvailability::Published) {
                return;
            }

            $frame->forceFill([
                'processing_state' => FrameProcessingState::Failed,
                'processing_error' => $failure,
            ])->save();

            if (! $failure->isRetryable()
                && $frame->availability !== ContentAvailability::Withdrawn
                && $frame->game_path === null
                && $frame->master_path !== null) {
                $provisional = $frame->master_path;
            }
        });

        if (is_string($provisional)) {
            $this->deleteProvisional($provisional);
        }
    }

    /**
     * Les octets provisoires d'un premier traitement définitivement échoué,
     * supprimés APRÈS le commit de l'état d'échec.
     */
    private function deleteProvisional(string $path): void
    {
        try {
            Storage::disk(FrameStoragePrefix::DISK)->delete($path);
        } catch (Throwable $exception) {
            Log::warning('Traitement d’image : octets provisoires non supprimés.', [
                'frame_id' => $this->frameId,
                'exception' => $exception::class,
            ]);
        }
    }
}
