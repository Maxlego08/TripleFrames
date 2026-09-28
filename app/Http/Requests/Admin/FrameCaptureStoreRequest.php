<?php

namespace App\Http\Requests\Admin;

use App\Concerns\FrameCropValidationRules;
use App\Enums\FrameLevel;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameGeometry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * Ajouter une variante par capture personnelle — contrat C9, spec 20 § 5.4,
 * lot L20-33 (D38 du 28/09).
 *
 * **Formulaire multipart** : le navigateur envoie la source NORMALISÉE — un
 * WebP de `FrameGeometry::MASTER_WIDTH` de large, ramené sous
 * {@see PlatformLimits::frameUploadMaxKilobytes()} —, son minutage dans le
 * film et le rectangle (R-46). Le plafond d'entrée est posé SOUS les limites
 * de PHP (`upload_max_filesize`, `post_max_size`) : un envoi trop lourd
 * échoue en erreur traduite, jamais en 419 muet par un `$_POST` vidé (§ 5.4).
 * Un fichier que PHP a lui-même refusé pour sa TAILLE (`uploaded`) reçoit le
 * même message ; toute autre panne d'envoi, un message neutre. Une source
 * animée est refusée sur son en-tête, avant tout décodage.
 *
 * Ce que cette requête vérifie : le type par le CONTENU (`mimetypes`, lu par
 * `finfo`, jamais l'extension ni le type déclaré), le poids, la largeur entre
 * celle d'une image de jeu et celle du master, le minutage `h:mm:ss`
 * obligatoire, le niveau sans défaut (§ 6.5) et la forme du cadre
 * ({@see FrameCropValidationRules}). Ce qu'elle ne vérifie PAS, et qui l'est
 * par le contrôleur : le paysage, la place d'un cadre admis et la borne de
 * HAUTEUR du plancher, qui dépendent de la hauteur réelle de la source ; puis
 * le job, sur le master qu'il en dérive.
 *
 * Le minutage est un instant DANS L'ŒUVRE, jamais l'outil ni la méthode
 * d'extraction (§ 7.6, A7) : c'est la source que la revue déclarera.
 */
class FrameCaptureStoreRequest extends FormRequest
{
    use FrameCropValidationRules;

    /**
     * Le minutage `h:mm:ss` : heures sur un ou deux chiffres, minutes et
     * secondes de 00 à 59. `D` refuse un saut de ligne final. La forme que
     * `ReviewQueue::timecode()` rend, heures sans zéro de tête : « 00:12:34 »
     * est admis et se relit « 0:12:34 ».
     */
    public const string TIMECODE_PATTERN = '/^(\d{1,2}):([0-5]\d):([0-5]\d)$/D';

    private const int SECONDS_PER_HOUR = 3_600;

    private const int SECONDS_PER_MINUTE = 60;

    private const int MILLISECONDS_PER_SECOND = 1_000;

    /**
     * L'en-tête étendu d'un WebP : `RIFF` (4 octets), taille (4), `WEBP` (4),
     * puis le bloc `VP8X` (4), sa taille (4) et son octet de drapeaux — le
     * 21ᵉ octet du fichier, dont le bit 1 annonce une ANIMATION.
     */
    private const int WEBP_VP8X_HEADER_LENGTH = 21;

    private const int WEBP_VP8X_FLAGS_OFFSET = 20;

    private const int WEBP_ANIMATION_FLAG = 0x02;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Closure|string|Enum>>
     */
    public function rules(): array
    {
        return [
            'source' => [
                'required',
                'file',
                'mimetypes:image/webp',
                'max:'.PlatformLimits::frameUploadMaxKilobytes(),
                'dimensions:min_width='.FrameGeometry::GAME_WIDTH.',max_width='.FrameGeometry::MASTER_WIDTH,
                $this->notAnimated(),
            ],
            'source_timecode' => ['required', 'string', 'regex:'.self::TIMECODE_PATTERN],
            'frame_level' => ['required', 'integer', Rule::enum(FrameLevel::class)],
            ...$this->cropRules(),
        ];
    }

    /**
     * Le ratio exact du cadre, une fois ses champs valides.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->cropAspectCheck()];
    }

    /**
     * Get custom messages for validator errors.
     *
     * Le plafond est rendu dans le message, et non laissé à la règle : un
     * fichier refusé par PHP (`uploaded`) n'a pas de paramètre `:max`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tooHeavy = __('admin.validation.frame_source.max', ['max' => PlatformLimits::frameUploadMaxKilobytes()]);
        $timecode = __('admin.frame.capture.timecode');

        return [
            'source.max' => $tooHeavy,
            'source.uploaded' => $this->uploadFailureMessage($tooHeavy),
            'source.mimetypes' => __('admin.validation.frame_source.mimetypes'),
            'source.dimensions' => __('admin.validation.frame_source.dimensions', ['width' => FrameGeometry::GAME_WIDTH]),
            'source_timecode.required' => $timecode,
            'source_timecode.string' => $timecode,
            'source_timecode.regex' => $timecode,
            ...$this->cropMessages(),
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
            'source' => __('admin.validation.capture_source'),
            'source_timecode' => __('admin.validation.source_timecode'),
            'frame_level' => __('admin.validation.frame_level'),
            ...$this->cropAttributes(),
        ];
    }

    /**
     * Refus d'une source ANIMÉE sur son seul en-tête, avant tout décodage :
     * le traitement la refuserait aussi (`source_animated`), mais une erreur
     * traduite, sous le champ, vaut mieux qu'une image en échec quelques
     * secondes plus tard. Le navigateur, qui repasse toute capture par un
     * canevas, n'en produit jamais : ce refus vise un envoi forgé.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function notAnimated(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile || ! $value->isValid()) {
                return;
            }

            $handle = @fopen($value->getRealPath(), 'rb');

            if ($handle === false) {
                return;
            }

            $header = (string) fread($handle, self::WEBP_VP8X_HEADER_LENGTH);
            fclose($handle);

            $extended = strlen($header) === self::WEBP_VP8X_HEADER_LENGTH
                && str_starts_with($header, 'RIFF')
                && substr($header, 8, 4) === 'WEBP'
                && substr($header, 12, 4) === 'VP8X';

            if ($extended && (ord($header[self::WEBP_VP8X_FLAGS_OFFSET]) & self::WEBP_ANIMATION_FLAG) !== 0) {
                $message = __('admin.validation.frame_source.animated');

                $fail(is_string($message) ? $message : 'admin.validation.frame_source.animated');
            }
        };
    }

    /**
     * Le message d'un fichier que PHP n'a pas reçu (`uploaded`) : le plafond
     * de poids SEULEMENT quand PHP l'a refusé pour sa taille ; toute autre
     * panne d'envoi — répertoire temporaire absent ou plein, envoi
     * interrompu — reçoit un message neutre, qui ne fait pas chercher au
     * curateur une image plus légère.
     */
    private function uploadFailureMessage(string $tooHeavy): string
    {
        $error = $this->file('source') instanceof UploadedFile
            ? $this->file('source')->getError()
            : UPLOAD_ERR_OK;

        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $tooHeavy;
        }

        $message = __('admin.validation.frame_source.upload_failed');

        return is_string($message) ? $message : 'admin.validation.frame_source.upload_failed';
    }

    /** La source reçue, validée. */
    public function source(): UploadedFile
    {
        $source = $this->file('source');

        if (! $source instanceof UploadedFile) {
            throw new LogicException('FrameCaptureStoreRequest : source absente d’une requête validée.');
        }

        return $source;
    }

    /**
     * Le minutage, en millisecondes : des SECONDES entières × 1 000, jamais
     * de millisecondes saisies, pour que `ReviewQueue::timecode()` rende
     * exactement l'instant saisi.
     */
    public function timecodeMs(): int
    {
        if (preg_match(self::TIMECODE_PATTERN, (string) $this->string('source_timecode'), $parts) !== 1) {
            throw new LogicException('FrameCaptureStoreRequest : minutage hors forme dans une requête validée.');
        }

        $seconds = (int) $parts[1] * self::SECONDS_PER_HOUR
            + (int) $parts[2] * self::SECONDS_PER_MINUTE
            + (int) $parts[3];

        return $seconds * self::MILLISECONDS_PER_SECOND;
    }

    /** Le niveau choisi par le curateur, sur l'échelle fermée 1-5. */
    public function frameLevel(): FrameLevel
    {
        return FrameLevel::from($this->integer('frame_level'));
    }
}
