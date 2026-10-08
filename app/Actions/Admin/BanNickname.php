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
 * Bannir un pseudo — spec 40 § 13.3 (n° 8, D66 du 07/10), écran de 20
 * § 11.5. Administrateur seul, **motif obligatoire**.
 *
 * Sous le verrou du siège : le masquage est posé s'il ne l'était pas, et il
 * devient **définitif** — la levée est ensuite refusée. L'état se lit dans le
 * journal ({@see NicknameModerationState}), aucune colonne.
 *
 * La **forme** du pseudo n'est jamais écrite dans `admin_action` (ligne
 * permanente, aucune donnée personnelle) : `details` reste NULL. L'écran
 * liste les sièges bannis dont le pseudo n'est pas encore anonymisé, avec
 * leur forme repliée à recopier dans
 * `resources/moderation/nicknames/banned.txt`, au commit et au déploiement
 * suivants — geste humain relu. Aucune table de pseudos interdits (A15 de
 * `10`).
 */
final readonly class BanNickname
{
    public function __construct(
        private AdminJournal $journal,
        private NicknameSeatBroadcast $broadcast,
    ) {}

    /**
     * @throws ValidationException Siège déjà banni.
     */
    public function handle(User $actor, Player $target, string $reason): void
    {
        DB::transaction(function () use ($actor, $target, $reason): void {
            $locked = Player::query()->lockForUpdate()->findOrFail($target->id);

            if (NicknameModerationState::isBanned($locked->id)) {
                $message = __('admin.moderation.errors.already_banned');

                throw ValidationException::withMessages([
                    'reason' => [is_string($message) ? $message : 'admin.moderation.errors.already_banned'],
                ]);
            }

            $wasMasked = $locked->nickname_masked_at !== null;

            if (! $wasMasked) {
                $locked->forceFill(['nickname_masked_at' => Date::now()])->save();
            }

            $this->journal->record($actor, AdminActionType::NicknameBanned, $locked->id, $reason);

            if (! $wasMasked) {
                $this->broadcast->dispatch($locked);
            }
        });

        $target->refresh();
    }
}
