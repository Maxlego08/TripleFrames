<?php

namespace App\Events\Game;

use App\Enums\GameMode;
use App\Models\Game;
use App\Models\Room;
use App\Support\Game\GameJournal;
use App\Support\Game\TransitionBroadcasts;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\GameWire;
use App\Support\Realtime\WirePayload;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * Base des événements DIFFUSÉS au salon, sur `presence-room.{roomKey}` —
 * spec 60 § 11.2 et § 11.6, contrat C7 § 2.3.
 *
 * Contenu identique pour tous les abonnés du salon : jamais rien de localisé,
 * jamais rien de propre à un siège (règle 3 ; l'envoi ciblé passe par
 * {@see SeatBroadcast}).
 *
 * **Transport.**
 * - `ShouldBroadcastNow` : émis par le processus de la transition, sans file.
 * - `ShouldDispatchAfterCommit` : émis dans la transaction qui l'a décidé, il
 *   ne part qu'après son commit, et jamais si elle est annulée.
 * - `ShouldRescue` (ajout de 60, écart (d) du § 22 bis) : une diffusion en
 *   échec (Reverb indisponible) est rapportée et avalée ; elle n'annule ni
 *   l'écriture de transition ni la programmation du job suivant, qu'un rappel
 *   après commit suivant exécute. Les clients se rattrapent par la
 *   resynchronisation (§ 12.6).
 *
 * **Charge précalculée sous le verrou** par l'émetteur, vérifiée champ par
 * champ contre la liste close {@see static::FIELDS} de l'événement
 * ({@see WirePayload::conform()}) ; l'enveloppe `{ v, serverNow, gameRef }`
 * est posée à l'émission, dans {@see self::broadcastWith()}, `serverNow` pris
 * à cet instant.
 *
 * **Garde de mode** (§ 11.2, contrat C7 § 4.12) : aucune instance pour une
 * partie `solo`. Tout émetteur teste d'abord `game.mode = multiplayer` ; ce
 * constructeur, qu'aucune sous-classe ne redéfinit, lève sinon — ce qu'il
 * refuse, c'est un défaut de l'émetteur, jamais un cas d'exécution.
 *
 * **Rattrapage et journal** (§ 4.4, § 4.7, lot L60-7). Pendant un passage de
 * rattrapage, la diffusion est confiée à {@see TransitionBroadcasts} par
 * {@see self::broadcastWhen()}, après le commit de sa transaction, et ne part
 * que si elle décrit encore l'état courant à la fin du passage. À
 * l'émission, {@see self::broadcastWith()} la signale au journal `game` :
 * une diffusion de frontière ({@see static::BOUNDARY}), dont l'émetteur a
 * posé l'instant théorique ({@see self::atBoundary()}), y journalise son
 * retard réel ; une diffusion en échec y est désignée
 * ({@see GameJournal::broadcastFailed()}).
 */
abstract class RoomBroadcast implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /**
     * Liste close des champs de la charge, hors enveloppe, et leur nature
     * ({@see WirePayload}) — 60 § 11.3.
     *
     * @var array<string, string>
     */
    public const array FIELDS = [];

    /**
     * Vrai pour un événement de partie : sans partie, il n'a pas de sens, et
     * son `gameRef` ne peut pas être nul. Faux pour un événement qui peut
     * partir au lobby, hors partie (`gameRef` nul).
     */
    public const bool GAME_BOUND = false;

    /**
     * Vrai pour les quatre diffusions de frontière (`round.scheduled`,
     * `tier.opened`, `round.closed`, `round.revealed`) : leur émetteur pose
     * l'instant théorique de l'étape ({@see self::atBoundary()}), et leur
     * retard réel part au journal `game` (§ 4.7) ; pendant un rattrapage,
     * seule la dernière d'une manche part (§ 4.4).
     */
    public const bool BOUNDARY = false;

    private readonly string $channel;

    private readonly ?Game $game;

    /** @var array<string, mixed> */
    private readonly array $payload;

    /** Instant théorique de l'étape émettrice, précalculé sous le verrou (§ 4.7). */
    private ?CarbonImmutable $theoreticalAt = null;

    /**
     * @param  array<array-key, mixed>  $payload  Charge hors enveloppe, précalculée sous le verrou.
     *
     * @throws LogicException partie solo, partie d'un autre salon, partie
     *                        absente d'un événement de partie, charge non
     *                        conforme.
     */
    final public function __construct(Room $room, ?Game $game, array $payload)
    {
        if ($game !== null && $game->mode !== GameMode::Multiplayer) {
            throw new LogicException(sprintf(
                '[%s] : aucune diffusion pour une partie solo (spec 60 § 11.2).',
                $this->broadcastAs(),
            ));
        }

        if ($game !== null && $game->room_id !== $room->id) {
            throw new LogicException(sprintf('[%s] : la partie n\'appartient pas à ce salon.', $this->broadcastAs()));
        }

        if ($game === null && static::GAME_BOUND) {
            throw new LogicException(sprintf('[%s] : événement de partie émis sans partie.', $this->broadcastAs()));
        }

        $this->channel = ChannelNames::room($room);
        $this->game = $game;
        $this->payload = WirePayload::conform($this->broadcastAs(), static::FIELDS, $payload);
    }

    /** Nom de l'événement sur le fil (`seat.joined`…), écouté côté client préfixé d'un point. */
    abstract public function broadcastAs(): string;

    final public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel($this->channel);
    }

    /**
     * Pose l'instant théorique de l'étape émettrice d'une diffusion de
     * frontière : `Tᵢ` pour `tier.opened`, l'instant de clôture écrit pour
     * `round.closed`, `ended_at + tier_grace_ms` pour `round.revealed`, et,
     * pour `round.scheduled`, celui de l'étape qui l'a décidé ou de la
     * transaction du geste (§ 4.7).
     *
     * @throws LogicException pour un événement qui n'est pas une frontière.
     */
    final public function atBoundary(CarbonImmutable $theoreticalAt): static
    {
        if (! static::BOUNDARY) {
            throw new LogicException(sprintf('[%s] : seule une diffusion de frontière porte un instant théorique.', $this->broadcastAs()));
        }

        $this->theoreticalAt = $theoreticalAt;

        return $this;
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
     * au journal `game` avec le retard réel d'une diffusion de frontière.
     *
     * @return array<string, mixed>
     */
    final public function broadcastWith(): array
    {
        $serverNow = Date::now()->toImmutable();
        $tierIndex = $this->payload['tierIndex'] ?? null;

        GameJournal::emitting(
            $this->broadcastAs(),
            $this->gameRef(),
            $this->sequenceIndex(),
            is_int($tierIndex) ? $tierIndex : null,
            $serverNow,
            $this->theoreticalAt,
            $this->game?->id,
        );

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

    /**
     * La manche que désigne la charge (`sequenceIndex`, ou celui de la
     * chronologie de `round.scheduled`) — nulle pour un événement sans manche.
     */
    final public function sequenceIndex(): ?int
    {
        $round = $this->payload['round'] ?? null;
        $sequenceIndex = $this->payload['sequenceIndex'] ?? (is_array($round) ? ($round['sequenceIndex'] ?? null) : null);

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
