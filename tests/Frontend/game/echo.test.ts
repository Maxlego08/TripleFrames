import { describe, expect, it } from 'vite-plus/test';
import {
    connectionStateOf,
    echoOptions,
    realtimeStatusOf,
    ROOM_EVENTS,
    SEAT_EVENTS,
} from '@/lib/game/echo';
import type { PageLocation } from '@/lib/game/echo';
import type { RealtimeConfig } from '@/types/game-wire';

/*
 * Client Echo des pages de jeu (spec 60 § 10.5, A-27) : configuré à
 * l'exécution depuis la prop `realtime` et l'emplacement de la page, jamais
 * depuis une variable figée au build. Ajout au lot L60-9 (aucun intitulé de
 * la spec) : parties pures seulement, aucune connexion n'est ouverte.
 */

const CONFIG: RealtimeConfig = {
    key: 'cle-publique',
    host: null,
    port: null,
    scheme: null,
    heartbeatIntervalMs: 10_000,
    clockSamples: 3,
};

const HTTPS_PAGE: PageLocation = {
    hostname: 'jeu.example.test',
    port: '',
    protocol: 'https:',
};

describe('echo', () => {
    it("se configure à l'exécution depuis la prop realtime et l'emplacement de la page", () => {
        // Hôte, port et schéma nuls : ceux de la page, TLS en `https`, ports
        // implicites laissés aux défauts de `pusher-js`.
        expect(echoOptions(CONFIG, HTTPS_PAGE)).toEqual({
            broadcaster: 'reverb',
            key: 'cle-publique',
            wsHost: 'jeu.example.test',
            forceTLS: true,
            enabledTransports: ['ws', 'wss'],
            enableStats: false,
            withoutInterceptors: true,
        });

        // Valeurs de la prop : elles priment sur la page.
        expect(
            echoOptions(
                { ...CONFIG, host: 'localhost', port: 8080, scheme: 'http' },
                HTTPS_PAGE,
            ),
        ).toMatchObject({
            wsHost: 'localhost',
            wsPort: 8080,
            wssPort: 8080,
            forceTLS: false,
        });

        // Port explicite de la page.
        expect(
            echoOptions(CONFIG, {
                hostname: '127.0.0.1',
                port: '8000',
                protocol: 'http:',
            }),
        ).toMatchObject({ wsHost: '127.0.0.1', wsPort: 8000, forceTLS: false });

        // Aucune clé : aucune connexion tentée.
        expect(echoOptions({ ...CONFIG, key: '' }, HTTPS_PAGE)).toBeNull();
    });

    it('sépare les seize événements du salon des trois du siège', () => {
        expect(ROOM_EVENTS).toHaveLength(16);
        expect(SEAT_EVENTS).toEqual([
            'seat.choices',
            'seat.superseded',
            'seat.kicked',
        ]);
        expect(
            ROOM_EVENTS.filter((name) =>
                (SEAT_EVENTS as readonly string[]).includes(name),
            ),
        ).toEqual([]);
    });

    it('ne montre le bandeau que sur une coupure, jamais pendant la première ouverture', () => {
        expect(realtimeStatusOf('connecting', false)).toBe('connecting');
        expect(realtimeStatusOf('connecting', true)).toBe('reconnecting');
        expect(realtimeStatusOf('failed', false)).toBe('unavailable');

        expect(connectionStateOf('connecting', true)).toBe('connected');
        expect(connectionStateOf('idle', true)).toBe('connected');
        expect(connectionStateOf('reconnecting', true)).toBe('reconnecting');
        expect(connectionStateOf('unavailable', true)).toBe('reconnecting');
        expect(connectionStateOf('connected', false)).toBe('offline');
    });
});
