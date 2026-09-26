<?php

namespace App\Events\Game;

use App\Broadcasting\SeatPrivateChannel;
use App\Enums\GameMode;
use App\Models\Game;
use App\Models\Player;
use App\Support\Game\GameJournal;
use App\Support\Game\TransitionBroadcasts;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\GameWire;
use App\Support\Realtime\WirePayload;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * Base des événements CIBLÉS à un siège, sur `private-seat.{publicId}` —
 * spec 60 § 11.2 et § 11.6, contrat C7 § 2.3.
 *
 * Seul chemin d'un contenu propre à un siège ou localisé (règle 3 : envoi
 * ciblé, jamais une diffusion salon identique) : `seat.choices`,
 * `seat.superseded`, `seat.kicked`. Le canal n'admet que le jeton qui tient
 * ce siège ({@see SeatPrivateChannel}).
 *
 * Même transport que {@see RoomBroadcast} : `ShouldBroadcastNow`,
 * `ShouldDispatchAfterCommit`, `ShouldRescue` (écart (d) du § 22 bis) ;
 * charge vérifiée champ par champ à la construction, enveloppe posée à
 * l'émission.
 *
 * **Garde de mode** (§ 11.2, contrat C7 § 4.12) : un siège sans salon — un
 * siège solo — n'a pas de canal et lève une `LogicException` ; une partie
 * `solo` aussi. Tout émetteur teste d'abord `game.mode = multiplayer` ou,
 * hors partie, `seat.room_id` non nul : sans ce test, recharger `game/solo`
 * lèverait ici à la prise d'onglet (§ 12.7).
 *
 * **Rattrapage et journal** (§ 4.4, § 4.7, lot L60-7), comme
 * {@see RoomBroadcast} : retenue pendant un passage de rattrapage
 * ({@see TransitionBroadcasts}) — `seat.choices` ne part que si le palier du
 * QCM est encore courant à la fin du passage —, et signalée au journal
 * `game` à l'émission, pour qu'une diffusion en échec y soit désignée.
 */
abstract class SeatBroadcast implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /**
     * Liste close des champs de la charge, hors enveloppe, et leur nature
     * ({@see WirePayload}) — 60 § 11.3.
     *
     * @var array<string, string>
     */
    public const array FIELDS = [];

    /** Vrai pour un événement de partie ({@see RoomBroadcast::GAME_BOUND}). */
    public const bool GAME_BOUND = false;

    private readonly string $channel;

    private readonly ?Game $game;

    /** @var array<string, mixed> */
    private readonly array $payload;

    /**
     * @param  array<array-key, mixed>  $payload  Charge hors enveloppe, précalculée sous le verrou.
     *
     * @throws LogicException siège sans salon, partie solo ou d'un autre salon,
     *                        partie absente d'un événement de partie, charge
     *                        non conforme.
     */
    final public function __construct(Player $seat, ?Game $game, array $payload)
    {
        if ($seat->room_id === null) {
            throw new LogicException(sprintf(
                '[%s] : un siège sans salon n\'a pas de canal (spec 60 § 11.2, 10 § 7.10).',
                $this->broadcastAs(),
            ));
        }

        if ($game !== null && $game->mode !== GameMode::Multiplayer) {
            throw new LogicException(sprintf(
                '[%s] : aucune diffusion pour une partie solo (spec 60 § 11.2).',
                $this->broadcastAs(),
            ));
        }

        if ($game !== null && $game->room_id !== $seat->room_id) {
            throw new LogicException(sprintf('[%s] : la partie n\'appartient pas au salon du siège.', $this->broadcastAs()));
        }

        if ($game === null && static::GAME_BOUND) {
            throw new LogicException(sprintf('[%s] : événement de partie émis sans partie.', $this->broadcastAs()));
        }

        $this->channel = ChannelNames::seat($seat);
        $this->game = $game;
        $this->payload = WirePayload::conform($this->broadcastAs(), static::FIELDS, $payload);
    }

    /** Nom de l'événement sur le fil (`seat.choices`…), écouté côté client préfixé d'un point. */
    abstract public function broadcastAs(): string;

    final public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    /**
     * Faux tant qu'un passage de rattrapage retient la diffusion (§ 4.4) :
     * évalué par le dispatcher APRÈS le commit de la transaction émettrice.
     */
    final public function broadcastWhen(): bool
    {
        return ! app(TransitionBroadcasts::class)->holds($this);
    }

    /**
     * L'enveloppe, puis la charge — `serverNow` pris à l'émission, et signalé
     * au journal `game` (aucune diffusion ciblée n'est une frontière).
     *
     * @return array<string, mixed>
     */
    final public function broadcastWith(): array
    {
        $serverNow = Date::now()->toImmutable();

        GameJournal::emitting($this->broadcastAs(), $this->gameRef(), $this->sequenceIndex(), null, $serverNow, null);

        return [
            ...GameWire::envelope($this->game, $serverNow),
            ...$this->payload,
        ];
    }

    /** La référence publique de la partie, nulle hors partie. */
    final public function gameRef(): ?string
    {
        return $this->game === null ? null : GameRef::for($this->game);
    }

    /** La manche que désigne la charge — nulle pour un événement sans manche. */
    final public function sequenceIndex(): ?int
    {
        $sequenceIndex = $this->payload['sequenceIndex'] ?? null;

        return is_int($sequenceIndex) ? $sequenceIndex : null;
    }

    /**
     * La charge précalculée, hors enveloppe, dans l'ordre de {@see static::FIELDS}.
     *
     * @return array<string, mixed>
     */
    final public function payload(): array
    {
        return $this->payload;
    }
}
