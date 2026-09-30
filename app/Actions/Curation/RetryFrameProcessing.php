<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingState;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Frame;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * « Relancer » le traitement d'une image — contrat C9, spec 20 § 5.6.
 *
 * **Offert au seul échec rejouable** (`FrameProcessingFailure::isRetryable()`,
 * `resource_limit` et `unexpected`) : un échec définitif redonnerait le même
 * résultat, et l'écran propose alors de re-recadrer ou d'écarter. Les octets
 * d'un échec rejouable sont restés sous `master_path` (§ 5.6) : la relance les
 * réutilise, sans rien retélécharger.
 *
 * **Refus d'état, traduits, jamais des 403** ({@see self::refusal()}) : une
 * image suspendue ou retirée oppose `admin.frame.recrop.locked` (§ 5.6), une
 * image en traitement `admin.frame.recrop.busy`, tout autre état
 * `admin.frame.retry.not_retryable`. Relus sous le verrou de la frame.
 *
 * Aucune disponibilité ne change — une image en échec n'est jamais en jeu, le
 * job ne réécrivant jamais une frame publiée —, donc aucun recalcul de
 * projection. La relance écrit sa ligne `frame.processing_retried` (D41 du
 * 30/09), dont `details` garde l'échec qu'elle efface. Le job part sur la file
 * `default` après le commit.
 */
final class RetryFrameProcessing
{
    public function __construct(
        private readonly AdminJournal $journal,
    ) {}

    /**
     * La clé du refus d'une relance, ou `null` si elle est permise.
     */
    public static function refusal(Frame $frame): ?string
    {
        if (in_array($frame->availability, [ContentAvailability::Suspended, ContentAvailability::Withdrawn], true)) {
            return 'admin.frame.recrop.locked';
        }

        if ($frame->processing_state === FrameProcessingState::Pending) {
            return 'admin.frame.recrop.busy';
        }

        if ($frame->processing_state !== FrameProcessingState::Failed
            || $frame->processing_error?->isRetryable() !== true) {
            return 'admin.frame.retry.not_retryable';
        }

        return null;
    }

    /**
     * Remet l'image en file de traitement.
     *
     * @throws ValidationException un refus d'état relu sous le verrou
     * @throws Throwable
     */
    public function handle(Frame $frame, User $curator): void
    {
        DB::transaction(function () use ($frame, $curator): void {
            $locked = Frame::query()->whereKey($frame->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('update', $locked);

            $refusal = self::refusal($locked);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['frame' => __($refusal)]);
            }

            $failure = $locked->processing_error;

            $locked->forceFill([
                'processing_state' => FrameProcessingState::Pending,
                'processing_error' => null,
            ])->save();

            $this->journal->record(
                $curator,
                AdminActionType::FrameProcessingRetried,
                $locked->id,
                details: AdminActionDetails::processingRetried($failure),
            );

            // `afterCommit` est posé par le job lui-même.
            ProcessFrameImage::dispatch($locked->id);
        });

        $frame->refresh();
    }
}
