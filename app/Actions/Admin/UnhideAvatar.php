<?php

namespace App\Actions\Admin;

use App\Enums\AdminActionType;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lever le masquage ou le retrait d'un avatar téléversé — spec 40 § 11.7,
 * D49 du 01/10, ligne 45 de la matrice de `20`.
 *
 * Sous le verrou du compte : `avatar_upload_hidden_at` vidé, fenêtre de
 * signalements remise à maintenant (les signalements passés ne comptent plus).
 * Lever un compte dont l'image a été retirée rouvre seulement le
 * téléversement. La ligne `avatar.unhidden` s'écrit dans la même transaction.
 */
final class UnhideAvatar
{
    public function __construct(private readonly AdminJournal $journal) {}

    /**
     * @throws ValidationException L'avatar n'est ni masqué ni retiré.
     */
    public function handle(User $actor, User $target, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $target, $reason): void {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->avatar_upload_hidden_at === null) {
                $message = __('admin.avatars.errors.not_hidden');

                throw ValidationException::withMessages([
                    'reason' => [is_string($message) ? $message : 'admin.avatars.errors.not_hidden'],
                ]);
            }

            $locked->forceFill([
                'avatar_upload_hidden_at' => null,
                'avatar_upload_reports_from' => Date::now(),
            ])->save();

            $this->journal->record($actor, AdminActionType::AvatarUnhidden, $locked->id, $reason);
        });

        $target->refresh();
    }
}
