<?php

namespace App\Http\Middleware;

use App\Actions\Game\ClaimSeatTab;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Support\Game\CurrentGame;
use App\Support\Game\SoloSeat;
use App\Support\Identity\PlayerTokenManager;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `seat.active` — le siège actif d'une écriture de jeu (spec 60 § 10.2 et
 * § 12.7, contrat C7 § 2.4 ; contrat C10 § 2, 70 § 7.1 et § 8).
 *
 * **Un `player_token` = un siège ; un siège = un onglet qui écrit.** Le
 * middleware :
 *
 * 1. résout le siège **exclusivement** par le hash du jeton courant (contrat
 *    C4 I4.9), expulsé toujours exclu : siège lié `{player}` (le `public_id`,
 *    tenu par ce jeton), sinon siège du salon `{room}` (le `room_code` du
 *    créneau actif, par `PlayerTokenManager::seatIn()`), sinon siège solo
 *    ({@see SoloSeat::heldBy()} : `room_id IS NULL`, `left_at IS NULL`,
 *    `player_token_idx`). Un `public_id` ne donne **aucun droit** : il ne
 *    fait que désigner un siège que le jeton doit tenir. Sans siège :
 *    **403** ;
 * 2. compare l'en-tête `X-Seat-Token` à `player.active_seat_token`, le jeton
 *    d'onglet frappé par {@see ClaimSeatTab} : en cas d'écart — jeton
 *    supplanté, en-tête absent, ou aucun onglet n'a encore pris la main —,
 *    **409 `{ "code": "seat_superseded" }`** (`game.errors.seat_superseded`),
 *    et rien n'est écrit. Reprendre la main, c'est recharger la page ;
 * 3. met en mémoire sur la requête le siège résolu **et sa partie courante**
 *    au sens du contrat C17 ({@see CurrentGame::of()}), ou NULL, que lisent
 *    le limiteur `answer` et les FormRequest de 70 par {@see self::seat()} et
 *    {@see self::game()}.
 *
 * **Rang dans la pile** (70 § 8, `bootstrap/app.php`) : inscrit avant
 * `ThrottleRequests` dans la liste de priorité, derrière `SetLocale` — pile
 * réelle `SetLocale`, `EnsureActiveSeat`, `ThrottleRequests`,
 * `SubstituteBindings`. Il s'exécute donc **avant la liaison implicite** et
 * lit `{room}` et `{player}` comme **paramètres bruts** (chaînes), jamais
 * comme modèles liés ; un paramètre déjà lié trahit une pile mal ordonnée et
 * lève.
 */
final class EnsureActiveSeat
{
    /** En-tête qui porte le jeton d'onglet, sur toute requête de l'onglet (§ 12.7). */
    public const string HEADER = 'X-Seat-Token';

    /** Code du refus d'un onglet supplanté, rendu par `game.errors.seat_superseded`. */
    public const string SUPERSEDED = 'seat_superseded';

    /** Attribut de requête : le siège résolu. */
    public const string SEAT_ATTRIBUTE = 'tripleframes.active_seat';

    /** Attribut de requête : la partie courante du siège (C17), ou NULL. */
    public const string GAME_ATTRIBUTE = 'tripleframes.current_game';

    public function __construct(private readonly PlayerTokenManager $tokens) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $seat = $this->resolveSeat($request);

        abort_if($seat === null, Response::HTTP_FORBIDDEN);

        if (! self::holdsTab($seat, self::presentedToken($request))) {
            return response()->json(['code' => self::SUPERSEDED], Response::HTTP_CONFLICT);
        }

        $request->attributes->set(self::SEAT_ATTRIBUTE, $seat);
        $request->attributes->set(self::GAME_ATTRIBUTE, CurrentGame::of($seat));

        return $next($request);
    }

    /**
     * Le siège résolu par `seat.active`, ou NULL hors de sa pile.
     */
    public static function seat(Request $request): ?Player
    {
        $seat = $request->attributes->get(self::SEAT_ATTRIBUTE);

        return $seat instanceof Player ? $seat : null;
    }

    /**
     * La partie courante du siège résolu (C17), ou NULL : sans partie en
     * cours, ou hors de la pile de `seat.active`.
     */
    public static function game(Request $request): ?Game
    {
        $game = $request->attributes->get(self::GAME_ATTRIBUTE);

        return $game instanceof Game ? $game : null;
    }

    /**
     * Le jeton d'onglet présenté par la requête (`X-Seat-Token`), ou NULL.
     * Lu aussi par les contrôleurs de page, qui le passent à
     * {@see ClaimSeatTab} (§ 12.7).
     */
    public static function presentedToken(Request $request): ?string
    {
        $token = $request->headers->get(self::HEADER);

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * Vrai si le jeton présenté est le jeton d'onglet actif du siège.
     * Comparaison à temps constant ; un siège dont aucun onglet n'a encore
     * pris la main n'a pas de jeton actif, et aucune requête ne le tient.
     */
    public static function holdsTab(Player $seat, ?string $presented): bool
    {
        $active = $seat->active_seat_token;

        return $active !== null && $presented !== null && hash_equals($active, $presented);
    }

    /**
     * Le siège du jeton courant, désigné par la route : `{player}`, sinon
     * `{room}`, sinon le siège solo. Expulsé toujours exclu.
     */
    private function resolveSeat(Request $request): ?Player
    {
        $token = $this->tokens->current($request);

        if ($token === null) {
            return null;
        }

        $route = $request->route();
        $publicId = $route instanceof Route ? self::rawParameter($route, 'player') : null;
        $roomCode = $route instanceof Route ? self::rawParameter($route, 'room') : null;
        $room = null;

        if ($roomCode !== null) {
            $room = Room::query()->where('room_code_active', Room::normalizeCode($roomCode))->first();

            if ($room === null) {
                return null;
            }
        }

        if ($publicId !== null) {
            $seat = Player::query()
                ->where('public_id', $publicId)
                ->heldByToken($token)
                ->whereNull('kicked_at')
                // Un siège de salon n'écrit plus dans un salon archivé.
                ->where(fn (Builder $query) => $query
                    ->whereNull('room_id')
                    ->orWhereHas('room', fn (Builder $room) => $room->whereNull('archived_at')))
                ->first();

            return $seat !== null && ($room === null || $seat->room_id === $room->id) ? $seat : null;
        }

        if ($room !== null) {
            return $this->tokens->seatIn($request, $room);
        }

        // Le siège solo, par la définition unique du démarrage solo : au plus
        // un par jeton (`player_solo_token_uq`, E10-N3) ; l'ordre ne fait que
        // rendre la lecture déterministe.
        return SoloSeat::heldBy($token)->orderByDesc('id')->first();
    }

    /**
     * Un paramètre de route BRUT, ou NULL s'il est absent.
     *
     * @throws LogicException paramètre déjà lié : `seat.active` a tourné
     *                        après `SubstituteBindings` (70 § 8).
     */
    private static function rawParameter(Route $route, string $name): ?string
    {
        if (! $route->hasParameter($name)) {
            return null;
        }

        $value = $route->parameter($name);

        if (! is_string($value)) {
            throw new LogicException(sprintf(
                'seat.active lit {%s} comme paramètre brut : il doit s\'exécuter avant la liaison implicite (spec 60 § 10.2, 70 § 8).',
                $name,
            ));
        }

        return $value;
    }
}
