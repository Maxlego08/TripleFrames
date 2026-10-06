<?php

namespace App\Actions\Admin;

use App\Actions\Account\DeleteAvatar;
use App\Avatars\AccountImage;
use App\Avatars\UploadedAvatars;
use App\Enums\AdminActionType;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retirer l'avatar téléversé d'un compte — spec 40 § 11.7, D49 du 01/10,
 * ligne 45 de la matrice de `20`. Motif obligatoire.
 *
 * Sous le verrou du compte : chemin vidé, masquage posé s'il ne l'était pas
 * (le téléversement reste bloqué jusqu'à une levée), nature ramenée au
 * prédéfini ou aux initiales ; ligne `avatar.removed` dans la même
 * transaction ; fichier supprimé APRÈS le commit.
 */
final class RemoveAvatar
{
    public function __construct(private readonly AdminJournal $journal) {}

    /**
     * @throws ValidationException Le compte ne porte aucune image.
     */
    public function handle(User $actor, User $target, string $reason, AccountImage $image = AccountImage::Upload): void
    {
        $oldPath = DB::transaction(function () use ($actor, $target, $reason, $image): string {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $oldPath = $image->path($locked);

            if ($oldPath === null) {
                $message = __('admin.avatars.errors.no_image');

                throw ValidationException::withMessages([
                    'reason' => [is_string($message) ? $message : 'admin.avatars.errors.no_image'],
                ]);
            }

            $changes = [
                $image->pathColumn() => null,
                $image->hiddenColumn() => $image->hiddenAt($locked) ?? Date::now(),
                'avatar_kind' => DeleteAvatar::fallbackKind($locked, $image),
            ];

            if ($image === AccountImage::Provider) {
                $changes['avatar_provider_source'] = null;
            }

            $locked->forceFill($changes)->save();

            $this->journal->record($actor, AdminActionType::AvatarRemoved, $locked->id, $reason);

            return $oldPath;
        });

        UploadedAvatars::deleteQuietly($oldPath);

        $target->refresh();
    }
}
