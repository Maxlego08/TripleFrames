import { describe, expect, it } from 'vite-plus/test';
import { readSoloGestureFailure } from '@/lib/game/solo-gestures';

/*
 * Échecs des gestes du joueur solo (spec 60 § 16.5, lot L60-16) : « Voir la
 * réponse », « Passer la manche », « Manche suivante ». Le serveur n'envoie
 * qu'un code, lu par la forme de l'erreur du client HTTP d'Inertia
 * (`response.status`, `response.data` en texte brut).
 */

/** Une erreur de réponse telle que la lève le client HTTP d'Inertia. */
function responseError(status: number, data: unknown): unknown {
    return {
        name: 'HttpResponseError',
        response: {
            status,
            data: typeof data === 'string' ? data : JSON.stringify(data),
        },
    };
}

describe('solo-gestures', () => {
    it('lit les deux 409 des gestes, l’onglet supplanté, le siège perdu, une annulation, et tout le reste comme un échec', () => {
        expect(
            readSoloGestureFailure(
                responseError(409, { code: 'round_not_running' }),
            ),
        ).toBe('round_not_running');
        expect(
            readSoloGestureFailure(
                responseError(409, { code: 'not_revealing' }),
            ),
        ).toBe('not_revealing');
        expect(
            readSoloGestureFailure(
                responseError(409, { code: 'seat_superseded' }),
            ),
        ).toBe('superseded');

        // Corps déjà décodé : même lecture.
        expect(
            readSoloGestureFailure({
                response: { status: 409, data: { code: 'round_not_running' } },
            }),
        ).toBe('round_not_running');

        // 403 : plus de siège solo tenu par ce jeton, quel que soit le corps.
        expect(readSoloGestureFailure(responseError(403, ''))).toBe('lost');

        // Annulations : page quittée, rien à dire.
        expect(readSoloGestureFailure({ code: 'ERR_CANCELLED' })).toBe(
            'cancelled',
        );
        expect(readSoloGestureFailure({ name: 'AbortError' })).toBe(
            'cancelled',
        );

        // Le code ne compte que sur un 409, et seulement s'il est connu ; un
        // nom hérité de `Object.prototype` n'en est pas un.
        for (const error of [
            responseError(409, { code: 'autre' }),
            responseError(409, { code: 'toString' }),
            responseError(409, { code: 'constructor' }),
            responseError(409, 'pas du JSON'),
            responseError(422, { code: 'round_not_running' }),
            responseError(429, { message: 'Too Many Attempts.' }),
            responseError(500, ''),
            new TypeError('Failed to fetch'),
            null,
            'erreur',
        ]) {
            expect(readSoloGestureFailure(error)).toBe('failed');
        }
    });
});
