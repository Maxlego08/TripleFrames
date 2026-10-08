<?php

namespace App\Support\Moderation;

use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Models\AdminAction;

/**
 * L'état « banni » d'un pseudo — spec 40 § 13.3 (n° 8, D66 du 07/10).
 *
 * **Aucune colonne** : un siège est banni si la DERNIÈRE ligne `nickname.*`
 * du journal dont il est le sujet est `nickname.banned`. Le journal porte
 * déjà ces lignes, permanentes ; une colonne dupliquerait un état qu'elles
 * justifient. Lecture servie par `admin_action_subject_idx`.
 */
final class NicknameModerationState
{
    /** Les trois gestes qui font l'état de modération d'un pseudo. */
    public const array ACTIONS = [
        AdminActionType::NicknameMasked,
        AdminActionType::NicknameUnmasked,
        AdminActionType::NicknameBanned,
    ];

    public static function isBanned(int $playerId): bool
    {
        return self::bannedAmong([$playerId]) === [$playerId];
    }

    /**
     * Parmi ces sièges, ceux dont la dernière ligne `nickname.*` est un
     * bannissement.
     *
     * @param  list<int>  $playerIds
     * @return list<int>
     */
    public static function bannedAmong(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $latest = [];

        $lines = AdminAction::query()
            ->where('subject_type', AdminActionSubject::Player->value)
            ->whereIn('subject_id', $playerIds)
            ->whereIn('action', array_map(static fn (AdminActionType $type): string => $type->value, self::ACTIONS))
            ->orderBy('id')
            ->get(['id', 'subject_id', 'action']);

        foreach ($lines as $line) {
            if ($line->subject_id !== null) {
                $latest[$line->subject_id] = $line->action;
            }
        }

        $banned = [];

        foreach ($latest as $playerId => $action) {
            if ($action === AdminActionType::NicknameBanned) {
                $banned[] = $playerId;
            }
        }

        sort($banned);

        return $banned;
    }
}
