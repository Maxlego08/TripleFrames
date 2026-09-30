<?php

namespace Tests\Support\Room;

use App\Http\Middleware\EnsureActiveSeat;
use App\Jobs\Game\BroadcastLobbyState;
use App\Models\Player;
use App\Models\Room;
use App\Settings\RoomSettings;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Écritures du lobby par HTTP, telles que le client les envoie (spec 50
 * § 8.2 et § 8.3 ; lot L50-2, second temps).
 *
 * Une requête du lobby est une visite Inertia : corps JSON, en-têtes
 * `X-Inertia` et `X-Requested-With`, `Accept` sans `application/json` — une
 * erreur de validation revient donc en redirection avec erreurs de session,
 * jamais en 422 —, cookie `player_token` et en-tête `X-Seat-Token` de
 * l'onglet actif (contrat C7 § 4.9).
 */
final class LobbyWrites
{
    /** URL d'où part l'écriture : la page du salon, retour de `back()`. */
    public static function lobbyUrl(Room $room): string
    {
        return '/r/'.$room->room_code;
    }

    /**
     * Un salon au lobby dont le siège hôte est tenu par ce jeton, un onglet
     * ayant déjà pris la main.
     *
     * @return array{0: Room, 1: Player}
     */
    public static function hostedRoom(PlayerToken $token, ?RoomSettings $settings = null): array
    {
        $room = Room::factory()->withSettings($settings ?? RoomSettings::defaults())->create();
        $host = self::seat($room, $token);

        Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

        return [$room->refresh(), $host];
    }

    /** Un siège du salon tenu par ce jeton, onglet actif frappé. */
    public static function seat(Room $room, PlayerToken $token): Player
    {
        return Player::factory()->create([
            'room_id' => $room->id,
            'player_token_hash' => $token->hash(),
            'active_seat_token' => (string) Str::ulid(),
        ]);
    }

    /**
     * Le jeton du joueur, posé en cookie comme le navigateur l'envoie, requêtes
     * JSON comprises.
     */
    public static function actAs(TestCase $test, PlayerToken $token): void
    {
        $test->withCredentials()
            ->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR));
    }

    /**
     * Une écriture du lobby, depuis la page du salon, présentant le jeton
     * d'onglet donné (le jeton actif du siège par défaut, aucun si `false`).
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public static function send(
        TestCase $test,
        string $method,
        string $uri,
        Room $room,
        array $body,
        Player|string|false $tab,
    ): TestResponse {
        $headers = [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ];

        if ($tab !== false) {
            $headers[EnsureActiveSeat::HEADER] = $tab instanceof Player ? (string) $tab->active_seat_token : $tab;
        }

        return $test->from(self::lobbyUrl($room))->json($method, $uri, $body, $headers);
    }

    /**
     * Dépile et exécute, comme un worker `--queue=game`, tous les jobs
     * disponibles de la file `game` de la connexion `database` ; rend leur
     * nombre.
     */
    public static function workGameQueue(): int
    {
        $processed = 0;

        while (($job = Queue::connection('database')->pop('game')) instanceof Job) {
            $job->fire();
            $job->delete();
            $processed++;
        }

        return $processed;
    }

    /**
     * Les diffusions de lobby en file, telles que la table `jobs` les porte :
     * file, instant de disponibilité, et le job désérialisé.
     *
     * @return list<array{queue: string, availableAt: int, job: BroadcastLobbyState}>
     */
    public static function queuedBroadcasts(): array
    {
        $queued = [];

        foreach (DB::table('jobs')->orderBy('id')->get() as $row) {
            $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
            $job = is_array($payload) ? unserialize((string) data_get($payload, 'data.command')) : null;

            if (! $job instanceof BroadcastLobbyState) {
                continue;
            }

            $queued[] = [
                'queue' => (string) $row->queue,
                'availableAt' => (int) $row->available_at,
                'job' => $job,
            ];
        }

        return $queued;
    }
}
