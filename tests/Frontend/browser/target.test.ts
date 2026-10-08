import { describe, expect, it } from 'vite-plus/test';
import { resolveTarget } from '../../Browser/support/target';

describe('cible des parcours Playwright (spec 100 § 19)', () => {
    it('refuse de jouer en CI, même avec une cible valide', () => {
        expect(() =>
            resolveTarget({
                CI: 'true',
                E2E_BASE_URL: 'https://preprod.example.test',
            }),
        ).toThrow(/jamais joués en CI/);
    });

    it('exige une adresse de préproduction', () => {
        expect(() => resolveTarget({})).toThrow(/E2E_BASE_URL est vide/);
        expect(() => resolveTarget({ E2E_BASE_URL: '   ' })).toThrow(
            /E2E_BASE_URL est vide/,
        );
        expect(() => resolveTarget({ E2E_BASE_URL: 'pas une url' })).toThrow(
            /illisible/,
        );
    });

    it('refuse tout hôte distant qui n’est pas preprod.*, ou servi en clair', () => {
        expect(() =>
            resolveTarget({ E2E_BASE_URL: 'https://example.com' }),
        ).toThrow(/jamais contre la production/);
        expect(() =>
            resolveTarget({ E2E_BASE_URL: 'https://www.example.com' }),
        ).toThrow(/jamais contre la production/);
        expect(() =>
            resolveTarget({ E2E_BASE_URL: 'http://preprod.example.com' }),
        ).toThrow(/HTTPS seulement/);
    });

    it('admet la préproduction en HTTPS et ne garde que son origine', () => {
        expect(
            resolveTarget({
                E2E_BASE_URL: 'https://preprod.example.com/r/new?x=1',
            }),
        ).toEqual({ baseURL: 'https://preprod.example.com' });
    });

    it('admet une machine locale en clair, pour la mise au point', () => {
        expect(
            resolveTarget({ E2E_BASE_URL: 'http://127.0.0.1:8000' }).baseURL,
        ).toBe('http://127.0.0.1:8000');
        expect(
            resolveTarget({ E2E_BASE_URL: 'http://tripleframes.test' }).baseURL,
        ).toBe('http://tripleframes.test');
    });

    it('porte les identifiants HTTP ensemble, ou refuse', () => {
        expect(
            resolveTarget({
                E2E_BASE_URL: 'https://preprod.example.com',
                E2E_HTTP_USER: 'recette',
                E2E_HTTP_PASSWORD: 'secret',
            }).httpCredentials,
        ).toEqual({ username: 'recette', password: 'secret' });

        expect(() =>
            resolveTarget({
                E2E_BASE_URL: 'https://preprod.example.com',
                E2E_HTTP_USER: 'recette',
            }),
        ).toThrow(/ensemble/);
    });
});
