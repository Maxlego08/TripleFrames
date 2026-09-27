import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import {
    INPUT_STATE_KEYS,
    inputFeedback,
    isSubmissionResult,
    readSubmissionFailure,
} from '@/lib/game/input-state-keys';
import type { SubmissionError } from '@/lib/game/input-state-keys';
import type { SubmissionResult } from '@/types/answers';

/*
 * Messages de la saisie (spec 70 § 16 et § 17, contrats C10 et C11).
 *
 * La table des messages d'état est lue contre les deux fichiers dont elle
 * dépend, tels que le dépôt les porte : l'union `InputState` de
 * `types/answers.ts` — que `InputStateTest` (Pest) prouve égale aux cas de
 * l'enum PHP — et les clés générées de `types/translations.d.ts`, que
 * `TranslationCoverageTest` garde égales aux dictionnaires FR et EN,
 * symétriques. Le reste éprouve le retour affiché sous la saisie et la
 * lecture des réponses sans verdict : module pur, aucun DOM (C18 § 2.4).
 */

function repositorySource(path: string): string {
    return readFileSync(
        new URL(`../../../resources/js/${path}`, import.meta.url),
        'utf8',
    );
}

/** Les littéraux d'un type union `export type <name> = … ;` d'un fichier. */
function unionLiterals(path: string, name: string): string[] {
    const declaration = new RegExp(`export type ${name} =([^;]+);`).exec(
        repositorySource(path),
    );

    return [...(declaration?.[1] ?? '').matchAll(/'([^']+)'/g)].map(
        (match) => match[1],
    );
}

const CLOSED_MESSAGE = 'La saisie est close pour cette manche.';

function closed(inputState: SubmissionResult['inputState']): SubmissionResult {
    return { result: 'closed', inputState, message: CLOSED_MESSAGE };
}

function rejected(attemptsLeft: number): SubmissionResult {
    return { result: 'rejected', inputState: 'open', attemptsLeft };
}

const ACCEPTED: SubmissionResult = {
    result: 'accepted',
    inputState: 'locked',
    lockRank: 2,
    tierIndex: 1,
    pointsTier: 300,
    pointsBonus: 120,
    pointsTotal: 420,
};

function failure(status: number, body: unknown): unknown {
    return {
        code: 'ERR_HTTP_RESPONSE',
        response: {
            status,
            data: typeof body === 'string' ? body : JSON.stringify(body),
            headers: {},
        },
    };
}

describe('input-state-keys', () => {
    it('associe une clé traduite ou aucune à chacun des sept états de saisie', () => {
        const states = unionLiterals('types/answers.ts', 'InputState');
        const translationKeys = new Set(
            unionLiterals('types/translations.d.ts', 'TranslationKey'),
        );

        // Les sept états de l'union, ni plus ni moins, chacun une fois.
        expect(states).toHaveLength(7);
        expect(Object.keys(INPUT_STATE_KEYS).sort()).toEqual(
            [...new Set(states)].sort(),
        );

        // La table du § 16, à la lettre.
        expect(INPUT_STATE_KEYS).toEqual({
            open: null,
            text_exhausted: 'game.answer.text_exhausted',
            attempts_exhausted: 'game.answer.exhausted',
            locked: 'game.answer.locked',
            qcm_wrong: 'game.choices.wrong',
            revealed: 'game.answer.closed',
            skipped: 'game.answer.closed',
        });

        // Une saisie ouverte n'a pas de message ; toute autre a une clé
        // traduite du domaine `game`, jamais une clé brute.
        expect(translationKeys.size).toBeGreaterThan(0);

        for (const [state, key] of Object.entries(INPUT_STATE_KEYS)) {
            if (state === 'open') {
                expect(key, state).toBeNull();

                continue;
            }

            expect(key, state).not.toBeNull();
            expect(translationKeys.has(key ?? ''), `${state} → ${key}`).toBe(
                true,
            );
            expect(key?.startsWith('game.'), state).toBe(true);
        }
    });

    it('dit sous la saisie le refus neutre, l’état d’une saisie close et jamais le message d’un 409 texte épuisé', () => {
        // Rien à dire avant toute soumission.
        expect(inputFeedback('open', null, null)).toBeNull();

        // Un refus a un seul retour, quelle qu'en soit la cause : le client
        // n'en connaît aucune (décision 13).
        expect(inputFeedback('open', rejected(12), null)).toEqual({
            kind: 'rejected',
        });
        expect(inputFeedback('open', rejected(1), null)).toEqual(
            inputFeedback('open', rejected(12), null),
        );

        // Une saisie close dit son état, par la table.
        expect(inputFeedback('locked', ACCEPTED, null)).toEqual({
            kind: 'state',
            key: 'game.answer.locked',
        });
        expect(inputFeedback('qcm_wrong', null, null)).toEqual({
            kind: 'state',
            key: 'game.choices.wrong',
        });
        expect(
            inputFeedback(
                'attempts_exhausted',
                {
                    result: 'rejected',
                    inputState: 'attempts_exhausted',
                    attemptsLeft: 0,
                },
                null,
            ),
        ).toEqual({ kind: 'state', key: 'game.answer.exhausted' });

        // 409 dont l'état relu vaut `text_exhausted` : le message d'état,
        // jamais le `message` du corps (§ 7.7), que le magasin ait appliqué
        // la réponse ou non.
        for (const state of ['text_exhausted', 'open'] as const) {
            expect(
                inputFeedback(state, closed('text_exhausted'), null),
                state,
            ).toEqual({ kind: 'state', key: 'game.answer.text_exhausted' });
        }

        // Manche finie pendant l'envoi, saisie restée ouverte : le message
        // du serveur, résolu dans la langue de la requête.
        expect(inputFeedback('open', closed('open'), null)).toEqual({
            kind: 'message',
            message: CLOSED_MESSAGE,
            invalid: false,
        });

        // Un échec sans verdict l'emporte sur le verdict précédent ; seul le
        // 422 est marqué invalide (`aria-invalid`).
        const invalid: SubmissionError = {
            source: 'text',
            kind: 'invalid',
            message: 'Tapez au moins une lettre ou un chiffre.',
        };
        const tooFast: SubmissionError = {
            source: 'text',
            kind: 'too_fast',
            message: 'Une tentative à la fois.',
        };

        expect(inputFeedback('open', rejected(12), invalid)).toEqual({
            kind: 'message',
            message: invalid.message,
            invalid: true,
        });
        expect(inputFeedback('open', rejected(12), tooFast)).toEqual({
            kind: 'message',
            message: tooFast.message,
            invalid: false,
        });

        // L'état du serveur l'emporte sur un échec resté affiché : la saisie
        // s'est fermée hors de la soumission (`player.locked` après une
        // réponse perdue, relecture, « Passer » en solo).
        const failed: SubmissionError = {
            source: 'text',
            kind: 'failed',
            message: 'Une erreur est survenue.',
        };

        expect(inputFeedback('locked', null, failed)).toEqual({
            kind: 'state',
            key: 'game.answer.locked',
        });
        expect(inputFeedback('skipped', rejected(3), invalid)).toEqual({
            kind: 'state',
            key: 'game.answer.closed',
        });
        expect(inputFeedback('text_exhausted', null, tooFast)).toEqual({
            kind: 'state',
            key: 'game.answer.text_exhausted',
        });
    });

    it('lit un 409 clos comme un verdict, et distingue onglet supplanté, trop rapide, annulation et échec', () => {
        expect(
            readSubmissionFailure(failure(409, closed('text_exhausted'))),
        ).toEqual({ kind: 'result', result: closed('text_exhausted') });

        expect(
            readSubmissionFailure(failure(409, { code: 'seat_superseded' })),
        ).toEqual({ kind: 'superseded' });

        expect(
            readSubmissionFailure(
                failure(429, { message: 'Une tentative à la fois.' }),
            ),
        ).toEqual({ kind: 'too_fast', message: 'Une tentative à la fois.' });
        expect(readSubmissionFailure(failure(429, ''))).toEqual({
            kind: 'too_fast',
            message: null,
        });

        expect(readSubmissionFailure({ code: 'ERR_CANCELLED' })).toEqual({
            kind: 'cancelled',
        });
        expect(readSubmissionFailure({ name: 'AbortError' })).toEqual({
            kind: 'cancelled',
        });

        for (const error of [
            { code: 'ERR_NETWORK' },
            failure(403, ''),
            failure(500, '<html>'),
            failure(409, { result: 'closed', inputState: 'unknown' }),
            failure(409, { result: 'rejected', inputState: 'open' }),
            failure(409, '{'),
            failure(200, ACCEPTED),
            null,
            'Network error',
        ]) {
            expect(readSubmissionFailure(error), JSON.stringify(error)).toEqual(
                { kind: 'failed' },
            );
        }
    });

    it('ne reconnaît un verdict qu’à sa forme exacte', () => {
        for (const body of [
            ACCEPTED,
            rejected(0),
            closed('qcm_wrong'),
            {
                result: 'rejected',
                inputState: 'text_exhausted',
                attemptsLeft: 0,
            },
        ]) {
            expect(isSubmissionResult(body), JSON.stringify(body)).toBe(true);
        }

        for (const body of [
            { ...ACCEPTED, inputState: 'open' },
            { ...ACCEPTED, pointsBonus: 1.5 },
            { result: 'rejected', inputState: 'open', attemptsLeft: '3' },
            { result: 'rejected', inputState: 'toString', attemptsLeft: 3 },
            { result: 'closed', inputState: 'locked' },
            { result: 'pending', inputState: 'open' },
            { code: 'seat_superseded' },
            null,
            'accepted',
        ]) {
            expect(isSubmissionResult(body), JSON.stringify(body)).toBe(false);
        }
    });
});
