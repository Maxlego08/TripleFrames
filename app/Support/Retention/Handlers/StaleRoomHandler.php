<?php

namespace App\Support\Retention\Handlers;

use App\Actions\Room\ArchiveRoom;
use App\Enums\PurgeScope;
use App\Models\Room;
use App\Support\Retention\PurgeHandler;
use App\Support\Retention\PurgeRow;
use App\Support\Retention\RetentionWindows;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Périmètre `stale_room` — 10 § 11.1 ; spec 100 § 14 ; 50 § 16.1 et § 16.2 :
 * le **filet** d'un balayage d'archivage qui n'a jamais tourné. Un salon non
 * archivé dont la dernière activité date de plus de
 * {@see RetentionWindows::STALE_ROOM_HOURS} heures est archivé de force.
 *
 * **Archivage forcé, jamais suppression.** Chaque salon passe par
 * {@see ArchiveRoom}, unique chemin d'archivage (50 § 16.2) : il en hérite
 * les effets — `room_code` recyclé, pseudos, formes normalisées, empreintes
 * de jeton et pseudos figés effacés dans la transaction de l'archivage,
 * rattachement tardif fermé, `room.archived` après validation — et les
 * refus : l'action relit le critère sous le verrou du salon (une activité
 * survenue entre la sélection du lot et le verrou l'emporte) et refuse, avec
 * un journal, un salon dont la dernière partie n'est pas figée. Aucune ligne
 * `room`, `player` ni `game_player` n'est supprimée ici : elles partent plus
 * tard, par dépendance (`orphan_player`, J2).
 *
 * **Un seul prédicat** : `archived_at IS NULL AND last_activity_at < borne`,
 * servi par `room_archived_activity_idx (archived_at, last_activity_at)`,
 * écrit une fois ({@see self::eligible()}) et lu par le compte de la sonde et
 * par la sélection du lot ; l'effacement le relit sous verrou, à la même
 * borne ({@see self::cutoff()}), dans l'action. La borne est prise à la
 * seconde, comme celle du balayage de 50 : `last_activity_at` est à la
 * seconde, et une borne fractionnaire séparerait la sélection (tronquée par
 * la base) de la relecture (en PHP).
 *
 * `rows_deleted` de la ligne `purge_run` porte le nombre de salons archivés,
 * compteur générique du journal ; un salon refusé par l'action compte 0,
 * jamais un échec. Ce qu'un refus répété laisse éligible, la sonde `purge`
 * (n° 4) et la sonde `integrity` (n° 1 et 2) le voient.
 */
final readonly class StaleRoomHandler implements PurgeHandler
{
    public function __construct(
        private ArchiveRoom $archive,
    ) {}

    public function scope(): PurgeScope
    {
        return PurgeScope::StaleRoom;
    }

    public function eligibleCount(?CarbonImmutable $asOf = null): int
    {
        return $this->eligible($asOf ?? CarbonImmutable::now())->count();
    }

    public function nextBatch(CarbonImmutable $now, ?PurgeRow $after, int $size): array
    {
        $query = $this->eligible($now)
            ->select(['id', 'last_activity_at'])
            ->orderBy('last_activity_at')
            ->orderBy('id')
            ->limit($size);

        if ($after !== null) {
            $query->where(static fn (Builder $cursor): Builder => $cursor
                ->where('last_activity_at', '>', $after->pilot)
                ->orWhere(static fn (Builder $tie): Builder => $tie
                    ->where('last_activity_at', '=', $after->pilot)
                    ->where('id', '>', $after->key)));
        }

        $rows = [];

        // Valeurs brutes de la base : le curseur se compare à la colonne telle
        // qu'elle est stockée, sans aller-retour par un fuseau ou un format.
        foreach ($query->toBase()->get() as $row) {
            $values = (array) $row;
            $rows[] = PurgeRow::fromValues($values['last_activity_at'] ?? null, $values['id'] ?? null);
        }

        return $rows;
    }

    /**
     * Archive le salon par l'action de 50, qui relit le critère sous verrou :
     * 1 s'il a été archivé par cet appel, 0 s'il a disparu, est déjà archivé,
     * a repris de l'activité ou garde une partie non figée.
     */
    public function purge(PurgeRow $row, CarbonImmutable $now): int
    {
        $room = Room::query()->select(['id'])->find($row->key);

        if (! $room instanceof Room) {
            return 0;
        }

        return $this->archive->handle($room, self::cutoff($now)) ? 1 : 0;
    }

    public function connection(): ConnectionInterface
    {
        return DB::connection((new Room)->getConnectionName());
    }

    /**
     * La borne à l'instant `$asOf`, à la seconde : est éligible tout salon non
     * archivé dont la dernière activité lui est strictement antérieure.
     */
    private static function cutoff(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->startOfSecond()->subHours(RetentionWindows::STALE_ROOM_HOURS);
    }

    /**
     * LE prédicat d'éligibilité du périmètre, à l'instant `$asOf`.
     *
     * @return Builder<Room>
     */
    private function eligible(CarbonImmutable $asOf): Builder
    {
        return Room::query()
            ->whereNull('archived_at')
            ->where('last_activity_at', '<', self::cutoff($asOf));
    }
}
