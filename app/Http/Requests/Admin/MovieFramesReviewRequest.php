<?php

namespace App\Http\Requests\Admin;

use App\Actions\Curation\ReviewMovieFrames;
use App\Support\Curation\ReviewQueue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valider en lot les images d'un film — D42 du 30/09, spec 20 § 7.9.
 *
 * Un seul champ, `frames` : la liste des images que la confirmation a
 * montrées, chacune `{ id, hash }` — son identifiant et l'empreinte des
 * octets affichés. Ni réponses ni décision : le serveur répond « rien à
 * signaler » à la grille COURANTE du niveau de chaque image, et relit sous
 * verrou que la liste est exactement celle du lot et qu'aucune empreinte n'a
 * changé ({@see ReviewMovieFrames}). Tout ou rien. Au plus
 * {@see ReviewQueue::BATCH_MAX_FRAMES} images.
 */
class MovieFramesReviewRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        return [
            'frames' => ['required', 'array', 'min:1', 'max:'.ReviewQueue::BATCH_MAX_FRAMES],
            'frames.*' => ['required', 'array:id,hash'],
            'frames.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'frames.*.hash' => ['required', 'string', 'size:'.FrameReviewStoreRequest::HASH_LENGTH],
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
            'frames' => __('admin.validation.frames'),
            'frames.*' => __('admin.validation.frames'),
            'frames.*.id' => __('admin.validation.frames'),
            'frames.*.hash' => __('admin.validation.reviewed_hash'),
        ];
    }

    /**
     * L'empreinte affichée, par identifiant d'image.
     *
     * @return array<int, string>
     */
    public function seen(): array
    {
        $seen = [];
        $input = $this->input('frames');

        foreach (is_array($input) ? $input : [] as $row) {
            if (is_array($row)) {
                $seen[(int) $row['id']] = (string) $row['hash'];
            }
        }

        return $seen;
    }
}
