<?php

namespace App\Support\Retention\Handlers;

use App\Actions\Room\ArchiveRoom;
use App\Enums\PurgeScope;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Support\Retention\PurgeHandler;
use App\Support\Retention\PurgeRow;
use App\Support\Retention\RetentionWindows;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Le périmètre `guest_nickname` (D62 du 06/10, spec 10 § 11.1) : le pseudo
 * d'un siège — `player.nickname`, `player.nickname_normalized` et le pseudo
 * figé de ses participations, `game_player.display_nickname` — anonymisé
 * {@see RetentionWindows::GUEST_NICKNAME_MONTHS} mois après sa dernière
 * activité (`last_seen_at`), siège de salon comme siège solo.
 *
 * Jusque-là, le pseudo survit à l'archivage du salon ({@see ArchiveRoom}) :
 * il sert l'analyse des parties (inspection, retours de joueurs). Le jeton,
 * lui, tombe toujours à l'archivage ou à 24 h pour un siège solo.
 *
 * **Anonymiser, jamais supprimer** : les lignes `player` et `game_player`
 * restent, faits de partie de leur propre fenêtre ; seules les trois
 * colonnes passent à NULL, la forme normalisée comprise — l'unicité
 * `player_room_nickname_uq (room_id, nickname_normalized)` admet plusieurs
 * NULL, là où une valeur neutre partagée entrerait en collision.
 *
 * Curseur sur `(last_seen_at, id)`, servi par `player_last_seen_idx`.
 * `rows_deleted` de la ligne `purge_run` porte le nombre de sièges
 * anonymisés.
 */
final readonly class GuestNicknameHandler implements PurgeHandler
{
    /**
     * Les colonnes anonymisées sur la ligne `player` : le pseudo, et le lien
     * au visiteur consentant avec l'appareil du siège (D62 du 06/10).
     */
    private const array SEAT_NICKNAME = ['nickname', 'nickname_normalized', 'visitor_id', 'device_class', 'browser_family', 'os_family'];

    public function scope(): PurgeScope
    {
        return PurgeScope::GuestNickname;
    }

    public function eligibleCount(?CarbonImmutable $asOf = null): int
    {
        return $this->eligible($asOf ?? CarbonImmutable::now())->count();
    }

    public function nextBatch(CarbonImmutable $now, ?PurgeRow $after, int $size): array
    {
        $query = $this->eligible($now)
            ->select(['id', 'last_seen_at'])
            ->orderBy('last_seen_at')
            ->orderBy('id')
            ->limit($size);

        if ($after !== null) {
            $query->where(static fn (Builder $cursor): Builder => $cursor
                ->where('last_seen_at', '>', $after->pilot)
                ->orWhere(static fn (Builder $tie): Builder => $tie
                    ->where('last_seen_at', '=', $after->pilot)
                    ->where('id', '>', $after->key)));
        }

        $rows = [];

        foreach ($query->toBase()->get() as $row) {
            $values = (array) $row;
            $rows[] = PurgeRow::fromValues($values['last_seen_at'] ?? null, $values['id'] ?? null);
        }

        return $rows;
    }

    /**
     * Anonymise le pseudo du siège, s'il est encore éligible sous son
     * verrou : 1 si cet appel l'a anonymisé, 0 sinon (activité reprise, déjà
     * anonymisé, siège disparu).
     */
    public function purge(PurgeRow $row, CarbonImmutable $now): int
    {
        return $this->connection()->transaction(function () use ($row, $now): int {
            $seat = $this->eligible($now)->whereKey($row->key)->lockForUpdate()->first(['id']);

            if (! $seat instanceof Player) {
                return 0;
            }

            Player::query()->whereKey($seat->id)->update(array_fill_keys(self::SEAT_NICKNAME, null));

            GamePlayer::query()
                ->where('player_id', $seat->id)
                ->whereNotNull('display_nickname')
                ->update(['display_nickname' => null]);

            return 1;
        });
    }

    public function connection(): ConnectionInterface
    {
        return DB::connection((new Player)->getConnectionName());
    }

    /**
     * La borne à l'instant `$asOf`, au format de `last_seen_at` (à la
     * milliseconde) : est éligible tout siège dont la dernière activité lui
     * est strictement antérieure.
     */
    private static function cutoff(CarbonImmutable $asOf): string
    {
        return $asOf
            ->subMonths(RetentionWindows::GUEST_NICKNAME_MONTHS)
            ->format((new Player)->getDateFormat());
    }

    /**
     * LE prédicat d'éligibilité, à l'instant `$asOf` : un siège inactif
     * depuis la fenêtre, qui porte encore un pseudo, sur sa ligne ou figé
     * dans une de ses participations.
     *
     * @return Builder<Player>
     */
    private function eligible(CarbonImmutable $asOf): Builder
    {
        return Player::query()
            ->where('last_seen_at', '<', self::cutoff($asOf))
            ->where(static function (Builder $nickname): void {
                foreach (self::SEAT_NICKNAME as $column) {
                    $nickname->orWhereNotNull($column);
                }

                $nickname->orWhereHas(
                    'gamePlayers',
                    static fn (Builder $participation) => $participation->whereNotNull('display_nickname'),
                );
            });
    }
}
