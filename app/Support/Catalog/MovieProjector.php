<?php

namespace App\Support\Catalog;

use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Models\MovieTitle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Le projecteur de `movie_projection` — tout ce qui est dérivé d'un film, et
 * rien d'autre (§ 3.2).
 *
 * Trois règles, et elles sont testées :
 *
 * 1. **La ligne est créée dans la même transaction que la ligne `movie`**, avec
 *    toutes ses colonnes à leur valeur calculée. Aucun observateur ne crée
 *    jamais une ligne de projection : un `updateOrCreate` d'observateur
 *    insérerait `title_mask_version = 0`, valeur qui n'apparie jamais la
 *    version courante — le film serait jouable mais invisible au tirage des
 *    leurres, et tout le salon basculerait en silence sur `title_original`.
 * 2. **Écriture synchrone, jamais un job de fond.** Un job de fond laisserait
 *    un film retiré encore comptant dans le vivier, et un retrait qui ne sort
 *    pas du vivier est un retrait qui ment.
 * 3. La commande de reprojection, `catalog:reproject`, reconstruit tout et
 *    crée les lignes manquantes avant toute mise à jour. Jouée à chaque
 *    déploiement (étape 7 du hook) et après toute restauration, elle borne à
 *    un déploiement la dégradation voulue temporaire qui suit une
 *    incrémentation de `Locale::MASK_VERSION` : les quatre propositions du
 *    QCM basculant ensemble sur `title_original` pour tout le salon.
 *
 * **Prédicat unique de variante jouable**, lu par la portée
 * {@see Frame::servable()} et jamais réécrit ici : `availability = 'published'`
 * **ET** `processing_state = 'ready'` **ET** `game_path IS NOT NULL`. Les trois
 * ensemble : sans la troisième, un job Imagick à moitié échoué produit un film
 * qui passe la garde de vivier et casse une manche. Le tirage et la
 * substitution (spec 30) lisent la même portée : la projection compte
 * exactement les variantes que le tirage peut retenir.
 *
 * L'import ne crée **aucune** frame — la curation des images est un acte humain
 * (spec 20) —, donc un film fraîchement importé a `levels_count = 0` et n'entre
 * dans aucun vivier. C'est voulu : la projection ne ment jamais.
 */
final class MovieProjector
{
    /**
     * Recalcule la projection d'un film sur l'état **réel** de la base, et
     * jamais sur une promesse. Crée la ligne si elle manque.
     */
    public function recompute(Movie $movie): MovieProjection
    {
        $levelsMask = 0;
        $levelsCount = 0;
        $variantsTotal = 0;

        /** @var array<string, int> $variants */
        $variants = [];

        /** @var EloquentCollection<int, Frame> $servable */
        $servable = Frame::query()
            ->where('movie_id', $movie->id)
            ->servable()
            ->get();

        foreach (FrameLevel::cases() as $level) {
            $count = $servable
                ->filter(static fn (Frame $frame): bool => $frame->frame_level === $level)
                ->count();

            if ($count > 0) {
                $levelsMask |= $level->bit();
                $levelsCount++;
            }

            $variantsTotal += $count;
            $variants[$level->variantsColumn()] = $count;
        }

        $titleLocaleMask = 0;

        /** @var EloquentCollection<int, MovieTitle> $titles */
        $titles = MovieTitle::query()->where('movie_id', $movie->id)->get();

        foreach ($titles as $title) {
            // Un bit par locale **activée** : une ligne `movie_title` en `ko`
            // est un titre affichable qui ne pèse sur aucun masque, faute de bit
            // à occuper. `Locale::tryFrom()` rendant `null`, elle est ignorée.
            $titleLocaleMask |= Locale::tryFrom($title->locale)?->maskBit() ?? 0;
        }

        $projection = MovieProjection::query()->find($movie->id) ?? new MovieProjection;

        $projection->forceFill(array_merge($variants, [
            'movie_id' => $movie->id,
            'levels_mask' => $levelsMask,
            'levels_count' => $levelsCount,
            'variants_total' => $variantsTotal,
            'title_locale_mask' => $titleLocaleMask,
            'title_mask_version' => Locale::MASK_VERSION,
            'recomputed_at' => CarbonImmutable::now(),
        ]));

        $projection->save();

        $movie->setRelation('projection', $projection);

        return $projection;
    }
}
