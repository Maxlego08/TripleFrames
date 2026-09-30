<?php

namespace App\Http\Requests\Room;

use App\Actions\Room\HandOverHost;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Transfert manuel du rôle d'hôte — `room.host.transfer`,
 * `POST /r/{room}/host`, corps `{ publicId }` (spec 50 § 11.4).
 *
 * **Ne valide que la forme du corps** : `publicId` est une chaîne. La cible
 * est résolue dans ce salon sous le verrou par {@see HandOverHost} — une
 * cible absente répond 404, une cible non connectée ou expulsée revient en
 * erreur sous `publicId` (`room.lobby.transfer_unavailable`). Le `public_id`
 * ne donne aucun droit : il ne fait que désigner un siège.
 */
class HandOverHostRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            HandOverHost::FIELD => ['required', 'string'],
        ];
    }

    /** Le `public_id` du siège désigné, validé. */
    public function publicId(): string
    {
        return $this->string(HandOverHost::FIELD)->toString();
    }
}
