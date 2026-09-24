<?php

namespace App\Http\Requests\Admin;

use App\Enums\FrameLevel;
use App\Settings\PlatformLimits;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameGeometry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Ajouter une variante depuis un visuel TMDB — contrat C9, spec 20 § 5.3.
 *
 * **Formulaire sans fichier** : le navigateur n'envoie que la référence du
 * visuel et le rectangle, et c'est le serveur qui télécharge l'original.
 *
 * Ce que cette requête ne peut PAS vérifier, et qui l'est par le contrôleur :
 * l'appartenance du chemin aux backdrops du film (un FormRequest ne connaît
 * pas `TmdbClient`, `TmdbBoundaryTest`) et la borne de HAUTEUR du plancher,
 * qui dépend de la hauteur du master, donc des métadonnées TMDB. Ici, la
 * forme seule : 16:9 exact, et la largeur entre la largeur minimale et la
 * borne de largeur du plancher, lues dans {@see PlatformLimits} — jamais un
 * littéral (règle 2).
 *
 * Le niveau n'a **aucun défaut** : un défaut serait un classement non décidé
 * (§ 6.5).
 */
class FrameTmdbStoreRequest extends FormRequest
{
    /**
     * Forme d'un chemin de visuel TMDB, la même que
     * `TmdbClient::IMAGE_FILE_PATH_PATTERN` : recopiée et non importée, une
     * requête ne connaissant aucun type de `App\Support\Tmdb`
     * (`TmdbBoundaryTest`). `D` refuse un saut de ligne final.
     */
    public const string TMDB_FILE_PATH_PATTERN = '/^\/[A-Za-z0-9_-]+\.(?:jpg|png)$/D';

    /** Longueur maximale du chemin, celle de la colonne `frame.tmdb_file_path`. */
    public const int TMDB_FILE_PATH_MAX_LENGTH = 255;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Closure|string|Enum>>
     */
    public function rules(): array
    {
        $limits = PlatformLimits::current();

        return [
            'tmdb_file_path' => ['required', 'string', 'max:'.self::TMDB_FILE_PATH_MAX_LENGTH, 'regex:'.self::TMDB_FILE_PATH_PATTERN],
            'frame_level' => ['required', 'integer', Rule::enum(FrameLevel::class)],
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
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['crop_width', 'crop_height'])) {
                    return;
                }

                $width = $this->integer('crop_width');
                $height = $this->integer('crop_height');

                if ($height * FrameGeometry::ASPECT_WIDTH !== $width * FrameGeometry::ASPECT_HEIGHT) {
                    $validator->errors()->add('crop_height', __('admin.validation.crop.aspect'));
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * Un chemin hors forme ne peut désigner aucun backdrop : même message que
     * le refus d'appartenance du contrôleur.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tmdb_file_path.regex' => __('admin.frame.tmdb.not_a_backdrop'),
            'crop_width.multiple_of' => __('admin.validation.crop.aspect'),
            'crop_width.min' => __('admin.validation.crop.too_narrow'),
            'crop_width.max' => __('admin.validation.crop.too_wide'),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tmdb_file_path' => __('admin.validation.tmdb_file_path'),
            'frame_level' => __('admin.validation.frame_level'),
            'crop_x' => __('admin.validation.crop_rect'),
            'crop_y' => __('admin.validation.crop_rect'),
            'crop_width' => __('admin.validation.crop_rect'),
            'crop_height' => __('admin.validation.crop_rect'),
            'crop_seconds' => __('admin.validation.crop_seconds'),
        ];
    }

    /** Le chemin du visuel, tel que TMDB le nomme. */
    public function tmdbFilePath(): string
    {
        return (string) $this->string('tmdb_file_path');
    }

    /** Le niveau choisi par le curateur, sur l'échelle fermée 1-5. */
    public function frameLevel(): FrameLevel
    {
        return FrameLevel::from($this->integer('frame_level'));
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
     * `null` s'il n'est pas fourni. Le plafond est posé par l'action d'ajout,
     * seul écrivain de la colonne.
     */
    public function cropSeconds(): ?int
    {
        return $this->filled('crop_seconds') ? $this->integer('crop_seconds') : null;
    }

    /**
     * Borne de LARGEUR du plancher : `⌊pct × MASTER_WIDTH ÷ 100 ÷ 16⌋ × 16`
     * (§ 5.3). La borne de hauteur, qui dépend du master, est vérifiée par le
     * contrôleur sur les métadonnées TMDB, puis par le job sur le master réel.
     */
    private static function maxCropWidthByWidth(PlatformLimits $limits): int
    {
        return intdiv(
            $limits->frameCropMaxWidthPercent * FrameGeometry::MASTER_WIDTH,
            PlatformLimits::FULL_PERCENT * FrameGeometry::ASPECT_WIDTH,
        ) * FrameGeometry::ASPECT_WIDTH;
    }
}
