<?php

namespace App\Http\Requests\Settings;

use App\Settings\PlatformLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Téléversement de l'avatar d'un compte — `avatar.store` (spec 40 § 11.2).
 *
 * Le plafond {@see PlatformLimits::avatarUploadMaxKilobytes()} est vérifié ICI,
 * sous `upload_max_filesize` : un dépassement devient une erreur traduite sous
 * `avatar`, jamais un 419 muet. Le type et le contenu, eux, sont relus par
 * `AvatarImage` sur les octets — jamais sur l'extension ni le type déclaré.
 */
class AvatarUploadRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'max:'.PlatformLimits::avatarUploadMaxKilobytes()],
        ];
    }

    /** Les octets reçus. */
    public function source(): string
    {
        $file = $this->file('avatar');

        return $file instanceof UploadedFile ? (string) $file->get() : '';
    }
}
