<?php

namespace App\Actions\Account;

use App\Avatars\AccountImage;
use App\Enums\AvatarKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le choix explicite de la nature effective d'un avatar de compte — spec 40
 * § 11.1, D49 du 01/10 : un prédéfini, ou l'image téléversée. Le dernier choix
 * l'emporte ; l'image non choisie reste stockée.
 */
final class ChooseAvatar
{
    /**
     * @param  string|null  $preset  Clé validée du catalogue, exigée pour `preset`.
     *
     * @throws ValidationException L'image demandée n'est plus affichable.
     */
    public function handle(User $user, AvatarKind $kind, ?string $preset): void
    {
        DB::transaction(function () use ($user, $kind, $preset): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $image = AccountImage::fromKind($kind);

            if ($image !== null) {
                if (! $image->isVisible($locked)) {
                    $message = __('account.avatar.errors.unavailable');

                    throw ValidationException::withMessages([
                        'avatar' => [is_string($message) ? $message : 'account.avatar.errors.unavailable'],
                    ]);
                }

                $locked->forceFill(['avatar_kind' => $kind])->save();

                return;
            }

            $locked->forceFill([
                'avatar_kind' => AvatarKind::Preset,
                'avatar_preset' => $preset,
            ])->save();
        });

        $user->refresh();
    }
}
