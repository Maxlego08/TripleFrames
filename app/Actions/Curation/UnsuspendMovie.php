<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\Locale;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\MovieProjector;
use App\Support\Curation\SuspensionHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lever la suspension d'un film — spec 20 § 11.2, ligne 30 de la matrice
 * (`can:unsuspend,movie`, administrateur seul).
 *
 * **Une transaction**, le film puis ses images verrouillés :
 *
 * 1. **état antérieur** reconstitué depuis le journal
 *    ({@see SuspensionHistory::priorMovieState()}) : `published`,
 *    `unpublished` (avec le motif de sa dernière dépublication) ou `draft` ;
 * 2. **images suspendues par la cascade** — dernière ligne de sujet `frame`
 *    autre que `frame.suspended` — restaurées sans ligne par image
 *    (invariant 9) : `published` si leur dernière revue passante porte sur
 *    leurs octets et la version de grille courants, sinon `unpublished`, ou
 *    `draft` si elles n'ont jamais été publiées. Une image suspendue
 *    individuellement reste suspendue : elle ne se lève que par son propre
 *    geste ({@see UnsuspendFrame}) ;
 * 3. projection recalculée, puis, pour un film qui revient `published`, la
 *    **garde rejouée** du § 8.1 — contenu `clear`, couverture 1/3/5,
 *    devinabilité ({@see PublishMovie::conditions()}) :
 *    - tenue : l'empreinte de l'aperçu d'ambiguïté montré à
 *      l'administrateur ({@see AmbiguityPreview::forPublication()}, décision
 *      13) doit égaler celle recalculée à l'instant, sinon refus **sans rien
 *      écrire** (`admin.movie.publish.preview_stale`) — jamais une levée
 *      dégradée ;
 *    - en échec : la levée écrit `movie.unsuspended` **puis**
 *      `movie.unpublished`, au motif `admin.movie.unsuspend.guard_failed`,
 *      et le film passe `unpublished` (invariant 10 : sans cette seconde
 *      ligne, une seconde suspension retrouverait le `movie.published`
 *      d'origine) ;
 * 4. ligne `movie.unsuspended`, puis `MovieProjector::recompute` et
 *    `AnswerKeyProjector::recomputeAmbiguity()` sur les formes du film
 *    (spec 10 § 3.2 règle 2, § 3.5).
 *
 * Aucun job : une levée ne réécrit aucune manche, et rien n'est poussé aux
 * lobbies, dont le rapport de vivier se recalcule à la prochaine écriture.
 */
final class UnsuspendMovie
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
        private readonly AnswerKeyProjector $answerKeys,
        private readonly AmbiguityPreview $preview,
    ) {}

    /**
     * Rend l'état où revient le film, et si la garde rejouée a échoué.
     *
     * @param  string|null  $ambiguityDigest  l'empreinte de l'aperçu montré, exigée pour un retour en `published`
     * @return array{state: ContentAvailability, guard_failed: bool}
     *
     * @throws AuthorizationException le film n'est plus suspendu, ou le compte n'est pas administrateur
     * @throws ValidationException aperçu d'ambiguïté périmé, sans aucune écriture
     * @throws Throwable
     */
    public function handle(Movie $movie, User $admin, ?string $reason, ?string $ambiguityDigest): array
    {
        $outcome = DB::transaction(function () use ($movie, $admin, $reason, $ambiguityDigest): array {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($admin)->authorize('unsuspend', $locked);

            $now = Date::now()->toImmutable();
            $prior = SuspensionHistory::priorMovieState($locked);

            $frames = Frame::query()
                ->where('movie_id', $locked->id)
                ->where('availability', ContentAvailability::Suspended->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($frames as $frame) {
                if (! SuspensionHistory::suspendedByCascade($frame)) {
                    continue;
                }

                $frame->forceFill([
                    'availability' => SuspensionHistory::restoredFrameState($frame),
                    'availability_changed_at' => $now,
                ])->save();
            }

            $state = $prior['state'];
            $availabilityReason = $prior['reason'];
            $guardFailed = false;

            if ($state === ContentAvailability::Published) {
                $availabilityReason = null;
                $projection = $this->projector->recompute($locked);

                if (PublishMovie::conditions($locked, $projection)['blockers'] !== []) {
                    $state = ContentAvailability::Unpublished;
                    $availabilityReason = self::guardFailedReason();
                    $guardFailed = true;
                } elseif (! hash_equals($this->preview->forPublication($locked)->digest(), (string) $ambiguityDigest)) {
                    throw ValidationException::withMessages([
                        'ambiguity_digest' => __(PublishMovie::MESSAGE_PREFIX.'preview_stale'),
                    ]);
                }
            }

            $locked->forceFill([
                'availability' => $state,
                'availability_changed_at' => $now,
                'availability_reason' => $availabilityReason,
            ])->save();

            $this->journal->record($admin, AdminActionType::MovieUnsuspended, $locked->id, $reason);

            if ($guardFailed) {
                $this->journal->record($admin, AdminActionType::MovieUnpublished, $locked->id, $availabilityReason);
            }

            // Synchrone, dans la transaction du geste (spec 10 § 3.2, § 3.5) :
            // les images rendues entrent au comptant, le film peut rentrer
            // au catalogue publié.
            $this->projector->recompute($locked);
            $this->answerKeys->recomputeAmbiguity(PublishMovie::formsOf($locked));

            return ['state' => $state, 'guard_failed' => $guardFailed];
        });

        $movie->refresh();

        return $outcome;
    }

    /**
     * L'état où la levée ramènerait le film, lu sans verrou pour l'écran :
     * la confirmation montre l'aperçu d'ambiguïté quand il revient
     * `published` (§ 11.2). `null` si le film n'est pas suspendu.
     */
    public static function restores(Movie $movie): ?ContentAvailability
    {
        return $movie->availability === ContentAvailability::Suspended
            ? SuspensionHistory::priorMovieState($movie)['state']
            : null;
    }

    /**
     * Le motif écrit par le serveur quand la garde rejouée échoue : le TEXTE,
     * en français explicite, jamais la clé (§ 2.7, « Motif écrit par le
     * serveur »).
     */
    public static function guardFailedReason(): string
    {
        return (string) __('admin.movie.unsuspend.guard_failed', [], Locale::French->value);
    }
}
