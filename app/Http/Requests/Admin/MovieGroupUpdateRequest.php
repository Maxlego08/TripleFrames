<?php

namespace App\Http\Requests\Admin;

use App\Actions\Curation\SetMovieGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Regrouper deux films, rejoindre un groupe, ou retirer un film de son
 * groupe — spec 20 § 9.4. Un seul des trois, toujours NOMMÉ :
 *
 * - `with_movie_id` : le film avec lequel regrouper ;
 * - `group_id` : le groupe à rejoindre ;
 * - `leave` (accepté) : « Retirer du groupe ».
 *
 * Le § 9.4 écrit « `with_movie_id` ou `group_id` ou `null` » : ce `null` est
 * dit ici en toutes lettres. Un envoi qui ne nomme rien — un identifiant
 * laissé vide — est refusé sous `with_movie_id`, jamais lu comme un retrait :
 * retirer un film d'un groupe de deux supprime le groupe et sa note, et ce
 * geste passe par sa confirmation.
 *
 * `label` (≤ 120, la largeur de `movie_group.label`) et `note` (≤ 500) sont
 * facultatifs, lus seulement quand un groupe naît : un libellé vide prend le
 * libellé pré-rempli « Titre A (année) / Titre B (année) ».
 *
 * L'existence des deux films, leur état et leurs groupes se relisent sous
 * verrou dans l'action, qui refuse en erreurs traduites.
 */
class MovieGroupUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'with_movie_id' => ['nullable', 'integer', 'min:1', 'prohibits:group_id', 'required_without_all:group_id,leave'],
            'group_id' => ['nullable', 'integer', 'min:1'],
            'leave' => ['sometimes', 'accepted', 'prohibits:with_movie_id,group_id'],
            'label' => ['nullable', 'string', 'max:'.SetMovieGroup::LABEL_MAX_LENGTH],
            'note' => ['nullable', 'string', 'max:'.SetMovieGroup::NOTE_MAX_LENGTH],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'with_movie_id.required_without_all' => (string) __('admin.movie.group.movie_required'),
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
            'with_movie_id' => __('admin.validation.group_movie'),
            'group_id' => __('admin.validation.group'),
            'leave' => __('admin.validation.group_leave'),
            'label' => __('admin.validation.group_label'),
            'note' => __('admin.validation.group_note'),
        ];
    }

    /** Le film avec lequel regrouper, s'il est donné. */
    public function withMovieId(): ?int
    {
        return $this->filled('with_movie_id') ? $this->integer('with_movie_id') : null;
    }

    /** Le groupe à rejoindre, s'il est donné. */
    public function groupId(): ?int
    {
        return $this->filled('group_id') ? $this->integer('group_id') : null;
    }

    /** Vrai quand l'envoi demande EXPLICITEMENT de retirer le film de son groupe. */
    public function leave(): bool
    {
        return $this->boolean('leave');
    }

    /** Le libellé saisi, rogné ; `null` quand il est vide. */
    public function label(): ?string
    {
        return $this->optionalText('label');
    }

    /** La note saisie, rognée ; `null` quand elle est vide. */
    public function note(): ?string
    {
        return $this->optionalText('note');
    }

    private function optionalText(string $key): ?string
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : $value;
    }
}
