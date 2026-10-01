<?php

namespace App\Actions\Account;

use App\Avatars\AvatarImage;
use App\Avatars\AvatarImageException;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Téléversement de l'avatar d'un compte — spec 40 § 11.2, D49 du 01/10.
 *
 * Normalisation d'abord ({@see AvatarImage}), hors transaction ; écriture sous
 * un NOUVEAU nom ; bascule du chemin, de la nature (`upload` : téléverser,
 * c'est choisir) et de la fenêtre de signalements sous `lockForUpdate` ;
 * suppression de l'ancien fichier APRÈS le commit. Refusé tant qu'un masquage
 * ou un retrait bloque le compte, relu sous le verrou : sans quoi un masquage
 * se contournerait en téléversant une autre image.
 */
final class UploadAvatar
{
    /**
     * @throws ValidationException Image refusée, ou téléversement bloqué.
     */
    public function handle(User $user, string $source): void
    {
        if ($user->isAvatarUploadBlocked()) {
            throw self::refusal('account.avatar.errors.blocked');
        }

        try {
            $webp = AvatarImage::normalize($source);
        } catch (AvatarImageException $exception) {
            throw self::refusal($exception->failure->messageKey());
        }

        $newPath = UploadedAvatars::newPath();
        UploadedAvatars::disk()->put($newPath, $webp);

        $oldPath = null;
        $blocked = false;

        try {
            DB::transaction(function () use ($user, $newPath, &$oldPath, &$blocked): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);

                if ($locked->isAvatarUploadBlocked()) {
                    $blocked = true;

                    return;
                }

                $oldPath = $locked->avatar_upload_path;

                $locked->forceFill([
                    'avatar_upload_path' => $newPath,
                    'avatar_kind' => AvatarKind::Upload,
                    'avatar_upload_reports_from' => Date::now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            UploadedAvatars::deleteQuietly($newPath);

            throw $exception;
        }

        if ($blocked) {
            UploadedAvatars::deleteQuietly($newPath);

            throw self::refusal('account.avatar.errors.blocked');
        }

        if ($oldPath !== null && $oldPath !== $newPath) {
            UploadedAvatars::deleteQuietly($oldPath);
        }

        $user->refresh();
    }

    private static function refusal(string $key): ValidationException
    {
        $message = __($key);

        return ValidationException::withMessages(['avatar' => [is_string($message) ? $message : $key]]);
    }
}
