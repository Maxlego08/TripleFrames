<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingState;
use App\Enums\Locale;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\MovieProjector;
use App\Support\Frames\CropRect;
use App\ValueObjects\Admin\AdminActionDetails;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Re-recadrer une image, **en place** — contrat C9, spec 20 § 5.7, n° 16.
 *
 * Jamais une nouvelle ligne : le rectangle de la même frame est réécrit, et le
 * job redérive le rendu du master déjà normalisé (aucune perte de génération),
 * sous un nouveau `game_path`, avec un nouveau `published_hash`.
 *
 * **Une image publiée sort du jeu AVANT la réécriture** (n° 16) : dans la même
 * transaction que le nouveau rectangle, elle passe `unpublished`, la ligne
 * `frame.unpublished` est écrite au journal et la projection du film est
 * recalculée — sans quoi le job, qui refuse durement une frame `published`,
 * ne pourrait jamais produire le nouveau rendu, et une frame en jeu
 * changerait d'octets sous une revue qui ne les a pas vus. Elle ne revient en
 * jeu qu'après une revue passante sur ses NOUVEAUX octets (§ 7.5) : la revue
 * antérieure cite l'ancienne empreinte, et ne prouve plus rien.
 *
 * **Refus d'état, traduits, jamais des 403** (§ 2.9, C9 § 3) : `locked` pour
 * une image suspendue ou retirée, `busy` pour une image en traitement,
 * `not_ready` pour une image sans rendu ({@see self::refusal()}). Relus SOUS
 * LE VERROU : le contrôleur les oppose d'abord pour répondre juste, l'action
 * les rejoue pour qu'un second onglet ne les contourne pas.
 *
 * **Le temps de recadrage garde le PREMIER recadrage** (§ 10.1, § 10.3) :
 * `crop_seconds` n'est écrit que s'il est encore NULL et que le film est
 * encore en passe 1 (`draft`, jamais publié ni écarté) ; la mesure du pilote
 * ne bouge plus après la terminaison du film.
 *
 * Verrous : le film, puis la frame — l'ordre de {@see AddFrame} —, pour que
 * deux gestes concurrents sur les images d'un même film recalculent sa
 * projection l'un après l'autre, jamais sur un état que l'autre a déjà changé.
 * Le job part sur la file `default` **après le commit**.
 *
 * **Toujours une ligne `frame.recropped`** (D41 du 30/09), publiée ou non,
 * dont `details` garde le rectangle écrasé en place ; le motif facultatif
 * saisi y est recopié. `frame.unpublished` s'y ajoute quand l'image sort du
 * jeu.
 *
 * Au jalon 1, le chemin est **paresseux** (C8 § 4.6) : une manche en cours qui
 * a tiré cette image la substitue à la frappe suivante ; rien n'est poussé.
 */
final class RecropFrame
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
    ) {}

    /**
     * La clé du refus d'état d'un re-recadrage, ou `null` s'il est permis.
     *
     * Ordre : un état posé par l'administrateur prime, puis un traitement en
     * cours, puis l'absence de rendu — une image dont le premier traitement
     * est encore en file est « en traitement », pas « sans rendu ».
     */
    public static function refusal(Frame $frame): ?string
    {
        if (in_array($frame->availability, [ContentAvailability::Suspended, ContentAvailability::Withdrawn], true)) {
            return 'admin.frame.recrop.locked';
        }

        if ($frame->processing_state === FrameProcessingState::Pending) {
            return 'admin.frame.recrop.busy';
        }

        if ($frame->game_path === null) {
            return 'admin.frame.recrop.not_ready';
        }

        return null;
    }

    /**
     * Réécrit le rectangle, fait sortir du jeu une image publiée, et confie le
     * nouveau rendu à la file `default`.
     *
     * `$reason` n'est écrit que si l'image était publiée ; vide, il est
     * remplacé par le texte de `admin.frame.recrop.default_reason`, celui que
     * l'écran pré-remplit — jamais par la clé (§ 2.7).
     *
     * @throws ValidationException un refus d'état relu sous le verrou
     * @throws Throwable
     */
    public function handle(Frame $frame, User $curator, CropRect $crop, ?string $reason, ?int $cropSeconds): void
    {
        DB::transaction(function () use ($frame, $curator, $crop, $reason, $cropSeconds): void {
            $movie = Movie::query()->whereKey($frame->movie_id)->lockForUpdate()->firstOrFail();
            $locked = Frame::query()->whereKey($frame->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('update', $locked);

            $refusal = self::refusal($locked);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['frame' => __($refusal)]);
            }

            $wasPublished = $locked->availability === ContentAvailability::Published;
            $before = CropRect::fromFrame($locked);

            $attributes = [
                'crop_x' => $crop->x,
                'crop_y' => $crop->y,
                'crop_width' => $crop->width,
                'crop_height' => $crop->height,
                'processing_state' => FrameProcessingState::Pending,
                'processing_error' => null,
            ];

            if ($wasPublished) {
                $attributes['availability'] = ContentAvailability::Unpublished;
                $attributes['availability_changed_at'] = CarbonImmutable::now();
            }

            if ($cropSeconds !== null && $locked->crop_seconds === null && self::inFirstPass($movie)) {
                $attributes['crop_seconds'] = self::cappedCropSeconds($cropSeconds);
            }

            $locked->forceFill($attributes)->save();

            $this->journal->record(
                $curator,
                AdminActionType::FrameRecropped,
                $locked->id,
                $reason,
                details: AdminActionDetails::recropped($before, $crop),
            );

            if ($wasPublished) {
                $this->journal->record(
                    $curator,
                    AdminActionType::FrameUnpublished,
                    $locked->id,
                    $reason ?? self::defaultReason(),
                );

                // La frame sortait du comptant : couverture et variantes
                // recalculées dans la même transaction (spec 10 § 3.2, règle 2).
                $this->projector->recompute($movie);
            }

            // `afterCommit` est posé par le job lui-même.
            ProcessFrameImage::dispatch($locked->id);
        });

        $frame->refresh();
    }

    /**
     * Passe 1 : le film n'a jamais été publié ni écarté — la même condition
     * que le battement de débit (§ 10.1).
     */
    private static function inFirstPass(Movie $movie): bool
    {
        return $movie->availability === ContentAvailability::Draft
            && $movie->first_published_at === null;
    }

    /**
     * Le temps de recadrage, plafonné à `catalog.curation.crop_seconds_max` :
     * un cadre oublié ouvert ne fausse pas la médiane du débit (§ 10.1).
     */
    private static function cappedCropSeconds(int $cropSeconds): int
    {
        return min(max(0, $cropSeconds), Config::integer('catalog.curation.crop_seconds_max'));
    }

    /**
     * Le motif pré-rempli, résolu en TEXTE et en français : le back-office est
     * en français seul, et un appel hors requête retomberait sinon sur la
     * locale de l'instance, où la clé resterait brute.
     */
    private static function defaultReason(): string
    {
        return (string) __('admin.frame.recrop.default_reason', [], Locale::French->value);
    }
}
