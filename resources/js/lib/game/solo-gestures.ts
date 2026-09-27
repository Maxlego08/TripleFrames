/**
 * Lecture d'un échec des gestes du joueur solo (spec 60 § 16.5, D18 du
 * 23/09) — « Voir la réponse » (`solo.reveal`), « Passer la manche »
 * (`solo.skip`) et « Manche suivante » (`solo.next`) : le serveur n'envoie
 * qu'un **code**, jamais une phrase ; le client le rend dans la langue du
 * joueur (même patron que `readNextRoundFailure()` de l'hôte).
 *
 * - `round_not_running` : 409 `{ "code": "round_not_running" }` — aucune
 *   manche en cours, ou saisie du siège déjà close
 *   (`game.errors.round_not_running`) ;
 * - `not_revealing` : 409 `{ "code": "not_revealing" }` — « Manche
 *   suivante » hors révélation (`game.errors.not_revealing`) ;
 * - `superseded` : 409 `seat_superseded` de `seat.active` — l'onglet n'a
 *   plus la main ; la relecture qui rend `seatActive: false` est laissée à
 *   l'écouteur d'erreur de `use-game-state` ;
 * - `lost` : 403 — le jeton ne tient plus de siège solo ; la relecture, qui
 *   répondra 403 à son tour, fait quitter la page ;
 * - `cancelled` : requête interrompue (page quittée), rien à dire ;
 * - `failed` : tout le reste — 429, 5xx, réseau, corps illisible.
 *
 * Lecture par la FORME de l'erreur du client HTTP d'Inertia, jamais par sa
 * classe : `HttpResponseError` porte `response.status` et `response.data`
 * (texte brut), `HttpCancelledError` le code `ERR_CANCELLED`. Fonction pure,
 * éprouvée par Vitest sans DOM (C18 § 2.4).
 */
export type SoloGestureFailure =
    | 'round_not_running'
    | 'not_revealing'
    | 'superseded'
    | 'lost'
    | 'cancelled'
    | 'failed';

/** Les trois gestes du joueur solo. */
export type SoloGestureKind = 'reveal' | 'skip' | 'next';

/** HTTP 403 Forbidden : plus de siège solo tenu par ce jeton. */
const HTTP_FORBIDDEN = 403;

/** HTTP 409 Conflict : hors précondition, ou onglet supplanté. */
const HTTP_CONFLICT = 409;

/** Codes des 409, miroirs des constantes des contrôleurs et de `seat.active`. */
const CONFLICT_CODES: ReadonlyMap<unknown, SoloGestureFailure> = new Map<
    unknown,
    SoloGestureFailure
>([
    ['round_not_running', 'round_not_running'],
    ['not_revealing', 'not_revealing'],
    ['seat_superseded', 'superseded'],
]);

/** Code des annulations du client HTTP d'Inertia (`HttpCancelledError`). */
const CANCELLED_CODE = 'ERR_CANCELLED';

function codeOf(data: unknown): unknown {
    let body: unknown = data;

    if (typeof body === 'string') {
        try {
            body = JSON.parse(body) as unknown;
        } catch {
            return null;
        }
    }

    return typeof body === 'object' && body !== null && 'code' in body
        ? body.code
        : null;
}

export function readSoloGestureFailure(error: unknown): SoloGestureFailure {
    if (typeof error !== 'object' || error === null) {
        return 'failed';
    }

    if (
        ('code' in error && error.code === CANCELLED_CODE) ||
        ('name' in error && error.name === 'AbortError')
    ) {
        return 'cancelled';
    }

    if (!('response' in error)) {
        return 'failed';
    }

    const response = (
        error as { response?: { status?: unknown; data?: unknown } }
    ).response;

    if (response?.status === HTTP_FORBIDDEN) {
        return 'lost';
    }

    if (response?.status !== HTTP_CONFLICT) {
        return 'failed';
    }

    return CONFLICT_CODES.get(codeOf(response.data)) ?? 'failed';
}
