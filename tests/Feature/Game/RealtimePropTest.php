<?php

use App\Models\User;
use App\Settings\EngineConstants;
use App\Support\I18n\TranslationDomains;
use App\Support\Realtime\RealtimeClientConfig;

/*
|--------------------------------------------------------------------------
| Prop partagée `realtime` — spec 60 § 10.5, contrat C7 § 2.6 (lot L60-4)
|--------------------------------------------------------------------------
|
| Ajout (aucun intitulé de la spec ne la prouve) : la configuration d'Echo
| voyage au RUNTIME, jamais figée au build (A-27, n° 79). Clé publique de
| l'application Reverb, hôte, port et schéma visés par le navigateur (nuls =
| `window.location`), cadence du battement et échantillons d'horloge lus
| dans `EngineConstants` — jamais un littéral côté client (règle 2).
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    config([
        'broadcasting.connections.reverb.key' => 'realtime-test-key',
        'broadcasting.connections.reverb.secret' => 'realtime-test-signing-value',
        'broadcasting.connections.reverb.app_id' => 'realtime-test-app',
    ]);
});

it('partage la configuration du client temps réel, lue au runtime et sans secret', function (): void {
    config(['broadcasting.connections.reverb.client' => ['host' => '', 'port' => '8080', 'scheme' => '']]);

    $curator = User::factory()->curator()->create();

    // Une page joueur et une page du back-office : identique pour tous.
    foreach (['accueil' => fn () => $this->get(route('home')), 'back-office' => fn () => $this->actingAs($curator)->get(route('admin.dashboard'))] as $label => $visit) {
        app()->forgetInstance(TranslationDomains::class);

        $response = $visit()->assertOk();

        expect($response->inertiaProps('realtime'))->toBe([
            'key' => 'realtime-test-key',
            'host' => null,
            'port' => 8080,
            'scheme' => null,
            'heartbeatIntervalMs' => EngineConstants::heartbeatIntervalMs(),
            'clockSamples' => EngineConstants::clockSamples(),
        ], $label);

        // Ni le secret, ni l'identifiant d'application.
        expect((string) $response->getContent())->not->toContain('realtime-test-signing-value')
            ->not->toContain('realtime-test-app');
    }

    // Les constantes du moteur suivent leur configuration, sans littéral.
    engineConstantsConfigure([
        'heartbeat_interval_ms' => EngineConstants::DEFAULT_HEARTBEAT_INTERVAL_MS * 2,
        'disconnect_after_ms' => EngineConstants::DEFAULT_HEARTBEAT_INTERVAL_MS * 5,
        'clock_samples' => EngineConstants::DEFAULT_CLOCK_SAMPLES + 2,
    ]);

    expect(RealtimeClientConfig::toArray())
        ->toMatchArray([
            'heartbeatIntervalMs' => EngineConstants::DEFAULT_HEARTBEAT_INTERVAL_MS * 2,
            'clockSamples' => EngineConstants::DEFAULT_CLOCK_SAMPLES + 2,
        ]);

    // Valeurs posées, puis valeurs illisibles : vides ou hors domaine, elles
    // valent « prendre window.location ».
    $cases = [
        [['host' => ' 127.0.0.1 ', 'port' => 443, 'scheme' => 'HTTPS'], ['127.0.0.1', 443, 'https']],
        [['host' => null, 'port' => null, 'scheme' => null], [null, null, null]],
        [['host' => '  ', 'port' => '70000', 'scheme' => 'ftp'], [null, null, null]],
        [['host' => '', 'port' => 'abc', 'scheme' => 'http'], [null, null, 'http']],
        [['host' => '', 'port' => '0', 'scheme' => ''], [null, null, null]],
    ];

    foreach ($cases as [$client, [$host, $port, $scheme]]) {
        config(['broadcasting.connections.reverb.client' => $client]);

        expect(RealtimeClientConfig::toArray())->toMatchArray(['host' => $host, 'port' => $port, 'scheme' => $scheme]);
    }
});
