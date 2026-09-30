<?php

namespace Tests\Support\Realtime;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;

/**
 * Diffuseur de test (spec 60 § 20, lot L60-3) : enregistre ce qui partirait
 * vers Reverb, TEL QUE le fil le porte — canaux au nom Pusher, nom
 * `broadcastAs`, charge après `broadcastWith()` et aller-retour JSON, `socket`
 * retiré comme le fait `PusherBroadcaster`.
 *
 * Contrairement à `Event::fake()`, qui intercepte l'événement avant le
 * diffuseur, il laisse jouer toute la chaîne du framework :
 * `ShouldDispatchAfterCommit`, `ShouldBroadcastNow`, `ShouldRescue`,
 * `broadcastOn()` et `broadcastWith()`. En mode {@see self::$failing}, il lève
 * la `BroadcastException` d'un Reverb indisponible.
 */
final class RecordingBroadcaster extends Broadcaster
{
    /** Nom du pilote et de la connexion installés pour le test. */
    public const string DRIVER = 'recording';

    /**
     * Diffusions reçues, dans l'ordre.
     *
     * @var list<array{channels: list<string>, event: string, payload: array<string, mixed>, json: string}>
     */
    public array $sent = [];

    /** Tentatives de diffusion, réussies ou non. */
    public int $attempts = 0;

    /** Vrai : chaque diffusion lève, comme un Reverb indisponible. */
    public bool $failing = false;

    /** Installe un enregistreur neuf comme connexion de diffusion par défaut. */
    public static function install(): self
    {
        $recorder = new self;

        // Ni `static` ni `self` : `extend()` relie la fermeture au gestionnaire.
        Broadcast::extend(self::DRIVER, fn (): RecordingBroadcaster => $recorder);
        Config::set('broadcasting.connections.'.self::DRIVER, ['driver' => self::DRIVER]);
        Config::set('broadcasting.default', self::DRIVER);
        Broadcast::purge(self::DRIVER);

        return $recorder;
    }

    /**
     * @param  Request  $request
     */
    public function auth($request): mixed
    {
        return null;
    }

    /**
     * @param  Request  $request
     */
    public function validAuthenticationResponse($request, $result): mixed
    {
        return null;
    }

    /**
     * @param  array<int, mixed>  $channels
     * @param  string  $event
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $this->attempts++;

        if ($this->failing) {
            throw new BroadcastException('Diffusion simulée en échec : Reverb indisponible.');
        }

        Arr::pull($payload, 'socket');

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $wire */
        $wire = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->sent[] = [
            'channels' => array_values($this->formatChannels($channels)),
            'event' => $event,
            'payload' => $wire,
            'json' => $json,
        ];
    }
}
