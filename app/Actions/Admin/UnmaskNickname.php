<?php

namespace App\Actions\Admin;

use App\Enums\AdminActionType;
use App\Models\Player;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Moderation\NicknameModerationState;
use App\Support\Moderation\NicknameSeatBroadcast;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lever le masquage d'un pseudo — spec 40 § 13.3, écran de 20 § 11.5
 * (ligne 35 de la matrice ; D66 du 07/10). Administrateur seul.
 *
 * Sous le verrou du siège : `nickname_masked_at` vidé, fenêtre de comptage
 * remise à maintenant (`nickname_reports_from`), pour que les signalements
 * passés ne remasquent pas au prochain clic. La ligne `nickname.unmasked`
 * s'écrit dans la même transaction ; après la validation, `seat.updated`
 * rend le pseudo au salon s'il vit encore. Refusée pour un siège banni : le
 * bannissement est définitif.
 */
final readonly class UnmaskNickname
{
    public function __construct(
        private AdminJournal $journal,
        private NicknameSeatBroadcast $broadcast,
    ) {}

    /**
     * @throws ValidationException Pseudo non masqué, ou banni.
     */
    public function handle(User $actor, Player $target, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $target, $reason): void {
            $locked = Player::query()->lockForUpdate()->findOrFail($target->id);

            if (NicknameModerationState::isBanned($locked->id)) {
                throw self::refusal('admin.moderation.errors.banned');
            }

            if ($locked->nickname_masked_at === null) {
                throw self::refusal('admin.moderation.errors.not_masked');
            }

            $locked->forceFill([
                'nickname_masked_at' => null,
                'nickname_reports_from' => Date::now(),
            ])->save();

            $this->journal->record($actor, AdminActionType::NicknameUnmasked, $locked->id, $reason);
            $this->broadcast->dispatch($locked);
        });

        $target->refresh();
    }

    private static function refusal(string $key): ValidationException
    {
        $message = __($key);

        return ValidationException::withMessages([
            'reason' => [is_string($message) ? $message : $key],
        ]);
    }
}
