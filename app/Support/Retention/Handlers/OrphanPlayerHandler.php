<?php

namespace App\Support\Retention\Handlers;

use App\Actions\Game\StartSoloGame;
use App\Actions\Room\ArchiveRoom;
use App\Enums\PurgeScope;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Support\Retention\PurgeHandler;
use App\Support\Retention\PurgeRow;
use App\Support\Retention\RetentionWindows;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Périmètre `orphan_player`, branche **sièges solo** — 10 § 7.1 et § 11.1 ;
 * spec 100 § 14. Un siège solo n'appartient à aucun salon : l'archivage, qui
 * efface les identifiants d'invité d'un siège de salon, ne le touche jamais.
 * Sans ce second déclencheur, son pseudo vivrait douze mois.
 *
 * **Effacement de colonnes, jamais suppression de ligne.** Pour un siège solo
 * (`room_id IS NULL`) dont la dernière activité (`last_seen_at`) date de plus
 * de {@see RetentionWindows::SOLO_SEAT_IDLE_MINUTES} minutes, **dans une seule
 * transaction** : `player.nickname`, `player.nickname_normalized` (une forme
 * repliée du pseudo qui survivrait serait encore le pseudo),
 * `player.player_token_hash` et `player.solo_token_hash` (le créneau
 * d'unicité, « effacé avec lui ») à NULL, puis `game_player.display_nickname`
 * (le pseudo figé) à NULL pour toutes les parties du siège. Aucune
 * interruption ne laisse un siège à moitié effacé. La ligne `player` et ses
 * participations restent, jusqu'à la branche « dépendante » du même
 * périmètre (J2, L100-17), qui les supprimera avec les faits de partie ;
 * `last_seen_at` n'est jamais touchée.
 *
 * **Un seul prédicat** ({@see self::eligible()}) : siège solo, dernière
 * activité strictement antérieure à la borne, et **au moins un identifiant à
 * effacer** — sans cette dernière clause, un siège déjà effacé resterait
 * éligible à jamais, la sonde n° 4 compterait des lignes que plus rien ne
 * peut effacer, et chaque nuit relirait tous les sièges solo des douze
 * derniers mois. Servi par `player_solo_expiry_idx (room_id, last_seen_at)` ;
 * lu par le compte de la sonde, par la sélection du lot et, sous le verrou
 * du siège, par l'effacement. La borne est liée **au format même de la
 * colonne** (`timestamp(3)`, `$dateFormat` de {@see Player}) : liée telle
 * quelle, une date partirait à la seconde, et SQLite, qui compare des
 * chaînes, tiendrait `… 02:10:00.000` pour postérieure à `… 02:10:00` — la
 * borne stricte n'y serait plus distinguable d'une borne large.
 *
 * **Relu sous le verrou du siège** : un siège qui a repris vie entre la
 * sélection du lot et le verrou (battement, geste solo) n'est pas effacé.
 * Premier et seul verrou de la transaction, dans l'ordre global
 * `player → game` que suit aussi le démarrage solo ({@see StartSoloGame}) :
 * un démarrage concurrent attend l'effacement, puis ne trouve plus de siège
 * sous ce jeton, ou l'effacement attend le démarrage.
 *
 * **Refus défensif** (lecture retenue, comme l'archivage de 50,
 * {@see ArchiveRoom}) : un siège dont une partie n'est pas figée
 * (`game.ended_at` NULL) n'est pas effacé, et le refus est journalisé. Un
 * siège repris par un démarrage solo garde sa dernière activité jusqu'à son
 * premier battement : sans ce refus, une purge passée dans cet intervalle
 * effacerait le siège d'une partie qui commence. Hors de ce cas, la
 * terminaison bornée d'une partie solo (pause puis interruption, 60 § 16.6)
 * le rend impossible, et la sonde n° 1 d'`integrity` verrait la partie. Un
 * refus compte 0, jamais un échec : le siège reste éligible, ce que la sonde
 * `purge` voit.
 *
 * `rows_deleted` de la ligne `purge_run` porte le nombre de sièges effacés,
 * compteur générique du journal.
 */
final readonly class OrphanPlayerHandler implements PurgeHandler
{
    /**
     * Les identifiants d'invité que la ligne `player` d'un siège solo porte,
     * effacés ensemble ; le pseudo figé des participations s'y ajoute.
     */
    private const array SEAT_IDENTITY = ['nickname', 'nickname_normalized', 'player_token_hash', 'solo_token_hash'];

    public function scope(): PurgeScope
    {
        return PurgeScope::OrphanPlayer;
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

        // Valeurs brutes de la base : le curseur se compare à la colonne telle
        // qu'elle est stockée, à la milliseconde, sans aller-retour par un
        // format de date.
        foreach ($query->toBase()->get() as $row) {
            $values = (array) $row;
            $rows[] = PurgeRow::fromValues($values['last_seen_at'] ?? null, $values['id'] ?? null);
        }

        return $rows;
    }

    /**
     * Efface les identifiants du siège, s'il est encore éligible sous son
     * verrou : 1 si cet appel les a effacés, 0 si le siège a disparu, n'est
     * plus éligible (activité reprise, déjà effacé) ou garde une partie non
     * figée.
     */
    public function purge(PurgeRow $row, CarbonImmutable $now): int
    {
        return $this->connection()->transaction(function () use ($row, $now): int {
            $seat = $this->eligible($now)->whereKey($row->key)->lockForUpdate()->first(['id']);

            if (! $seat instanceof Player) {
                return 0;
            }

            $unfinished = Game::query()
                ->whereNull('ended_at')
                ->whereHas('gamePlayers', static fn (Builder $participation) => $participation->where('player_id', $seat->id))
                ->value('id');

            if ($unfinished !== null) {
                // Aucune donnée de joueur : des identifiants internes, pour
                // l'exploitant qui croise la sonde n° 1.
                Log::warning('Effacement d’un siège solo refusé : une de ses parties n’est pas figée.', [
                    'player_id' => $seat->id,
                    'game_id' => $unfinished,
                ]);

                return 0;
            }

            // Mise à jour Eloquent : `updated_at` à la milliseconde
            // (`$dateFormat` de `Player`), `last_seen_at` jamais touchée.
            Player::query()->whereKey($seat->id)->update(array_fill_keys(self::SEAT_IDENTITY, null));

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
     * milliseconde) : est éligible tout siège solo dont la dernière activité
     * lui est strictement antérieure.
     */
    private static function cutoff(CarbonImmutable $asOf): string
    {
        return $asOf
            ->subMinutes(RetentionWindows::SOLO_SEAT_IDLE_MINUTES)
            ->format((new Player)->getDateFormat());
    }

    /**
     * LE prédicat d'éligibilité du périmètre, à l'instant `$asOf` : un siège
     * solo inactif depuis la fenêtre, qui porte encore au moins un
     * identifiant d'invité.
     *
     * @return Builder<Player>
     */
    private function eligible(CarbonImmutable $asOf): Builder
    {
        return Player::query()
            ->whereNull('room_id')
            ->where('last_seen_at', '<', self::cutoff($asOf))
            ->where(static function (Builder $identity): void {
                foreach (self::SEAT_IDENTITY as $column) {
                    $identity->orWhereNotNull($column);
                }

                $identity->orWhereHas(
                    'gamePlayers',
                    static fn (Builder $participation) => $participation->whereNotNull('display_nickname'),
                );
            });
    }
}
