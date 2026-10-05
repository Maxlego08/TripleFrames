<?php

namespace App\Http\Requests\Admin;

use App\Support\Curation\FrameBatch;
use App\Support\Curation\FrameBatchException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Le dépôt d'un lot d'images — spec 20 § 5.10, D57 du 05/10.
 *
 * Un fichier JSON au format `tripleframes.frame-batch`, lu et validé ici en
 * entier : un lot illisible ou hors format est refusé sous le champ `batch`,
 * avec le motif de {@see FrameBatchException}, avant tout aperçu.
 */
class FrameBatchStoreRequest extends FormRequest
{
    /** Plafond du fichier, en kilo-octets : un lot ne porte que des références. */
    public const int MAX_KILOBYTES = 1024;

    private ?FrameBatch $batch = null;

    /**
     * @return array<string, array<int, ValidationRule|array<mixed>|string|Closure>>
     */
    public function rules(): array
    {
        return [
            'batch' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'extensions:json'],
        ];
    }

    /**
     * Le lot lui-même, lu une fois les règles de fichier passées.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $file = $this->file('batch');
                $json = $file instanceof UploadedFile ? $file->get() : null;

                try {
                    $this->batch = FrameBatch::fromJson(is_string($json) ? $json : '');
                } catch (FrameBatchException $exception) {
                    $validator->errors()->add('batch', $exception->translated());
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'batch.required' => __('admin.frame_batch.invalid.required'),
            'batch.file' => __('admin.frame_batch.invalid.required'),
            'batch.max' => __('admin.frame_batch.invalid.too_large', ['max' => self::MAX_KILOBYTES]),
            'batch.extensions' => __('admin.frame_batch.invalid.json'),
        ];
    }

    /** Le lot validé. */
    public function batch(): FrameBatch
    {
        return $this->batch ?? throw new \LogicException('Lot lu avant sa validation.');
    }
}
