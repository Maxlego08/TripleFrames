<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Models\Frame;
use App\Models\MovieProjection;
use App\Settings\RoomSettingsBounds;
use App\ValueObjects\Catalog\FrameLevelCoverage;

/**
 * L'avertissement de perte de couverture, **avant confirmation** — spec 20
 * § 8.4 et § 5.7, E10-23, n° 4.
 *
 * Un geste qui fait sortir une image du jeu — la dépublier, la re-recadrer ou
 * changer son niveau alors qu'elle est publiée — peut casser la couverture
 * 1-3-5 d'un film publié. Le geste reste permis et le film reste publié,
 * jouable jusqu'à un certain `N` avec repli de niveau ; l'écran le dit AVANT
 * l'envoi, par `admin.frame.unpublish.coverage_warning` (`:max` = ce `N`) ou
 * `….coverage_warning_unplayable` quand aucun `N` n'est plus jouable.
 *
 * Un film publié DÉJÀ incomplet n'a plus de couverture 1-3-5 à casser : il
 * n'est annoncé que si le geste lui retire son dernier `N` jouable, par la
 * variante sans nombre — c'est, pour un geste sur une seule image, le seul
 * chemin qui l'atteint (retirer un niveau d'un masque qui couvre 1, 3 et 5
 * en laisse au moins deux, donc `N` = 2 reste jouable).
 *
 * **Lecture seule** : rien n'est écrit, pas même la projection. Le calcul part
 * de `movie_projection`, recalculée de façon synchrone à chaque transition, et
 * y retire la frame visée :
 *
 * 1. le masque jouable perd le bit du niveau de la frame si elle en est la
 *    seule variante jouable (`level_{i}_variants = 1`) ;
 * 2. `k` = le plus grand `N` de `RoomSettingsBounds::MIN_FRAMES_PER_ROUND` à
 *    `MAX_FRAMES_PER_ROUND` pour lequel `FrameLevelCoverage::select(N, masque)`
 *    n'est pas `null` (C1), `null` s'il n'y en a aucun.
 *
 * Servi en prop **optionnelle** `unpublish_preview` de l'éditeur de la banque
 * (lot L20-10), au rechargement partiel qui ouvre la confirmation :
 * {@see self::forFrame()} en est la valeur — `null` quand l'écran n'a rien à
 * annoncer.
 */
final class CoverageLossPreview
{
    /**
     * L'avertissement dû pour un geste qui sortirait cette frame du jeu, ou
     * `null` s'il n'y a rien à annoncer : film non publié, frame hors du
     * comptant, couverture 1-3-5 intacte après le geste, ou film déjà
     * incomplet qui reste jouable à au moins un `N` (ou qui ne l'était déjà
     * plus).
     *
     * @return array{frame_id: int, playable_up_to: int|null}|null
     */
    public function forFrame(Frame $frame): ?array
    {
        if (! $frame->isServable() || $frame->movie->availability !== ContentAvailability::Published) {
            return null;
        }

        $projection = MovieProjection::query()->find($frame->movie_id);

        if (! $projection instanceof MovieProjection) {
            return null;
        }

        $mask = $projection->levels_mask;

        if ($projection->variantsForLevel($frame->frame_level) <= 1) {
            $mask &= ~$frame->frame_level->bit();
        }

        $playableUpTo = self::playableUpTo($mask);

        if (! self::isAnnounced($projection, $mask, $playableUpTo)) {
            return null;
        }

        return [
            'frame_id' => $frame->id,
            'playable_up_to' => $playableUpTo,
        ];
    }

    /**
     * Le plus grand `N` permis par `RoomSettingsBounds` auquel un film de ce
     * masque de niveaux reste jouable, repli de niveau compris, ou `null`.
     */
    public static function playableUpTo(int $levelsMask): ?int
    {
        for ($framesPerRound = RoomSettingsBounds::MAX_FRAMES_PER_ROUND; $framesPerRound >= RoomSettingsBounds::MIN_FRAMES_PER_ROUND; $framesPerRound--) {
            if (FrameLevelCoverage::select($framesPerRound, $levelsMask) !== null) {
                return $framesPerRound;
            }
        }

        return null;
    }

    /**
     * Vrai si le geste casse la couverture 1-3-5 d'un film qui l'avait, ou
     * rend injouable à tout `N` un film déjà incomplet qui jouait encore.
     */
    private static function isAnnounced(MovieProjection $projection, int $maskAfter, ?int $playableAfter): bool
    {
        $publishable = MovieProjection::publishableLevelsMask();

        if ($projection->coversPublishableLevels()) {
            return ($maskAfter & $publishable) !== $publishable;
        }

        return $playableAfter === null
            && self::playableUpTo($projection->levels_mask) !== null;
    }
}
