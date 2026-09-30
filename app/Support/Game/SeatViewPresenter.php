<?php

namespace App\Support\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Support\Identity\PlayerIdentity;
use LogicException;

/**
 * `SeatView` — un siège tel que le salon le voit (spec 60 § 11.4 et § 11.5,
 * contrat C7 § 3) : l'identité affichée de 40 (`PlayerIdentity::toArray()`,
 * contrat C5) prolongée de l'état de siège. Miroir de `SeatView` dans
 * `resources/js/types/game-wire.ts`.
 *
 * - **Au lobby** : identité COURANTE du siège (`PlayerIdentity::fromSeat`),
 *   `firstRoundNumber` nul.
 * - **En partie** : identité GELÉE au lancement (`PlayerIdentity::fromGamePlayer`,
 *   `game_player.display_*`), `firstRoundNumber` = `game_player.first_round_number`.
 *
 * `isHost` = `room.host_player_id = player.id` ; `connection` =
 * `player.connection_state` (présence vive, jamais l'issue figée) ; `kicked`
 * = `kicked_at` non nul. **Aucun identifiant interne** : un siège s'adresse
 * par son `public_id` ; ni `player.id`, ni hash de jeton, ni
 * `active_seat_token`, ni `kicked_at` en clair (10 § 7.1, E10-34).
 *
 * Seul constructeur serveur de la forme : le paquet de resynchronisation
 * ({@see GameStateBuilder}), `seat.joined`, `seat.updated` et `game.launched`
 * (50) la prennent ici.
 *
 * @phpstan-type SeatViewPayload array{publicId: string, nickname: string|null, masked: bool, avatar: array{kind: string|null, url: string|null, altKey: string, initials: string}, isHost: bool, connection: string, kicked: bool, firstRoundNumber: int|null}
 */
final class SeatViewPresenter
{
    /**
     * Colonnes du siège qu'exige une vue en partie, sur la relation `player`
     * de `game_player` : celles de `PlayerIdentity::fromGamePlayer()` et
     * l'état de siège.
     *
     * @var list<string>
     */
    public const array GAME_SEAT_COLUMNS = [...PlayerIdentity::FROZEN_SEAT_COLUMNS, 'connection_state', 'kicked_at'];

    /**
     * Vue d'un siège au lobby.
     *
     * @return SeatViewPayload
     */
    public static function lobby(Player $seat, ?int $hostPlayerId): array
    {
        return [
            ...PlayerIdentity::fromSeat($seat)->toArray(),
            ...self::state($seat, $hostPlayerId),
            'firstRoundNumber' => null,
        ];
    }

    /**
     * Vue d'un siège en partie. La relation `player` doit être chargée avec
     * au moins {@see self::GAME_SEAT_COLUMNS}.
     *
     * @return SeatViewPayload
     *
     * @throws LogicException relation `player` absente ou incomplète.
     */
    public static function inGame(GamePlayer $participation, ?int $hostPlayerId): array
    {
        $identity = PlayerIdentity::fromGamePlayer($participation)->toArray();
        $seat = $participation->player;

        foreach (['connection_state', 'kicked_at'] as $column) {
            if (! array_key_exists($column, $seat->getAttributes())) {
                throw new LogicException("SeatViewPresenter::inGame() exige `player.{$column}` chargé.");
            }
        }

        return [
            ...$identity,
            ...self::state($seat, $hostPlayerId),
            'firstRoundNumber' => $participation->first_round_number,
        ];
    }

    /**
     * La vue d'UN siège telle que la page du salon la tient (§ 11.5) : en
     * partie si `$game` — la partie que décrit le paquet du siège
     * ({@see CurrentGame::forState()} : la partie en cours, à défaut la
     * dernière tant que le salon est en partie, podium compris) — porte une
     * participation de ce siège, identité gelée comprise ; au lobby sinon,
     * siège sans participation compris (il attend la partie suivante).
     * Émetteurs : `seat.updated` au départ et à l'expulsion (50), et aux
     * transitions de présence (60).
     *
     * @return SeatViewPayload
     */
    public static function ofSeat(Player $seat, ?Game $game, ?int $hostPlayerId): array
    {
        if ($game !== null) {
            $participation = GamePlayer::query()
                ->whereBelongsTo($game)
                ->where('player_id', $seat->id)
                ->with('player:'.implode(',', self::GAME_SEAT_COLUMNS))
                ->first();

            if ($participation !== null) {
                return self::inGame($participation, $hostPlayerId);
            }
        }

        return self::lobby($seat, $hostPlayerId);
    }

    /**
     * Les sièges d'un salon au lobby : **tous**, partis et expulsés compris
     * (l'état de chacun est dans sa vue), triés par `joined_at` croissant
     * puis par `id` (50 § 8.1).
     *
     * @return list<SeatViewPayload>
     */
    public static function lobbySeats(Room $room): array
    {
        $seats = Player::query()
            ->whereBelongsTo($room)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        return array_values($seats->map(
            static fn (Player $seat): array => self::lobby($seat, $room->host_player_id),
        )->all());
    }

    /**
     * Les sièges d'une partie : toutes les lignes `game_player`, partis et
     * expulsés compris, par `game_player.id` croissant (60 § 11.5, § 12.2).
     *
     * @return list<SeatViewPayload>
     */
    public static function gameSeats(Game $game, ?int $hostPlayerId): array
    {
        $participations = GamePlayer::query()
            ->whereBelongsTo($game)
            ->with('player:'.implode(',', self::GAME_SEAT_COLUMNS))
            ->orderBy('id')
            ->get();

        return array_values($participations->map(
            static fn (GamePlayer $participation): array => self::inGame($participation, $hostPlayerId),
        )->all());
    }

    /**
     * L'état de siège, hors identité.
     *
     * @return array{isHost: bool, connection: string, kicked: bool}
     */
    private static function state(Player $seat, ?int $hostPlayerId): array
    {
        return [
            'isHost' => $hostPlayerId !== null && $hostPlayerId === $seat->id,
            'connection' => $seat->connection_state->value,
            'kicked' => $seat->kicked_at !== null,
        ];
    }
}
