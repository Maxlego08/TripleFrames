<?php

namespace App\Support\Game;

use App\Actions\Game\ClaimSeatTab;
use App\Enums\GameMode;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameWire;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Le paquet de resynchronisation `GameStatePacket` — spec 60 § 12.1 et
 * § 12.2, contrat C7 § 2.5 et § 3 (nom et signature figés).
 *
 * **Une seule forme, un seul constructeur** : c'est à la fois la prop
 * initiale `state` de toute page `game/*`, la réponse de `room.state` et
 * celle de `solo.state`. Réponse HTTP à destinataire unique, jamais
 * diffusée. Miroir de `GameStatePacket` (`resources/js/types/game-wire.ts`),
 * clés dans l'ordre du type : enveloppe, puis `mode`, `channels`, `status`,
 * `roundsCount`, `roundsCompleted`, `framesPerRound`, `inputDifficulty`,
 * `maxAnswerLength`, `seats`, `pause`, `round`, `self`, `leaderboard`,
 * `podium`, `nextTransitionAt`.
 *
 * **Branche sans partie** (`$game` nul : lobby, solo pas encore lancé ;
 * lot L60-4) : les canaux du salon (nuls en solo seulement, écart (a) du
 * § 22 bis), les sièges du salon dans l'ordre de 50 (`joined_at`, § 8.1) ou
 * `[soi]` en solo, `self` avec `ownScore` à 0 sans appel à
 * `Scoreboard::seatScore()` (dont la signature exige une partie), aucune
 * manche, `maxAnswerLength` nul (écart (r)), classement vide
 * `{ scoreless: false, roundNumber: null, rows: [] }`.
 *
 * **Branche de partie** (manche portée, bornes des URL, `nextTransitionAt`,
 * `maxAnswerLength` du snapshot, `self.input`, classement, podium ; § 12.3 à
 * § 12.5) : lot L60-12, qui livre aussi les champs **hors manche** du § 12.2
 * — enveloppe avec `gameRef`, `mode` de la partie (canaux nuls en solo),
 * colonnes de `game`, sièges par {@see SeatViewPresenter::gameSeats()},
 * `pause` (`paused_at` + `EngineConstants::pauseTimeoutMs()`), `self.member`,
 * `self.participates`, `self.ownScore` par `Scoreboard::seatScore()`. D'ici
 * là, construire le paquet d'une partie lève — un paquet partiel décrirait un
 * état faux au lieu d'échouer.
 *
 * `self.seatActive` : jeton présenté = `player.active_seat_token`, comparé à
 * temps constant ({@see EnsureActiveSeat::holdsTab()}). À une requête JSON,
 * le jeton présenté est l'en-tête `X-Seat-Token` ; au rendu d'une page,
 * c'est le jeton **rendu par {@see ClaimSeatTab}**, appelée avant. Le jeton
 * lui-même ne figure jamais dans le paquet (§ 11.7).
 *
 * Règle 3 : aucun identifiant interne, aucun titre, aucun niveau d'image ;
 * les sièges passent par {@see SeatViewPresenter}, seule sérialisation d'un
 * siège, les canaux par {@see ChannelNames} (clé HMAC, jamais `room.id` ni
 * `room_code`).
 *
 * @phpstan-import-type SeatViewPayload from SeatViewPresenter
 * @phpstan-import-type LeaderboardPayload from Scoreboard
 * @phpstan-import-type PodiumPayload from Scoreboard
 *
 * @phpstan-type SelfStatePayload array{publicId: string, seatActive: bool, isHost: bool, member: bool, participates: bool, input: array<string, mixed>|null, ownScore: int}
 * @phpstan-type GameStatePacketPayload array{v: int, serverNow: string, gameRef: string|null, mode: 'multiplayer'|'solo', channels: array{room: string, seat: string}|null, status: string|null, roundsCount: int|null, roundsCompleted: int|null, framesPerRound: int|null, inputDifficulty: string|null, maxAnswerLength: int|null, seats: list<SeatViewPayload>, pause: array{pausedAt: string, interruptsAt: string}|null, round: array<string, mixed>|null, self: SelfStatePayload, leaderboard: LeaderboardPayload, podium: PodiumPayload|null, nextTransitionAt: string|null}
 */
final class GameStateBuilder
{
    /**
     * @param  Game|null  $game  la partie décrite (§ 12.2, {@see CurrentGame::forState()}),
     *                           nulle au lobby et pour un solo pas encore lancé
     * @param  string|null  $presentedSeatToken  jeton d'onglet présenté, ou rendu par {@see ClaimSeatTab}
     * @return GameStatePacketPayload
     *
     * @throws LogicException partie fournie (branche de partie : L60-12), ou
     *                        siège de salon sans salon.
     */
    public static function build(?Game $game, Player $seat, CarbonImmutable $now, ?string $presentedSeatToken): array
    {
        if ($game !== null) {
            throw new LogicException(
                'GameStateBuilder : la branche de partie du paquet (manche, images, saisie, classement, podium) est livrée par le lot L60-12 (spec 60 § 12.2 à § 12.5).',
            );
        }

        $room = $seat->room_id === null ? null : Room::query()->find($seat->room_id);

        if ($seat->room_id !== null && $room === null) {
            throw new LogicException('GameStateBuilder : le salon du siège est introuvable.');
        }

        $hostPlayerId = $room?->host_player_id;

        return [
            ...GameWire::envelope(null, $now),
            'mode' => $room === null ? GameMode::Solo->value : GameMode::Multiplayer->value,
            'channels' => $room === null ? null : [
                'room' => ChannelNames::room($room),
                'seat' => ChannelNames::seat($seat),
            ],
            'status' => null,
            'roundsCount' => null,
            'roundsCompleted' => null,
            'framesPerRound' => null,
            'inputDifficulty' => null,
            'maxAnswerLength' => null,
            'seats' => $room === null
                ? [SeatViewPresenter::lobby($seat, null)]
                : SeatViewPresenter::lobbySeats($room),
            'pause' => null,
            'round' => null,
            'self' => [
                'publicId' => $seat->public_id,
                'seatActive' => EnsureActiveSeat::holdsTab($seat, $presentedSeatToken),
                'isHost' => $hostPlayerId !== null && $hostPlayerId === $seat->id,
                'member' => false,
                'participates' => false,
                'input' => null,
                'ownScore' => 0,
            ],
            'leaderboard' => self::emptyLeaderboard(),
            'podium' => null,
            'nextTransitionAt' => null,
        ];
    }

    /**
     * Le classement sans partie (§ 12.2).
     *
     * @return LeaderboardPayload
     */
    private static function emptyLeaderboard(): array
    {
        return ['scoreless' => false, 'roundNumber' => null, 'rows' => []];
    }
}
