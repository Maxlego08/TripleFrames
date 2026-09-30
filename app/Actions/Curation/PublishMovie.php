<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\MovieProjector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Publier un film, ou le republier — spec 20 § 8.1, ligne 19 de la matrice
 * des capacités (`can:publish,movie` : un brouillon, un film dépublié ou
 * écarté).
 *
 * **Un geste explicite du curateur, jamais un déclencheur** (n° 3, AN20-1) :
 * « un film est `published` dès qu'il couvre 1, 3 et 5 » décrit son
 * éligibilité. La couverture et le contenu sont des **gardes de transition**,
 * vérifiées ici et nulle part ailleurs, jamais des invariants d'état
 * (E10-23, A-26) : un film publié qui perd ensuite une image reste publié.
 *
 * **Une transaction, le film verrouillé, la projection recalculée d'abord**,
 * puis les gardes, chacune avec son message, dans cet ordre :
 *
 * 1. contenu vérifié, `content_flag = clear` (`….content_not_clear`) — ni
 *    `unrated_pending`, ni `blocked`, que rien ne lève (décision 12) ;
 * 2. couverture des niveaux 1, 3 et 5 par des variantes JOUABLES, lue sur la
 *    projection par {@see MovieProjection::publishableLevelsMask()}, sans
 *    littéral (`….coverage_missing`, niveaux manquants nommés) ;
 * 3. **devinabilité** : au moins une clé `answer_key` de nature exacte et de
 *    forme non vide (`….not_guessable`, E10-24, C12) — un film dont aucun
 *    titre ne se tape n'entre jamais en jeu ;
 * 4. l'empreinte de l'aperçu d'ambiguïté que le curateur a lu égale celle
 *    recalculée à l'instant (`….preview_stale`, § 8.2) : l'avertissement
 *    nominatif n'est jamais périmé.
 *
 * Puis les écritures : `published`, `availability_changed_at`, motif effacé,
 * `first_published_at` et `curated_by_id` s'ils sont nuls — jamais réécrits :
 * l'auteur de la passe de curation est celui qui publie la première fois —,
 * la ligne `movie.published` (première publication, film écarté compris) ou
 * `movie.republished`, sans motif, et le **recompte synchrone de
 * l'ambiguïté** de toutes les formes du film (spec 10 § 3.5 : jamais un job).
 * Aucun cache de `answer_key` n'existe au J1 (E10-16) : rien à invalider, et
 * rien n'est poussé aux salons ouverts, dont le rapport de vivier se
 * recalcule à la prochaine écriture de réglage (C2 § 4).
 */
final class PublishMovie
{
    /** Condition manquante : le contenu n'est pas vérifié. */
    public const string CONTENT_NOT_CLEAR = 'content_not_clear';

    /** Condition manquante : un des niveaux 1, 3 ou 5 n'a aucune variante jouable. */
    public const string COVERAGE_MISSING = 'coverage_missing';

    /** Condition manquante : aucune clé exacte non vide — aucun titre ne se tape. */
    public const string NOT_GUESSABLE = 'not_guessable';

    /** Préfixe des messages de refus, une feuille par condition, plus `preview_stale`. */
    public const string MESSAGE_PREFIX = 'admin.movie.publish.';

    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
        private readonly AnswerKeyProjector $answerKeys,
        private readonly AmbiguityPreview $preview,
    ) {}

    /**
     * Publie le film ; rend `true` si c'était sa première publication.
     *
     * @param  string  $ambiguityDigest  l'empreinte de l'aperçu que l'écran a montré
     *
     * @throws AuthorizationException le film a quitté `draft` et `unpublished` entre la garde et le verrou
     * @throws ValidationException une condition manquante, ou un aperçu périmé — sans aucune écriture
     * @throws Throwable
     */
    public function handle(Movie $movie, User $curator, string $ambiguityDigest): bool
    {
        $first = DB::transaction(function () use ($movie, $curator, $ambiguityDigest): bool {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('publish', $locked);

            $projection = $this->projector->recompute($locked);

            $this->guard($locked, $projection, $ambiguityDigest);

            $now = Date::now()->toImmutable();
            $first = $locked->first_published_at === null;

            $attributes = [
                'availability' => ContentAvailability::Published,
                'availability_changed_at' => $now,
                'availability_reason' => null,
            ];

            if ($first) {
                $attributes['first_published_at'] = $now;
            }

            if ($locked->curated_by_id === null) {
                $attributes['curated_by_id'] = $curator->id;
            }

            $locked->forceFill($attributes)->save();

            $this->journal->record(
                $curator,
                $first ? AdminActionType::MoviePublished : AdminActionType::MovieRepublished,
                $locked->id,
            );

            // Le film entre au catalogue publié : ses formes pèsent de nouveau
            // dans le recompte, dans la transaction du geste.
            $this->answerKeys->recomputeAmbiguity(self::formsOf($locked));

            return $first;
        });

        $movie->refresh();

        return $first;
    }

    /**
     * Les conditions de publication qui manquent au film — la même lecture
     * sert la garde sous verrou et le bouton « Publier le film », inactif
     * avec la condition manquante nommée (§ 8.1).
     *
     * `first` : la prochaine publication serait la première (ligne
     * `movie.published`), ou une republication. `missing_levels` : les
     * niveaux exigés sans variante jouable, dans l'ordre croissant.
     *
     * @return array{first: bool, blockers: list<string>, missing_levels: list<int>}
     */
    public static function conditions(Movie $movie, ?MovieProjection $projection): array
    {
        $blockers = [];

        if ($movie->content_flag !== ContentFlag::Clear) {
            $blockers[] = self::CONTENT_NOT_CLEAR;
        }

        $missing = self::missingLevels($projection);

        if ($missing !== []) {
            $blockers[] = self::COVERAGE_MISSING;
        }

        if (! self::isGuessable($movie)) {
            $blockers[] = self::NOT_GUESSABLE;
        }

        return [
            'first' => $movie->first_published_at === null,
            'blockers' => $blockers,
            'missing_levels' => $missing,
        ];
    }

    /**
     * Les formes normalisées du film — toutes natures confondues : sa sortie
     * ou son entrée au catalogue publié change le compte de chacune.
     *
     * @return list<string>
     */
    public static function formsOf(Movie $movie): array
    {
        return array_values(array_map(
            static fn (mixed $form): string => (string) $form,
            AnswerKey::query()->where('movie_id', $movie->id)->pluck('normalized')->all(),
        ));
    }

    /**
     * Les gardes de transition, dans l'ordre du § 8.1 ; la première qui
     * manque refuse le geste.
     *
     * @throws ValidationException
     */
    private function guard(Movie $movie, MovieProjection $projection, string $ambiguityDigest): void
    {
        $conditions = self::conditions($movie, $projection);
        $blocker = $conditions['blockers'][0] ?? null;

        if ($blocker !== null) {
            $replace = $blocker === self::COVERAGE_MISSING
                ? ['levels' => implode((string) __('admin.common.list_separator'), $conditions['missing_levels'])]
                : [];

            throw ValidationException::withMessages([
                'movie' => __(self::MESSAGE_PREFIX.$blocker, $replace),
            ]);
        }

        if (! hash_equals($this->preview->forPublication($movie)->digest(), $ambiguityDigest)) {
            throw ValidationException::withMessages([
                'ambiguity_digest' => __(self::MESSAGE_PREFIX.'preview_stale'),
            ]);
        }
    }

    /**
     * Les niveaux exigés — 1, 3 et 5, lus dans le masque de
     * {@see MovieProjection::publishableLevelsMask()} — sans variante jouable.
     * Un film sans ligne de projection n'en couvre aucun.
     *
     * @return list<int>
     */
    private static function missingLevels(?MovieProjection $projection): array
    {
        $required = MovieProjection::publishableLevelsMask();
        $covered = $projection->levels_mask ?? 0;
        $missing = [];

        foreach (FrameLevel::cases() as $level) {
            if (($required & $level->bit()) !== 0 && ($covered & $level->bit()) === 0) {
                $missing[] = $level->value;
            }
        }

        return $missing;
    }

    /**
     * Garde de devinabilité (E10-24) : une clé de nature exacte — titre
     * original, translittération, titre, alias —, de forme non vide. Une clé
     * dérivée ne compte jamais : un préfixe peut devenir ambigu.
     */
    private static function isGuessable(Movie $movie): bool
    {
        return AnswerKey::query()
            ->where('movie_id', $movie->id)
            ->whereNotIn('key_kind', AnswerKeyKind::collisionCheckedValues())
            ->where('normalized', '!=', '')
            ->exists();
    }
}
