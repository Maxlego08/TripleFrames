/**
 * Lecture d'un échec du geste « manche suivante » de l'hôte (`room.round.next`,
 * spec 60 § 5.4 et § 10.1) : le serveur n'envoie qu'un **code**, jamais une
 * phrase ; le client le rend dans la langue du joueur (même patron que
 * `seat_superseded`, 70 § 16).
 *
 * - `not_revealing` : 409 `{ "code": "not_revealing" }` — aucune manche en
 *   révélation, ou révélation déjà finie (`game.errors.not_revealing`) ;
 * - `superseded` : 409 `seat_superseded` de `seat.active` — l'onglet n'a plus
 *   la main ; la relecture qui rend `seatActive: false` est laissée à
 *   l'écouteur d'erreur de `use-game-state` ;
 * - `cancelled` : requête interrompue (page quittée), rien à dire ;
 * - `failed` : tout le reste — 403 d'un hôte déchu entre l'affichage et le
 *   clic, 429, 5xx, réseau, corps illisible.
 *
 * Lecture par la FORME de l'erreur du client HTTP d'Inertia, jamais par sa
 * classe : `HttpResponseError` porte `response.status` et `response.data`
 * (texte brut), `HttpCancelledError` le code `ERR_CANCELLED`. Fonction pure,
 * éprouvée par Vitest sans DOM (C18 § 2.4).
 */
export type NextRoundFailure =
    | 'not_revealing'
    | 'superseded'
    | 'cancelled'
    | 'failed';

/** HTTP 409 Conflict : hors révélation, ou onglet supplanté. */
const HTTP_CONFLICT = 409;

/** Code du 409 hors révélation, miroir de `NextRoundController::NOT_REVEALING`. */
const NOT_REVEALING = 'not_revealing';

/** Code du 409 de `seat.active`, miroir de `EnsureActiveSeat::SUPERSEDED`. */
const SEAT_SUPERSEDED = 'seat_superseded';

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

export function readNextRoundFailure(error: unknown): NextRoundFailure {
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

    if (response?.status !== HTTP_CONFLICT) {
        return 'failed';
    }

    switch (codeOf(response.data)) {
        case NOT_REVEALING:
            return 'not_revealing';
        case SEAT_SUPERSEDED:
            return 'superseded';
        default:
            return 'failed';
    }
}
