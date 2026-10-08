<?php

namespace App\Actions\ContentReport;

use App\Enums\ContentReportReason;
use App\Models\ContentReport;
use App\Support\ContentReport\ContentReporter;
use App\Support\ContentReport\ContentReportTarget;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Enregistrer un signalement de contenu par un joueur (D63 du 07/10, spec 10
 * § 8.1 bis) : une ligne `open`, rien d'autre. **Aucun effet automatique** —
 * ni dépublication, ni seuil, ni masquage : seul un geste curateur+ change
 * l'état d'un film ou d'une image, depuis la file du back-office.
 *
 * Dédoublonné par signaleur et par cible (`target_key`) : relu avant
 * l'insertion, et la violation d'unicité d'une course perdue est rendue comme
 * un doublon, jamais comme une erreur 500.
 */
final class SubmitContentReport
{
    /**
     * Vrai si la ligne est créée, faux si ce signaleur avait déjà signalé la
     * cible.
     *
     * @throws Throwable
     */
    public function handle(
        ContentReportTarget $target,
        ContentReporter $reporter,
        ContentReportReason $reason,
        ?string $comment,
    ): bool {
        $targetKey = ContentReport::targetKeyFor($target->movie, $target->frame);

        if ($reporter->hasReported($targetKey)) {
            return false;
        }

        try {
            DB::transaction(static function () use ($target, $reporter, $reason, $comment, $targetKey): void {
                $report = new ContentReport([
                    'reason' => $reason,
                    'comment' => $comment,
                ]);

                $report->forceFill([
                    'movie_id' => $target->movie->id,
                    'frame_id' => $target->frame?->id,
                    'target_key' => $targetKey,
                    'reporter_user_id' => $reporter->user?->id,
                    'reporter_player_id' => $reporter->user === null ? $reporter->seat?->id : null,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
