<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\MovieProjector;
use App\Support\Curation\CoverageLossPreview;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Dépublier une image publiée, ou **écarter** une image jamais publiée —
 * spec 20 § 8.4, régime de curation du § 11.1.
 *
 * Un seul geste, deux lectures : depuis `published`, l'image sort du jeu et
 * n'y revient que par une revue (elle réapparaît dans « À revoir ») ; depuis
 * `draft`, elle est écartée — `unpublished` avec `first_published_at` NULL
 * (EN20-1) —, ne réapparaît jamais en revue, et son visuel se réutilise par
 * une nouvelle variante, que le dédoublonnage d'{@see AddFrame} permet.
 *
 * **Jamais une suppression** : la ligne reste (`restrictOnDelete`), ses
 * fichiers restent sur le disque tant qu'elle n'est pas retirée, et ses
 * revues gardent leur valeur de preuve.
 *
 * Une transaction : passage `unpublished`, `availability_changed_at`, ligne
 * `frame.unpublished` (motif facultatif, C14), projection du film recalculée.
 * Un film publié qui perd ainsi sa couverture 1-3-5 **reste publié**, jouable
 * jusqu'au plus grand `N` que sa banque couvre encore, et s'affiche
 * « incomplet » (E10-23) : l'écran l'annonce avant confirmation
 * ({@see CoverageLossPreview}), le geste reste permis.
 *
 * La garde d'état est `FramePolicy::unpublish` (`draft` ou `published`),
 * portée par la route et rejouée ici sous le verrou.
 */
final class UnpublishFrame
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
    ) {}

    /**
     * Dépublie ou écarte l'image ; rend `true` si elle était publiée.
     *
     * @throws AuthorizationException l'image a quitté `draft` et `published` entre la garde et le verrou
     * @throws Throwable
     */
    public function handle(Frame $frame, User $curator, ?string $reason): bool
    {
        $wasPublished = DB::transaction(function () use ($frame, $curator, $reason): bool {
            $movie = Movie::query()->whereKey($frame->movie_id)->lockForUpdate()->firstOrFail();
            $locked = Frame::query()->whereKey($frame->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('unpublish', $locked);

            $wasPublished = $locked->availability === ContentAvailability::Published;

            $locked->forceFill([
                'availability' => ContentAvailability::Unpublished,
                'availability_changed_at' => CarbonImmutable::now(),
            ])->save();

            $this->journal->record($curator, AdminActionType::FrameUnpublished, $locked->id, $reason);

            // Synchrone, dans la transaction du geste (spec 10 § 3.2, règle 2).
            $this->projector->recompute($movie);

            return $wasPublished;
        });

        $frame->refresh();

        return $wasPublished;
    }
}
