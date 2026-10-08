<?php

namespace App\Support\Account;

use Illuminate\Support\Facades\Cache;

/**
 * La clé du cache court de l'historique d'un compte — spec 40 § 13.4 (D66 du
 * 07/10).
 *
 * Seule source de la clé : le lecteur de « Mes parties » (`PlayHistory`) la
 * lit ici, et ses deux oublieurs l'effacent par {@see self::forget()} — la
 * fin d'une partie (`ForgetPlayHistoryCache` sur `GameFinalized`) et le
 * rattachement d'un siège invité au compte (`ClaimSeatForAccount`, § 13.2).
 * Oublier une clé absente ne fait rien : l'oubli est idempotent.
 */
final class PlayHistoryCache
{
    /** Préfixe de la clé, suivi de l'identifiant du compte. */
    public const string PREFIX = 'play-history:';

    /** La clé du cache de ce compte. */
    public static function key(int $userId): string
    {
        return self::PREFIX.$userId;
    }

    /** Oublie le cache de ce compte, présent ou non. */
    public static function forget(int $userId): void
    {
        Cache::forget(self::key($userId));
    }
}
