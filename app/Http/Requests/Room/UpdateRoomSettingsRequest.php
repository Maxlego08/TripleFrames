<?php

namespace App\Http\Requests\Room;

use App\Actions\Room\UpdateRoomSettings;
use App\Settings\RoomSettingsEditor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Écriture des réglages par l'hôte — `room.settings.update`,
 * `PATCH /r/{room}/settings` (spec 50 § 3.1, contrat C0).
 *
 * **Ne valide que la FORME du corps** : un tableau, dont `advanced` est un
 * booléen facultatif. Aucune règle `ValidRoomSettings` (§ 3.1) : une entrée
 * de l'onglet Simple ne se valide qu'une fois composée avec l'état courant
 * (règle D34 du 23/09), et la capacité se compare à l'effectif présent
 * (§ 10) — deux lectures qui n'ont de valeur que sous le verrou du salon.
 * Appliquer `fromInput()` ici, à la charge brute, refuserait toute requête
 * portant `themeKeys` et validerait une entrée partielle contre les défauts
 * au lieu de l'état courant. La validation qui fait autorité est celle de
 * {@see UpdateRoomSettings}, sous verrou : {@see RoomSettingsEditor}, puis
 * `fromInput()`, puis la garde de capacité.
 *
 * **Le corps seul**, jamais la chaîne de requête : le formulaire Wayfinder
 * (`.form()`) porte l'usurpation de méthode `_method=PATCH` dans l'URL, et
 * l'éditeur refuserait toute clé hors de l'onglet (`not_editable`). Les
 * champs de transport du framework (`_method`, `_token`) sont retirés du
 * corps pour la même raison : ce ne sont pas des réglages.
 */
class UpdateRoomSettingsRequest extends FormRequest
{
    /** Champs de transport du framework, jamais des réglages. */
    private const array TRANSPORT_KEYS = ['_method', '_token'];

    /** Sélecteur d'onglet (§ 3.1) : absent, il vaut l'onglet courant. */
    private const string ADVANCED = 'advanced';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            self::ADVANCED => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Le message du value object pour un sélecteur illisible : le même texte
     * que `fromInput()` lèverait sur le même champ.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            self::ADVANCED.'.boolean' => __('validation.room_settings.boolean'),
        ];
    }

    /**
     * Ce qui est validé : le corps posté, jamais la chaîne de requête.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->posted();
    }

    /**
     * Le corps posté, clés camelCase, tel quel — l'éditeur ne coerce rien.
     *
     * @return array<string, mixed>
     */
    public function posted(): array
    {
        /** @var array<string, mixed> $body */
        $body = $this->request->all();

        return Arr::except($body, self::TRANSPORT_KEYS);
    }
}
