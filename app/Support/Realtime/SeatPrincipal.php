<?php

namespace App\Support\Realtime;

use App\Models\Player;
use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;

/**
 * Le principal de la garde `player` — spec 60 § 10.4, contrat C7 § 2.2.
 *
 * **Un invité n'est pas un compte.** Ce principal ne porte que le hash du
 * `player_token` courant, lu par `PlayerTokenManager::current()` (C4, R-30) —
 * jamais le cookie brut, jamais le `tid`. `Player` n'implémente pas
 * `Authenticatable` : un siège n'est pas une identité de connexion, et `web`
 * reste la garde par défaut ; Fortify ne voit jamais `player`.
 *
 * **Identifiant de diffusion = `public_id` du siège lié, jamais le hash.**
 * Pusher publie l'identifiant d'un membre de présence à tous les abonnés du
 * canal (`user_id`) : le hash du jeton y deviendrait un identifiant de siège
 * réutilisable, lisible par les autres joueurs. Tant qu'aucun canal n'a lié
 * de siège ({@see self::bindSeat()}), l'identifiant est nul.
 *
 * Méthodes de mot de passe et de souvenir neutres : aucune session, aucun
 * « se souvenir de moi » ne passe par ce principal.
 */
final class SeatPrincipal implements Authenticatable
{
    private ?Player $seat = null;

    public function __construct(#[\SensitiveParameter] public readonly string $tokenHash) {}

    /**
     * Lie le siège que la classe de canal vient d'autoriser : c'est lui que le
     * membre de présence désignera.
     *
     * @throws LogicException siège tenu par un autre jeton — la classe de canal
     *                        ne doit jamais lier un siège qu'elle n'a pas
     *                        résolu par ce hash.
     */
    public function bindSeat(Player $seat): void
    {
        if (! is_string($seat->player_token_hash) || ! hash_equals($this->tokenHash, $seat->player_token_hash)) {
            throw new LogicException('SeatPrincipal : le siège lié doit être tenu par le jeton courant.');
        }

        $this->seat = $seat;
    }

    public function boundSeat(): ?Player
    {
        return $this->seat;
    }

    public function getAuthIdentifierName(): string
    {
        return 'public_id';
    }

    public function getAuthIdentifier(): ?string
    {
        return $this->seat?->public_id;
    }

    /**
     * Lu par `PusherBroadcaster::validAuthenticationResponse()` pour le
     * `user_id` du membre de présence.
     */
    public function getAuthIdentifierForBroadcasting(): ?string
    {
        return $this->seat?->public_id;
    }

    public function getAuthPasswordName(): string
    {
        return '';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    /**
     * @param  string  $value
     */
    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * Ce qu'un `dump()` ou une trace montre : jamais le hash du jeton.
     *
     * @return array{tokenHash: string, seat: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'tokenHash' => '[redacted]',
            'seat' => $this->seat?->public_id,
        ];
    }
}
