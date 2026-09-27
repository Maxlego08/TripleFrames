<?php

namespace App\Support\Game;

use App\Actions\Game\StartSoloGame;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Player;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Le siège solo tenu par un `player_token` — spec 60 § 16.2 et § 16.4, 10
 * § 7.1 (« Portée exacte de l'invariant un jeton = un siège »), contrat C4
 * I4.9.
 *
 * **Une seule définition** du prédicat, lue par tous ses consommateurs : le
 * démarrage ({@see StartSoloGame}, qui prend le siège `FOR UPDATE`), la
 * validation de `solo.store` (pseudo et avatar exigés d'un jeton sans siège
 * solo), les pages `solo.create` et `solo.show`, et `seat.active`
 * ({@see EnsureActiveSeat}) :
 *
 * `player_token_hash = hash(tid)` ET `room_id IS NULL` ET `left_at IS NULL`
 * ET `kicked_at IS NULL`, servi par `player_token_idx (player_token_hash,
 * room_id)`. Un siège solo ne passe jamais `left` (§ 13.3) et n'est jamais
 * expulsé : les deux dernières clauses sont défensives. La reprise lit
 * `player_token_hash`, jamais le créneau `solo_token_hash`, qui ne sert qu'à
 * l'unicité (`player_solo_token_uq`, E10-N3) : un siège solo dont l'échéance
 * d'effacement a vidé les deux hash n'est plus tenu par personne.
 *
 * Identification par le seul hash du jeton courant : ni IP, ni session, ni
 * `public_id` fourni par le client.
 */
final readonly class SoloSeat
{
    public function __construct(private PlayerTokenManager $tokens) {}

    /**
     * Les sièges solo tenus par ce jeton — au plus un, par
     * `player_solo_token_uq`, pour tout siège écrit par le démarrage solo.
     *
     * @return Builder<Player>
     */
    public static function heldBy(PlayerToken $token): Builder
    {
        return Player::query()
            ->whereNull('room_id')
            ->heldByToken($token)
            ->whereNull('left_at')
            ->whereNull('kicked_at');
    }

    /**
     * Le siège solo du jeton de la requête, ou `null` — sans jeton, ou sans
     * siège solo. Ne frappe jamais de jeton (C4 I4.1) : lecture seule.
     */
    public function of(Request $request): ?Player
    {
        $token = $this->tokens->current($request);

        return $token === null ? null : self::heldBy($token)->orderByDesc('id')->first();
    }
}
