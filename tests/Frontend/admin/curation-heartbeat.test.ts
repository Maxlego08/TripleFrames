import { describe, expect, it } from 'vite-plus/test';
import {
    afterInput,
    HEARTBEAT_GATE_IDLE,
    onTick,
} from '@/lib/admin/heartbeat-gate';
import type { HeartbeatGate } from '@/lib/admin/heartbeat-gate';

/*
 * La porte du battement de débit (spec 20 § 10.1, lot L20-17) : un tick bat
 * si et seulement si la page est visible et qu'une saisie a eu lieu depuis le
 * tick précédent. Fonction pure, testée sans DOM (C18 § 2.4) : le hook ne
 * fait que la brancher sur le minuteur et les événements de saisie.
 */

/** Un événement de la chronologie d'une page, en secondes. */
type Event =
    | { at: number; kind: 'input' }
    | { at: number; kind: 'tick'; visible?: boolean };

/**
 * Rejoue une chronologie (saisies et ticks, dans l'ordre) et rend les
 * instants des ticks qui ont posté un battement.
 */
function beatsOf(events: Event[]): number[] {
    let gate: HeartbeatGate = HEARTBEAT_GATE_IDLE;
    const beats: number[] = [];

    for (const event of events) {
        if (event.kind === 'input') {
            gate = afterInput();

            continue;
        }

        const tick = onTick(gate, event.visible ?? true);

        gate = tick.gate;

        if (tick.beat) {
            beats.push(event.at);
        }
    }

    return beats;
}

/** Les ticks d'un minuteur de `cadence` secondes, de `from` à `to` compris. */
function ticks(from: number, to: number, cadence: number): Event[] {
    const events: Event[] = [];

    for (let at = from; at <= to; at += cadence) {
        events.push({ at, kind: 'tick' });
    }

    return events;
}

/** Fusionne des événements par instant ; à égalité, la saisie d'abord. */
function timeline(...groups: Event[][]): Event[] {
    return groups
        .flat()
        .sort(
            (a, b) =>
                a.at - b.at ||
                (a.kind === 'input' ? -1 : 0) - (b.kind === 'input' ? -1 : 0),
        );
}

describe('porte du battement de débit', () => {
    it("aucun battement n'est posté sur un tick sans saisie depuis le tick précédent", () => {
        // Aucune saisie : aucun tick ne bat, jamais.
        expect(beatsOf(ticks(15, 300, 15))).toEqual([]);

        // Une seule saisie : seul le tick qui la suit bat ; les suivants,
        // sans saisie nouvelle, se taisent.
        expect(
            beatsOf(timeline(ticks(15, 120, 15), [{ at: 20, kind: 'input' }])),
        ).toEqual([30]);

        // Plusieurs saisies entre deux ticks valent une seule : un battement.
        expect(
            beatsOf(
                timeline(ticks(15, 45, 15), [
                    { at: 16, kind: 'input' },
                    { at: 21, kind: 'input' },
                    { at: 29, kind: 'input' },
                ]),
            ),
        ).toEqual([30]);

        // L'exemple du § 10.1 : dernière saisie juste avant le tick de
        // t = 0, reprise à t = 100 — les ticks de 15 à 90 se taisent, et le
        // battement suivant part à t = 105. Côté serveur, l'écart de 105 s
        // dépasse la fenêtre de 60 s : la pause entière est exclue.
        expect(
            beatsOf(
                timeline(ticks(0, 120, 15), [
                    { at: -1, kind: 'input' },
                    { at: 100, kind: 'input' },
                ]),
            ),
        ).toEqual([0, 105]);

        // Un onglet en arrière-plan ne bat pas, même après une saisie ; et le
        // tick consomme la saisie : revenu au premier plan, l'onglet attend
        // une saisie nouvelle.
        expect(
            beatsOf([
                { at: 5, kind: 'input' },
                { at: 15, kind: 'tick', visible: false },
                { at: 30, kind: 'tick' },
                { at: 40, kind: 'input' },
                { at: 45, kind: 'tick' },
            ]),
        ).toEqual([45]);
    });

    it("chaque tick rend la porte à l'état de repos, qu'il batte ou non", () => {
        expect(onTick(afterInput(), true)).toEqual({
            beat: true,
            gate: HEARTBEAT_GATE_IDLE,
        });
        expect(onTick(afterInput(), false)).toEqual({
            beat: false,
            gate: HEARTBEAT_GATE_IDLE,
        });
        expect(onTick(HEARTBEAT_GATE_IDLE, true)).toEqual({
            beat: false,
            gate: HEARTBEAT_GATE_IDLE,
        });
    });
});
