<?php

namespace App\Actions\Room;

use App\Avatars\AccountImage;
use App\Enums\AdminActionType;
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
 * c'est une image personnelle d'un compte rattaché : téléversée, ou copie de
 * la photo du fournisseur (§ 12.6, D51 du 01/10), chacune sur ses colonnes
 * ({@see AccountImage}). Un prédéfini ne l'est
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
        $reportable = self::reportable($room, $reporter, $target);

        if ($reportable === null) {
            $message = __('common.avatar.report.not_reportable');

            throw ValidationException::withMessages([
                'avatar' => [is_string($message) ? $message : 'common.avatar.report.not_reportable'],
            ]);
        }

        [$userId, $image] = $reportable;

        DB::transaction(function () use ($reporter, $userId, $image): void {
            $user = User::query()->lockForUpdate()->find($userId);

            if ($user === null || $image->path($user) === null) {
                return;
            }

            // L'unique `(reporter_player_id, target_user_id)` vaut pour les deux
            // images d'un compte : un siège ne signale un compte qu'une fois.
            $already = Report::query()
                ->where('reporter_player_id', $reporter->id)
                ->where('target_user_id', $user->id)
                ->exists();

            if (! $already) {
                $report = new Report;
                $report->forceFill([
                    'target_type' => $image->reportTarget(),
                    'reporter_player_id' => $reporter->id,
                    'target_player_id' => null,
                    'target_user_id' => $user->id,
                ])->save();
            }

            if ($image->hiddenAt($user) !== null) {
                return;
            }

            $count = self::reportersSince($user, $image);

            if ($count < self::DISTINCT_REPORTERS) {
                return;
            }

            $user->forceFill([$image->hiddenColumn() => Date::now()])->save();
            $this->journal->recordAutomatic(AdminActionType::AvatarHidden, $user->id, $count);
        });
    }

    /**
     * Les sièges distincts qui ont signalé l'image depuis le début de sa
     * fenêtre de comptage. Partagé par l'écran « Avatars » de l'admin.
     */
    public static function reportersSince(User $user, AccountImage $image = AccountImage::Upload): int
    {
        $from = $image->reportsFrom($user);

        return Report::query()
            ->where('target_type', $image->reportTarget()->value)
            ->where('target_user_id', $user->id)
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
            ->distinct()
            ->count('reporter_player_id');
    }

    /**
     * Le compte et l'image affichés par le siège visé, ou `null` : autre
     * salon, son propre siège, aucun compte rattaché, ou un avatar qui n'est
     * pas une image personnelle.
     *
     * @return array{0: int, 1: AccountImage}|null
     */
    private static function reportable(Room $room, Player $reporter, Player $target): ?array
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

        $image = AccountImage::fromKind($kind);

        return $image === null ? null : [$target->user_id, $image];
    }
}
