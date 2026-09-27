<?php

namespace Tests\Support\Room;

use App\Enums\GamePlayerStatus;
use App\Enums\Locale;
use App\Enums\RoomStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Settings\RoomSettings;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Game\EngineFixtures;
use Tests\TestCase;

/**
 * Gestes de l'hôte et départ, par HTTP ou par les actions (spec 50 § 11 ;
 * lot L50-6) : salons à sièges tenus par des jetons, envois tels que le
 * client du lobby les fait (`LobbyWrites`), et partie en cours matérialisée
 * par les actions réelles du moteur (`EngineFixtures`).
 */
final class HostGestures
{
    /**
     * Un salon au lobby, aux réglages donnés (défauts du site sinon), dont
     * l'hôte est tenu par un jeton neuf, onglet actif frappé.
     *
     * @return array{0: Room, 1: Player, 2: PlayerToken}
     */
    public static function room(?RoomSettings $settings = null, Locale $locale = Locale::French): array
    {
        $token = PlayerToken::mint($locale);
        [$room, $host] = LobbyWrites::hostedRoom($token, $settings);
        $host->forceFill(['joined_at' => Date::now()->subMinutes(30)])->save();

        return [$room->refresh(), $host->refresh(), $token];
    }

    /**
     * Un siège du salon tenu par un jeton neuf, onglet actif frappé, entré
     * `$minutesAgo` minutes plus tôt (l'ancienneté départage le transfert).
     *
     * @param  array<string, mixed>  $attributes  Colonnes imposées (état de connexion…).
     * @return array{0: Player, 1: PlayerToken}
     */
    public static function seat(Room $room, int $minutesAgo = 10, array $attributes = []): array
    {
        $token = PlayerToken::mint(Locale::French);
        $seat = Player::factory()->create([
            'room_id' => $room->id,
            'player_token_hash' => $token->hash(),
            'active_seat_token' => (string) Str::ulid(),
            'joined_at' => Date::now()->subMinutes($minutesAgo),
            ...$attributes,
        ]);

        return [$seat, $token];
    }

    /**
     * L'expulsion par la route, depuis la page du salon, au nom du jeton
     * donné et de l'onglet actif de son siège.
     *
     * @return TestResponse<Response>
     */
    public static function kick(TestCase $test, Room $room, PlayerToken $token, Player $requester, string $targetPublicId): TestResponse
    {
        LobbyWrites::actAs($test, $token);

        return LobbyWrites::send(
            $test,
            'POST',
            route('room.players.kick', ['room' => $room, 'target' => $targetPublicId]),
            $room,
            [],
            $requester,
        );
    }

    /**
     * Le transfert manuel par la route.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public static function transfer(TestCase $test, Room $room, PlayerToken $token, Player $requester, array $body): TestResponse
    {
        LobbyWrites::actAs($test, $token);

        return LobbyWrites::send($test, 'POST', route('room.host.transfer', $room), $room, $body, $requester);
    }

    /**
     * Le départ par la route.
     *
     * @return TestResponse<Response>
     */
    public static function leave(TestCase $test, Room $room, PlayerToken $token, Player $seat): TestResponse
    {
        LobbyWrites::actAs($test, $token);

        return LobbyWrites::send($test, 'POST', route('room.leave', $room), $room, [], $seat);
    }

    /**
     * Une partie en cours dans ce salon, sur ces sièges (participations
     * gelées depuis eux), matérialisée par l'action réelle, sa manche 1
     * programmée puis ouverte à son palier 1 : les lignes `round_player`
     * naissent à `T₁`, comme en production. Le salon passe en partie.
     *
     * Exige le disque `frames` simulé (`PoolFixtures::fakeFramesDisk()`).
     *
     * @param  list<Player>  $seats
     * @return array{0: Game, 1: Round}
     */
    public static function runningGame(Room $room, array $seats): array
    {
        $game = EngineFixtures::game(EngineFixtures::settings(), room: $room);

        foreach ($seats as $seat) {
            GamePlayer::factory()->for($game)->frozenFrom($seat)->create(['status' => GamePlayerStatus::Playing]);
        }

        Room::query()->whereKey($room->id)->update([
            'status' => RoomStatus::Playing->value,
            'launched_at' => Date::now(),
        ]);

        EngineFixtures::materialize($game);
        $round = EngineFixtures::round($game, 1);
        $now = Date::now()->toImmutable();

        EngineFixtures::schedule($round, $now->addSeconds(5), $now);
        EngineFixtures::openTier($round, 1);

        return [$game->refresh(), $round->refresh()];
    }

    /**
     * La ligne brute d'une table, par identifiant, sans cast : l'état exact,
     * à l'octet, pour prouver qu'un geste n'a rien écrit.
     *
     * @return array<string, mixed>
     */
    public static function raw(string $table, int $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    /** Un instant rendu à la milliseconde, pour comparer deux colonnes `timestamp(3)`. */
    public static function ms(?CarbonImmutable $instant): ?string
    {
        return $instant?->format('Y-m-d H:i:s.v');
    }
}
