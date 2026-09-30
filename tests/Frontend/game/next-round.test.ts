import { describe, expect, it } from 'vite-plus/test';
import { readNextRoundFailure } from '@/lib/game/next-round';

/*
 * Échecs du geste « manche suivante » de l'hôte (spec 60 § 5.4, lot L60-14) :
 * le serveur n'envoie qu'un code, lu par la forme de l'erreur du client HTTP
 * d'Inertia (`response.status`, `response.data` en texte brut).
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

describe('next-round', () => {
    it('lit un 409 hors révélation, un onglet supplanté, une annulation, et tout le reste comme un échec', () => {
        expect(
            readNextRoundFailure(responseError(409, { code: 'not_revealing' })),
        ).toBe('not_revealing');
        expect(
            readNextRoundFailure(
                responseError(409, { code: 'seat_superseded' }),
            ),
        ).toBe('superseded');

        // Corps déjà décodé : même lecture.
        expect(
            readNextRoundFailure({
                response: { status: 409, data: { code: 'not_revealing' } },
            }),
        ).toBe('not_revealing');

        // Annulations : page quittée, rien à dire.
        expect(readNextRoundFailure({ code: 'ERR_CANCELLED' })).toBe(
            'cancelled',
        );
        expect(readNextRoundFailure({ name: 'AbortError' })).toBe('cancelled');

        // Le code ne compte que sur un 409 ; tout le reste est un échec.
        for (const error of [
            responseError(403, { code: 'not_revealing' }),
            responseError(409, { code: 'autre' }),
            responseError(409, 'pas du JSON'),
            responseError(429, { message: 'Too Many Attempts.' }),
            responseError(500, ''),
            new TypeError('Failed to fetch'),
            null,
            'erreur',
        ]) {
            expect(readNextRoundFailure(error)).toBe('failed');
        }
    });
});
