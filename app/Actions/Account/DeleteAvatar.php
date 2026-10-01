<?php

namespace App\Actions\Account;

use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Le titulaire supprime son image téléversée — spec 40 § 11.2, D49 du 01/10.
 *
 * Le chemin est vidé sous verrou, la nature redescend au prédéfini du compte
 * (ou aux initiales), et le fichier part APRÈS le commit. Un masquage en cours
 * est CONSERVÉ : supprimer puis téléverser ne lève jamais un masquage.
 */
final class DeleteAvatar
{
    public function handle(User $user): void
    {
        $oldPath = DB::transaction(function () use ($user): ?string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $oldPath = $locked->avatar_upload_path;

            $locked->forceFill([
                'avatar_upload_path' => null,
                'avatar_kind' => self::fallbackKind($locked),
            ])->save();

            return $oldPath;
        });

        UploadedAvatars::deleteQuietly($oldPath);

        $user->refresh();
    }

    /**
     * La nature après la disparition de l'image : inchangée si l'image n'était
     * pas la nature effective, sinon le prédéfini du compte, sinon les
     * initiales (`null`). Partagée par le retrait de l'administrateur.
     */
    public static function fallbackKind(User $user): ?AvatarKind
    {
        if ($user->avatar_kind !== AvatarKind::Upload) {
            return $user->avatar_kind;
        }

        return $user->avatar_preset !== null ? AvatarKind::Preset : null;
    }
}
