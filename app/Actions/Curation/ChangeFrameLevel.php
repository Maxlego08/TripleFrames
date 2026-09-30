<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\MovieProjector;
use App\ValueObjects\Admin\AdminActionDetails;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Changer le niveau d'une image — spec 20 § 5.7, arbitrage B3.
 *
 * `frame_level` est une propriété STABLE fixée en curation (`00` §
 * Vocabulaire) : la changer est un geste de curation, et la projection du film
 * est recalculée de façon synchrone, dans la transaction du geste.
 *
 * **Une image publiée sort du jeu et repasse en revue** : passage
 * `unpublished`, ligne `frame.unpublished` au journal, projection recalculée —
 * une seule transaction. Raison : la ligne `frame_review` ne porte pas le
 * niveau, et les items applicables en dépendent (`no_lead_face` aux niveaux 1
 * et 2) ; plutôt que d'inférer la validité d'une preuve, on la rejoue. Ni les
 * octets ni la version de grille ne changent : c'est `availability_changed_at`,
 * posé ici, qui la renvoie dans la file « À revoir » (§ 7.3, B12).
 *
 * **Le motif est écrit par le serveur, en TEXTE** — celui de
 * `admin.frame.level.default_reason`, résolu en français —, jamais la clé :
 * un motif est relu tel quel au journal (§ 2.7).
 *
 * **Refus d'état, traduit, jamais un 403** : `admin.frame.level.locked` pour
 * une image suspendue ou retirée (§ 2.9), relu sous le verrou. Une image en
 * traitement ou en échec change de niveau librement : le niveau n'entre pas
 * dans son rendu.
 *
 * Tout changement effectif écrit sa ligne `frame.level_changed` (D41 du
 * 30/09), dont `details` garde l'ancien niveau écrasé en place — en plus de
 * `frame.unpublished` quand l'image sort du jeu.
 *
 * Le même niveau ne change rien : aucune écriture, aucune ligne, et une image
 * publiée reste en jeu.
 */
final class ChangeFrameLevel
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
    ) {}

    /**
     * La clé du refus d'un changement de niveau, ou `null` s'il est permis.
     */
    public static function refusal(Frame $frame): ?string
    {
        if (in_array($frame->availability, [ContentAvailability::Suspended, ContentAvailability::Withdrawn], true)) {
            return 'admin.frame.level.locked';
        }

        return null;
    }

    /**
     * Écrit le niveau ; rend `true` si l'image, publiée, est sortie du jeu
     * pour repasser en revue.
     *
     * @throws ValidationException un refus d'état relu sous le verrou
     * @throws Throwable
     */
    public function handle(Frame $frame, User $curator, FrameLevel $level): bool
    {
        $leftPlay = DB::transaction(function () use ($frame, $curator, $level): bool {
            $movie = Movie::query()->whereKey($frame->movie_id)->lockForUpdate()->firstOrFail();
            $locked = Frame::query()->whereKey($frame->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('update', $locked);

            $refusal = self::refusal($locked);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['frame' => __($refusal)]);
            }

            if ($locked->frame_level === $level) {
                return false;
            }

            $wasPublished = $locked->availability === ContentAvailability::Published;
            $from = $locked->frame_level;

            $attributes = ['frame_level' => $level];

            if ($wasPublished) {
                $attributes['availability'] = ContentAvailability::Unpublished;
                $attributes['availability_changed_at'] = CarbonImmutable::now();
            }

            $locked->forceFill($attributes)->save();

            $this->journal->record(
                $curator,
                AdminActionType::FrameLevelChanged,
                $locked->id,
                details: AdminActionDetails::levelChanged($from, $level),
            );

            if ($wasPublished) {
                $this->journal->record(
                    $curator,
                    AdminActionType::FrameUnpublished,
                    $locked->id,
                    self::defaultReason(),
                );
            }

            // Synchrone, dans la transaction du geste (spec 10 § 3.2, règle 2).
            $this->projector->recompute($movie);

            return $wasPublished;
        });

        $frame->refresh();

        return $leftPlay;
    }

    /**
     * Le motif écrit par le serveur, résolu en TEXTE et en français (§ 2.7) :
     * hors requête, la locale de l'instance laisserait la clé brute.
     */
    private static function defaultReason(): string
    {
        return (string) __('admin.frame.level.default_reason', [], Locale::French->value);
    }
}
