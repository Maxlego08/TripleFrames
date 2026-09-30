<?php

namespace App\Support\Frames;

use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingState;
use App\Models\Frame;
use App\Settings\PlatformLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Les quatre sondes de frame — spec 20 § 5.9, E10-18 et E10-19.
 *
 * Écrites en tests par la spec 20 (`tests/Feature/Curation/FrameProbesTest.php`)
 * et exécutées en production par la sonde `integrity` de la spec 100 (§ 15),
 * qui appelle CETTE classe : une seule copie de chaque requête existe. Chacune
 * rend un constructeur de requête sur `frame`, donc une liste autant qu'un
 * compte ; en production, chacune doit compter **zéro**.
 *
 * Aucune valeur n'est écrite en littéral : le format vient de
 * {@see FrameGeometry}, le plancher de {@see PlatformLimits}, et chaque valeur
 * part en paramètre lié.
 */
final class FrameProbes
{
    /**
     * Frames `published` sans preuve : `published_review_id IS NULL`.
     *
     * @return Builder<Frame>
     */
    public static function publishedWithoutReview(): Builder
    {
        return Frame::query()
            ->where('availability', ContentAvailability::Published->value)
            ->whereNull('published_review_id');
    }

    /**
     * Frames `published` dont les octets servis ne sont pas ceux que la revue
     * désignée a hachés : `published_hash` différent du `reviewed_hash` de la
     * ligne `published_review_id`, revue absente, ou revue d'une autre frame —
     * une empreinte seule ne lie pas une preuve à sa frame.
     *
     * @return Builder<Frame>
     */
    public static function publishedHashMismatch(): Builder
    {
        return Frame::query()
            ->select('frame.*')
            ->leftJoin('frame_review', static function (JoinClause $join): void {
                $join->on('frame_review.id', '=', 'frame.published_review_id');
            })
            ->where('frame.availability', ContentAvailability::Published->value)
            ->whereNotNull('frame.published_review_id')
            ->where(static function (Builder $query): void {
                $query->whereNull('frame_review.id')
                    ->orWhereNull('frame.published_hash')
                    ->orWhereColumn('frame_review.frame_id', '<>', 'frame.id')
                    ->orWhereColumn('frame_review.reviewed_hash', '<>', 'frame.published_hash');
            });
    }

    /**
     * Frames `ready` hors format servable : dimensions autres que
     * `GAME_WIDTH` × `GAME_HEIGHT`, taille non paddée au multiple de
     * `GAME_PAD_BYTES` ou au-delà du plafond d'encodage, chemin ou empreinte
     * absents.
     *
     * @return Builder<Frame>
     */
    public static function readyOutOfFormat(): Builder
    {
        return Frame::query()
            ->where('processing_state', FrameProcessingState::Ready->value)
            ->where(static function (Builder $query): void {
                $query->whereNull('game_path')
                    ->orWhereNull('published_hash')
                    ->orWhereNull('game_width')
                    ->orWhereNull('game_height')
                    ->orWhereNull('game_bytes')
                    ->orWhere('game_width', '<>', FrameGeometry::GAME_WIDTH)
                    ->orWhere('game_height', '<>', FrameGeometry::GAME_HEIGHT)
                    ->orWhere('game_bytes', '>', FrameGeometry::gameEncodeCeilingBytes())
                    ->orWhereRaw('game_bytes % ? <> 0', [FrameGeometry::GAME_PAD_BYTES]);
            });
    }

    /**
     * La requête d'audit du plancher (D6 du 23/09), aux valeurs COURANTES de
     * `PlatformLimits` : un cadre plus large que la fraction admise de la
     * largeur du master, plus étroit que la largeur minimale, de largeur non
     * multiple du pas, ou hors 16:9 exact.
     *
     * Elle ne vérifie que la LARGEUR : la hauteur du master n'est pas en base,
     * et la borne de hauteur est garantie par le contrôleur puis revalidée par
     * le job sur le master réel. Durcir le plancher rend non conformes des
     * images déjà enregistrées : cette requête les liste, et elles se recadrent
     * une à une (§ 5.2).
     *
     * Elle ne porte donc que sur les frames qu'un geste peut encore corriger,
     * celles qui ont un dérivé et ne sont pas retirées. Une frame `withdrawn`
     * refuse le re-recadrage (`locked`, § 5.7) et n'est plus servie ; une frame
     * sans `game_path` le refuse aussi (`not_ready`) et n'a jamais été servie :
     * elle ne peut entrer en jeu qu'après un traitement réussi, et le job
     * revalide alors le plancher (`crop_invalid`). Les compter rendrait la sonde
     * `integrity` rouge pour toujours après un durcissement, sans aucun remède.
     *
     * @return Builder<Frame>
     */
    public static function cropFloorViolations(PlatformLimits $limits): Builder
    {
        return Frame::query()
            ->where('availability', '<>', ContentAvailability::Withdrawn->value)
            ->whereNotNull('game_path')
            ->where(static function (Builder $query) use ($limits): void {
                $query->whereRaw('crop_width * ? > ? * ?', [
                    PlatformLimits::FULL_PERCENT,
                    $limits->frameCropMaxWidthPercent,
                    FrameGeometry::MASTER_WIDTH,
                ])
                    ->orWhere('crop_width', '<', $limits->frameCropMinWidthPx)
                    ->orWhereRaw('crop_width % ? <> 0', [FrameGeometry::ASPECT_WIDTH])
                    ->orWhereRaw('crop_height * ? <> crop_width * ?', [
                        FrameGeometry::ASPECT_WIDTH,
                        FrameGeometry::ASPECT_HEIGHT,
                    ]);
            });
    }
}
