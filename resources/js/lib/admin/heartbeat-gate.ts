/**
 * La porte du battement de débit (spec 20 § 10.1, décision 10) : décide, à
 * chaque tick, si l'écran d'un film poste un battement. **Fonction pure**,
 * testée sans DOM (C18 § 2.4) ; le hook `use-curation-heartbeat.ts` ne fait
 * que la brancher sur le minuteur, la visibilité de la page et les saisies.
 *
 * La règle : un tick bat **si et seulement si** la page est visible **et**
 * qu'une saisie (clavier, pointeur) a eu lieu **depuis le tick précédent**.
 * Chaque tick consomme la saisie, qu'il batte ou non.
 *
 * Pourquoi « depuis le tick précédent », et non « dans les 60 dernières
 * secondes » : combinée à la règle serveur (un écart de plus de
 * `idle_seconds` entre deux battements n'ajoute rien), elle exclut EN ENTIER
 * toute pause plus longue que la fenêtre. Au réglage par défaut (battement
 * toutes les 15 s, fenêtre de 60 s) : dernière saisie juste avant le tick de
 * `t = 0`, reprise à `t = 100`, battement suivant à `t = 105` — écart de
 * 105 s, rien n'est compté. L'ancienne règle laissait les battements courir
 * une minute après la dernière saisie, et une pause de cent secondes était
 * comptée en entier.
 */

/** L'état de la porte entre deux ticks. */
export type HeartbeatGate = {
    /** Une saisie a-t-elle eu lieu depuis le tick précédent ? */
    inputSinceTick: boolean;
};

/** Ce que décide un tick : battre ou non, et l'état pour le tick suivant. */
export type HeartbeatTick = {
    beat: boolean;
    gate: HeartbeatGate;
};

/** Aucune saisie depuis le dernier tick : l'état de départ et d'après chaque tick. */
export const HEARTBEAT_GATE_IDLE: HeartbeatGate = { inputSinceTick: false };

/**
 * Les événements qui valent saisie : le clavier et le pointeur — souris,
 * stylet, toucher —, molette comprise (zoom du recadreur, défilement de la
 * banque).
 */
export const HEARTBEAT_INPUT_EVENTS = [
    'keydown',
    'pointerdown',
    'pointermove',
    'wheel',
] as const;

export type HeartbeatInputEvent = (typeof HEARTBEAT_INPUT_EVENTS)[number];

/** Une saisie : le prochain tick pourra battre. */
export function afterInput(): HeartbeatGate {
    return { inputSinceTick: true };
}

/**
 * Un tick du minuteur. `visible` : la page du film est-elle visible à cet
 * instant ? Un onglet en arrière-plan ne bat jamais, même après une saisie.
 */
export function onTick(gate: HeartbeatGate, visible: boolean): HeartbeatTick {
    return {
        beat: visible && gate.inputSinceTick,
        gate: HEARTBEAT_GATE_IDLE,
    };
}
