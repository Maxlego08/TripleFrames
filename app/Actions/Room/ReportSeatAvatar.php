<?php

namespace App\Actions\Room;

use App\Enums\AdminActionType;
use App\Enums\AvatarKind;
use App\Enums\ReportTarget;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Report;
use App\Models\Room;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Game\CurrentGame;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un siège signale l'avatar téléversé d'un autre siège du même salon — spec
 * 40 § 11.6, D49 du 01/10.
 *
 * Ce qui est signalable : l'avatar AFFICHÉ du siège visé — la nature gelée de
 * sa participation à la partie affichée, sinon sa nature vivante — quand
 * c'est une image téléversée d'un compte rattaché. Un prédéfini ne l'est
 * jamais (I5.9), ni son propre siège.
 *
 * Sous le verrou du compte visé : une ligne `report` par siège signaleur
 * (l'unique `(reporter_player_id, target_user_id)` est relu avant
 * l'insertion), puis le décompte des sièges DISTINCTS depuis
 * `avatar_upload_reports_from`. Au seuil, l'image est masquée et le journal
 * reçoit `avatar.hidden`, geste automatique, dans la même transaction. Rien
 * n'est supprimé : l'administrateur doit voir l'image pour lever ou retirer.
 *
 * La réponse est la même dans tous les cas acceptés — seuil atteint ou non,
 * second clic, image déjà masquée : le signaleur n'apprend rien de plus.
 */
final readonly class ReportSeatAvatar
{
    /** Sièges distincts qui masquent une image (spec 40 § 11.6). */
    public const int DISTINCT_REPORTERS = 2;

    public function __construct(private AdminJournal $journal) {}

    /**
     * @throws ValidationException Le siège visé n'affiche aucune image signalable.
     */
    public function handle(Room $room, Player $reporter, Player $target): void
    {
        $userId = self::reportableUserId($room, $reporter, $target);

        if ($userId === null) {
            $message = __('common.avatar.report.not_reportable');

            throw ValidationException::withMessages([
                'avatar' => [is_string($message) ? $message : 'common.avatar.report.not_reportable'],
            ]);
        }

        DB::transaction(function () use ($reporter, $userId): void {
            $user = User::query()->lockForUpdate()->find($userId);

            if ($user === null || $user->avatar_upload_path === null) {
                return;
            }

            $already = Report::query()
                ->where('reporter_player_id', $reporter->id)
                ->where('target_user_id', $user->id)
                ->exists();

            if (! $already) {
                $report = new Report;
                $report->forceFill([
                    'target_type' => ReportTarget::UploadedAvatar,
                    'reporter_player_id' => $reporter->id,
                    'target_player_id' => null,
                    'target_user_id' => $user->id,
                ])->save();
            }

            if ($user->avatar_upload_hidden_at !== null) {
                return;
            }

            $count = self::reportersSince($user);

            if ($count < self::DISTINCT_REPORTERS) {
                return;
            }

            $user->forceFill(['avatar_upload_hidden_at' => Date::now()])->save();
            $this->journal->recordAutomatic(AdminActionType::AvatarHidden, $user->id, $count);
        });
    }

    /**
     * Les sièges distincts qui ont signalé l'image depuis le début de sa
     * fenêtre de comptage. Partagé par l'écran « Avatars » de l'admin.
     */
    public static function reportersSince(User $user): int
    {
        return Report::query()
            ->where('target_type', ReportTarget::UploadedAvatar->value)
            ->where('target_user_id', $user->id)
            ->when(
                $user->avatar_upload_reports_from !== null,
                fn ($query) => $query->where('created_at', '>=', $user->avatar_upload_reports_from),
            )
            ->distinct()
            ->count('reporter_player_id');
    }

    /**
     * Le compte dont l'image est affichée par le siège visé, ou `null` : autre
     * salon, son propre siège, aucun compte rattaché, ou un avatar qui n'est
     * pas une image téléversée.
     */
    private static function reportableUserId(Room $room, Player $reporter, Player $target): ?int
    {
        if ($target->room_id !== $room->id || $target->id === $reporter->id || $target->user_id === null) {
            return null;
        }

        $kind = $target->avatar_kind;
        $game = CurrentGame::forState($target);

        if ($game !== null) {
            $participation = GamePlayer::query()
                ->where('game_id', $game->id)
                ->where('player_id', $target->id)
                ->first(['id', 'display_avatar_kind']);

            if ($participation !== null) {
                $kind = $participation->display_avatar_kind;
            }
        }

        return $kind === AvatarKind::Upload ? $target->user_id : null;
    }
}
