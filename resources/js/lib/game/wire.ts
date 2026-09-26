import type { IsoMs } from '@/types/game-wire';

/**
 * Version du fil de jeu (spec 60 § 11.1), miroir de
 * `App\Support\Realtime\GameWire::VERSION` : `WireVersionTest` refuse tout
 * écart. Toute rupture de forme d'une charge l'incrémente des deux côtés.
 */
export const GAME_WIRE_VERSION = 1;

/** La seule forme admise d'un `IsoMs` : UTC, à la milliseconde. */
const ISO_MS_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/;

/**
 * Un instant du fil en millisecondes depuis l'époque Unix (UTC), l'unité de
 * `serverNow()` et de la chronologie cliente.
 *
 * Stricte : toute chaîne qui n'est pas un `IsoMs` bien formé lève une
 * `RangeError`, au lieu de rendre un `NaN` qui fausserait en silence un
 * décalage ou un compte à rebours. Un instant mal formé est un défaut du
 * serveur, jamais un cas d'exécution à tolérer.
 */
export function parseIsoMs(value: IsoMs): number {
    const epochMs = ISO_MS_PATTERN.test(value) ? Date.parse(value) : Number.NaN;

    if (!Number.isFinite(epochMs)) {
        throw new RangeError(`parseIsoMs: ${JSON.stringify(value)}`);
    }

    return epochMs;
}
