<?php

namespace App\Support\Draw;

use App\Models\Round;
use App\Settings\PlatformLimits;
use Carbon\CarbonImmutable;

/**
 * Fenêtre unique de la mémoire du salon (spec 30 § 3.5, contrat C2, E10-56).
 *
 * `since(salon, now) = max(now − roomMemoryWindowDays() jours, t)`, où `t` est le
 * `started_at` de la `roomMemoryWindowRounds()`-ième manche **jouée** la plus
 * récente du salon, ou `now − roomMemoryWindowDays()` seul s'il y en a moins :
 * **la borne atteinte en premier gagne**.
 *
 * Une seule fenêtre pour trois usages — la non-répétition des films
 * ({@see PoolQuery}, clause 4), la préférence de variante (`seen_frame`, § 7) et
 * la purge de `seen_frame` (spec 10 § 11.1) — parce qu'une seule notion de
 * « récemment vu par le salon » s'explique et se purge. Aucune de ces valeurs
 * n'entre dans un calcul rejoué : le tirage est matérialisé.
 *
 * **« Jouée » = `started_at <= now`** (§ 1.3), quel que soit le statut, manche
 * annulée comprise : une manche programmée dont `T₁` n'est pas atteint, une
 * manche de réserve jamais démarrée ou déprogrammée à la pause (`started_at`
 * nul) ne comptent pas.
 *
 * La mémoire est portée par `round.room_id`, jamais par un joueur : un nouveau
 * salon repart d'une mémoire vide (§ 4.5).
 *
 * Appelée **une fois** par lancement, par {@see PoolScope::forRoom()} et
 * {@see PoolScope::forGame()} : deux appels à quelques millisecondes d'écart
 * pourraient chevaucher une frontière de la fenêtre et faire diverger la garde
 * et le tirage (§ 6.1) ; et, hors lancement, par
 * {@see VariantChooser::substitute()} à chaque substitution, à l'instant de la
 * frappe (§ 7.2, § 8.1), jamais en solo. Une lecture, sans verrou ni écriture,
 * servie par `round_room_started_movie_idx`.
 */
final readonly class RoomMemoryWindow
{
    /**
     * Borne basse de la mémoire du salon à l'instant `$now`.
     */
    public static function since(int $roomId, CarbonImmutable $now): CarbonImmutable
    {
        $byDays = $now->subDays(PlatformLimits::roomMemoryWindowDays());

        $nthStarted = Round::query()
            ->where('room_id', $roomId)
            ->where('started_at', '<=', self::storedInstant($now))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->offset(PlatformLimits::roomMemoryWindowRounds() - 1)
            ->limit(1)
            ->value('started_at');

        if ($nthStarted instanceof CarbonImmutable && $nthStarted->greaterThan($byDays)) {
            return $nthStarted;
        }

        return $byDays;
    }

    /**
     * L'instant au format EXACT de la colonne (`timestamp(3)`, `#[DateFormat]` de
     * {@see Round}), jamais celui de la grammaire, qui tronque à la seconde.
     *
     * Sous SQLite, `started_at` est un texte : `'…10:00:00.000' <= '…10:00:00'`
     * est faux, et une manche démarrée à la seconde pile ne serait pas jouée.
     */
    private static function storedInstant(CarbonImmutable $at): ?string
    {
        return (new Round)->fromDateTime($at);
    }
}
