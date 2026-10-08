<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\TakedownRequest;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\MovieProjector;
use App\Support\Curation\RetroactiveGrid;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Le **geste rétroactif de grille** — spec 20 § 7.7, D13 du 23/09, L20-25 ;
 * ligne 33 de la matrice, administrateur seul.
 *
 * Sous une version de grille marquée `retroactive` (motif juridique), dépublie
 * les images `published` revues à une version antérieure, aux seuls niveaux
 * que touchent les items ajoutés ({@see RetroactiveGrid}). Une image y revient
 * par une nouvelle revue à la version courante.
 *
 * - **Une transaction par film**, dans l'ordre des identifiants : verrou du
 *   film, puis de ses images visées relues sous le verrou, passage
 *   `unpublished`, une ligne `frame.grid_unpublished` par image (motif
 *   obligatoire, demande de retrait liée s'il y en a une), projection
 *   recalculée (`MovieProjector::recompute`). Un film n'attend jamais qu'un
 *   autre soit traité, et une panne au milieu laisse les films déjà traités
 *   dans un état cohérent — le second passage reprend les autres.
 * - **Idempotent** : une image dépubliée n'est plus `published`, donc plus
 *   visée ; un second passage ne trouve rien.
 * - **Aucune dépublication de film** : un film qui perd sa couverture 1-3-5
 *   reste `published`, jouable par repli de niveau tant qu'un `N` tient, et
 *   s'affiche « incomplet » (§ 8.4, E10-23).
 * - **Jamais une suppression** de ligne ni de fichier : ce n'est pas un
 *   retrait juridique.
 */
final class ApplyRetroactiveGrid
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
        private readonly RetroactiveGrid $grid,
    ) {}

    /**
     * Rend le nombre d'images dépubliées.
     *
     * @throws AuthorizationException
     * @throws Throwable
     */
    public function handle(User $admin, string $reason, ?TakedownRequest $takedown = null): int
    {
        Gate::forUser($admin)->authorize('applyRetroactiveGrid', Frame::class);

        if (! $this->grid->isAvailable()) {
            return 0;
        }

        /** @var list<int> $movieIds */
        $movieIds = $this->grid->eligibleFrames()
            ->distinct()
            ->orderBy('movie_id')
            ->pluck('movie_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $total = 0;

        foreach ($movieIds as $movieId) {
            $total += DB::transaction(function () use ($movieId, $admin, $reason, $takedown): int {
                $movie = Movie::query()->whereKey($movieId)->lockForUpdate()->first();

                if (! $movie instanceof Movie) {
                    return 0;
                }

                $frames = $this->grid->eligibleFrames($movie->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($frames->isEmpty()) {
                    return 0;
                }

                $now = CarbonImmutable::now();

                foreach ($frames as $frame) {
                    $frame->forceFill([
                        'availability' => ContentAvailability::Unpublished,
                        'availability_changed_at' => $now,
                    ])->save();

                    $this->journal->record(
                        $admin,
                        AdminActionType::FrameGridUnpublished,
                        $frame->id,
                        $reason,
                        $takedown,
                    );
                }

                // Synchrone, dans la transaction du film (spec 10 § 3.2).
                $this->projector->recompute($movie);

                return $frames->count();
            });
        }

        return $total;
    }
}
