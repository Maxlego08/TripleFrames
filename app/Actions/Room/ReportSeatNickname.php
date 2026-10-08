<?php

namespace App\Actions\Room;

use App\Enums\AdminActionType;
use App\Enums\PlayerConnectionState;
use App\Enums\ReportTarget;
use App\Models\Player;
use App\Models\Report;
use App\Models\Room;
use App\Support\Admin\AdminJournal;
use App\Support\Moderation\NicknameSeatBroadcast;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un siège signale le pseudo d'un autre siège du même salon — spec 40 § 13.3
 * (sujet 6, n° 8, n° 26 ; D66 du 07/10), même patron que l'image téléversée
 * ({@see ReportSeatAvatar}).
 *
 * **Qui** : un siège tenu du salon, ni expulsé ni parti, sur un AUTRE siège
 * du même salon dont le pseudo n'est pas nul (un pseudo effacé par
 * l'archivage ou la rétention n'a plus rien à signaler). Jamais en solo : un
 * siège solo n'a pas de salon, la route ne l'atteint pas.
 *
 * Sous le verrou du siège visé : une ligne `report` de cible `nickname` par
 * siège signaleur (l'unique `(reporter_player_id, target_player_id)` est relu
 * avant l'insertion), puis le décompte des sièges DISTINCTS depuis
 * `player.nickname_reports_from` (NULL = depuis toujours). Au seuil,
 * `nickname_masked_at` est posé et le journal reçoit `nickname.masked`, geste
 * automatique sous l'acteur `system`, dans la même transaction ; après la
 * validation, `seat.updated` porte l'identité masquée au salon (lobby comme
 * partie, l'identité gelée relisant le masquage sur le siège vivant, I5.10).
 *
 * **Aucune notification, aucun e-mail** (10 § 8.1) : l'avis au joueur masqué
 * est rendu par son propre client, qui reconnaît son identité masquée.
 *
 * La réponse est la même dans tous les cas acceptés — seuil atteint ou non,
 * second clic, pseudo déjà masqué : le signaleur n'apprend rien de plus.
 */
final readonly class ReportSeatNickname
{
    /** Sièges distincts qui masquent un pseudo (spec 40 § 13.3). */
    public const int DISTINCT_REPORTERS = 2;

    public function __construct(
        private AdminJournal $journal,
        private NicknameSeatBroadcast $broadcast,
    ) {}

    /**
     * @throws ValidationException Le signaleur ou le siège visé ne s'y prête pas.
     */
    public function handle(Room $room, Player $reporter, Player $target): void
    {
        if (! self::reportable($room, $reporter, $target)) {
            $message = __('common.player.report.not_reportable');

            throw ValidationException::withMessages([
                'nickname' => [is_string($message) ? $message : 'common.player.report.not_reportable'],
            ]);
        }

        DB::transaction(function () use ($room, $reporter, $target): void {
            $locked = Player::query()->whereBelongsTo($room)->lockForUpdate()->find($target->id);

            if ($locked === null || $locked->nickname === null) {
                return;
            }

            $already = Report::query()
                ->where('reporter_player_id', $reporter->id)
                ->where('target_player_id', $locked->id)
                ->exists();

            if (! $already) {
                $report = new Report;
                $report->forceFill([
                    'target_type' => ReportTarget::Nickname,
                    'reporter_player_id' => $reporter->id,
                    'target_player_id' => $locked->id,
                    'target_user_id' => null,
                ])->save();
            }

            if ($locked->nickname_masked_at !== null) {
                return;
            }

            $count = self::reportersSince($locked);

            if ($count < self::DISTINCT_REPORTERS) {
                return;
            }

            $locked->forceFill(['nickname_masked_at' => Date::now()])->save();
            $this->journal->recordAutomatic(AdminActionType::NicknameMasked, $locked->id, $count);

            // Diffusé après la validation (`ShouldDispatchAfterCommit`).
            $this->broadcast->dispatch($locked);
        });
    }

    /**
     * Les sièges distincts qui ont signalé le pseudo depuis le début de sa
     * fenêtre de comptage. Partagé par l'écran « Modération » de l'admin.
     */
    public static function reportersSince(Player $target): int
    {
        $from = $target->nickname_reports_from;

        return Report::query()
            ->where('target_type', ReportTarget::Nickname->value)
            ->where('target_player_id', $target->id)
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
            ->distinct()
            ->count('reporter_player_id');
    }

    /**
     * Vrai si le signalement est recevable : les deux sièges dans ce salon,
     * distincts ; le signaleur ni expulsé ni parti ; un pseudo à signaler.
     */
    private static function reportable(Room $room, Player $reporter, Player $target): bool
    {
        return $reporter->room_id === $room->id
            && $target->room_id === $room->id
            && $reporter->id !== $target->id
            && ! $reporter->wasKicked()
            && $reporter->connection_state !== PlayerConnectionState::Left
            && $target->nickname !== null;
    }
}
