<?php

namespace App\Concerns;

use App\Settings\PlatformLimits;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameGeometry;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Le rectangle de recadrage, tel qu'un formulaire du back-office le poste —
 * contrat C9, spec 20 § 5.2 et § 5.3.
 *
 * Partagé par les trois requêtes qui portent un cadre : l'ajout d'une variante
 * depuis TMDB (`FrameTmdbStoreRequest`) ou par capture
 * (`FrameCaptureStoreRequest`), et le re-recadrage en place
 * (`FrameCropUpdateRequest`). Réservé aux `FormRequest` : les accesseurs
 * lisent l'entrée validée par `$this->integer()`.
 *
 * Ce qu'une requête vérifie : la FORME seule — 16:9 exact, largeur multiple
 * de {@see FrameGeometry::ASPECT_WIDTH}, entre la largeur minimale et la borne
 * de LARGEUR du plancher, toutes deux lues dans {@see PlatformLimits}, jamais
 * en littéral (règle 2). La borne de HAUTEUR et le débordement dépendent de la
 * hauteur du master, qu'une requête ne connaît pas : le contrôleur les vérifie
 * par `FrameGeometry::violation()`, puis le job sur le master réel.
 */
trait FrameCropValidationRules
{
    /**
     * Les cinq champs du cadre et du temps de recadrage.
     *
     * @return array<string, list<string>>
     */
    protected function cropRules(): array
    {
        $limits = PlatformLimits::current();

        return [
            'crop_x' => ['required', 'integer', 'min:0'],
            'crop_y' => ['required', 'integer', 'min:0'],
            'crop_width' => [
                'required',
                'integer',
                'multiple_of:'.FrameGeometry::ASPECT_WIDTH,
                'min:'.$limits->frameCropMinWidthPx,
                'max:'.self::maxCropWidthByWidth($limits),
            ],
            'crop_height' => ['required', 'integer', 'min:1'],
            'crop_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Le ratio exact : `crop_height × 16 = crop_width × 9`, vérifié une fois
     * les deux champs valides chacun pour soi.
     *
     * @return Closure(Validator): void
     */
    protected function cropAspectCheck(): Closure
    {
        return function (Validator $validator): void {
            if ($validator->errors()->hasAny(['crop_width', 'crop_height'])) {
                return;
            }

            $width = $this->integer('crop_width');
            $height = $this->integer('crop_height');

            if ($height * FrameGeometry::ASPECT_WIDTH !== $width * FrameGeometry::ASPECT_HEIGHT) {
                $validator->errors()->add('crop_height', __('admin.validation.crop.aspect'));
            }
        };
    }

    /**
     * Les messages du cadre : la cause géométrique, jamais la règle Laravel.
     *
     * @return array<string, string>
     */
    protected function cropMessages(): array
    {
        return [
            'crop_width.multiple_of' => __('admin.validation.crop.aspect'),
            'crop_width.min' => __('admin.validation.crop.too_narrow'),
            'crop_width.max' => __('admin.validation.crop.too_wide'),
        ];
    }

    /**
     * Les noms de champ, pour les messages génériques de Laravel.
     *
     * @return array<string, string>
     */
    protected function cropAttributes(): array
    {
        return [
            'crop_x' => __('admin.validation.crop_rect'),
            'crop_y' => __('admin.validation.crop_rect'),
            'crop_width' => __('admin.validation.crop_rect'),
            'crop_height' => __('admin.validation.crop_rect'),
            'crop_seconds' => __('admin.validation.crop_seconds'),
        ];
    }

    /** Le rectangle, dans l'espace du master (1920 de large). */
    public function crop(): CropRect
    {
        return new CropRect(
            x: $this->integer('crop_x'),
            y: $this->integer('crop_y'),
            width: $this->integer('crop_width'),
            height: $this->integer('crop_height'),
        );
    }

    /**
     * Le temps de recadrage posté par le recadreur, en secondes entières, ou
     * `null` s'il n'est pas fourni. Le plafond est posé par l'action qui
     * écrit la colonne.
     */
    public function cropSeconds(): ?int
    {
        return $this->filled('crop_seconds') ? $this->integer('crop_seconds') : null;
    }

    /**
     * Borne de LARGEUR du plancher : `⌊pct × MASTER_WIDTH ÷ 100 ÷ 16⌋ × 16`
     * (§ 5.3). La borne de hauteur, qui dépend du master, est vérifiée par le
     * contrôleur, puis par le job sur le master réel.
     */
    private static function maxCropWidthByWidth(PlatformLimits $limits): int
    {
        return intdiv(
            $limits->frameCropMaxWidthPercent * FrameGeometry::MASTER_WIDTH,
            PlatformLimits::FULL_PERCENT * FrameGeometry::ASPECT_WIDTH,
        ) * FrameGeometry::ASPECT_WIDTH;
    }
}
