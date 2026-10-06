<?php

namespace Tests\Support\Room;

use App\Avatars\AvatarPresetCatalog;
use App\Models\Player;
use App\Models\Room;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestes de siège par HTTP — création et entrée (spec 50 § 6 et § 7 ; lot
 * L50-3b) — et lecture du `player_token` qu'ils posent.
 */
final class SeatEntry
{
    /** Un pseudo que la règle accepte, accent compris (forme repliée `zoe`). */
    public const string NICKNAME = 'Zoé';

    /**
     * En production, un processus PHP-FPM sert une requête puis meurt : la
     * file du `CookieJar` — singleton que le framework ne vide jamais — ne
     * survit pas à sa requête. Ici, toutes les requêtes d'un test partagent
     * l'application : sans ce vidage, le `Set-Cookie` d'un geste repartirait
     * sur la requête suivante et ferait mentir chaque assertion d'absence.
     */
    public static function isolateCookies(): void
    {
        Event::listen(RequestHandled::class, static fn () => Cookie::flushQueuedCookies());
    }

    /**
     * Le corps d'un formulaire de siège : le pseudo seul — l'avatar est
     * attribué par le serveur (D55 du 02/10).
     *
     * @return array{nickname: string}
     */
    public static function form(string $nickname = self::NICKNAME): array
    {
        return ['nickname' => $nickname];
    }

    /** La n-ième clé du catalogue des avatars prédéfinis (1 = la première). */
    public static function avatar(int $position): string
    {
        return AvatarPresetCatalog::keys()[$position - 1];
    }

    /**
     * Les `Set-Cookie` `player_token` d'une réponse.
     *
     * @param  TestResponse<Response>  $response
     * @return list<SymfonyCookie>
     */
    public static function tokenCookies(TestResponse $response): array
    {
        return array_values(array_filter(
            $response->headers->getCookies(),
            static fn (SymfonyCookie $cookie): bool => $cookie->getName() === PlayerTokenCookie::NAME,
        ));
    }

    /**
     * Le jeton que pose la réponse, déchiffré — exactement un `Set-Cookie`.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function tokenFrom(TestResponse $response): PlayerToken
    {
        expect(self::tokenCookies($response))->toHaveCount(1);

        $cookie = $response->getCookie(PlayerTokenCookie::NAME);
        $claims = json_decode((string) $cookie?->getValue(), true, 4, JSON_THROW_ON_ERROR);
        $token = is_array($claims) ? PlayerToken::fromClaims($claims) : null;

        expect($token)->toBeInstanceOf(PlayerToken::class);

        return $token ?? throw new LogicException('player_token illisible.');
    }

    /**
     * Les revendications du jeton que pose la réponse.
     *
     * @param  TestResponse<Response>  $response
     * @return array{v: int, tid: string, locale: string|null, avatar: string|null}
     */
    public static function claims(TestResponse $response): array
    {
        return self::tokenFrom($response)->toClaims();
    }

    /**
     * Les lignes `player` d'un salon, colonnes brutes, par `id` : l'état
     * exact, à l'octet, pour prouver qu'un geste n'a rien écrit.
     *
     * @return list<array<string, mixed>>
     */
    public static function rawSeats(Room $room): array
    {
        return DB::table('player')
            ->where('room_id', $room->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /** Le siège de ce jeton dans ce salon, expulsé compris, ou `null`. */
    public static function seatOf(Room $room, PlayerToken $token): ?Player
    {
        return Player::query()->whereBelongsTo($room)->heldByToken($token)->first();
    }
}
