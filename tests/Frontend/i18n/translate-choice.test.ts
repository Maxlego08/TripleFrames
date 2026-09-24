import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import type { TranslationSnapshot } from '@/lib/i18n';
import { translateChoice } from '@/lib/i18n';
import type { TranslationKey, TranslationMessages } from '@/types/translations';

/*
 * Parité de `translateChoice()` avec `Translator::choice` (spec 05
 * § Dictionnaire front, spec 100 § 6) : le serveur et le client doivent
 * produire exactement la même phrase, pour un e-mail comme pour un écran.
 *
 * Les clés sont de vraies clés du dictionnaire (le type `TranslationKey`
 * l'exige) ; les lignes, elles, sont synthétiques. Chaque phrase attendue est
 * celle que rend `Translator::choice` de Laravel pour la même ligne, la même
 * locale et le même nombre (relevé à l'écriture du test).
 */

const POINTS = 'common.action.save' satisfies TranslationKey;
const PLAYERS = 'common.action.cancel' satisfies TranslationKey;
const ROUNDS = 'common.action.close' satisfies TranslationKey;
const MISSING = 'common.action.confirm' satisfies TranslationKey;

const LINES: TranslationMessages = {
    // Deux formes sans condition : l'index vient de la règle de la locale.
    [POINTS]: ':count point|:count points',
    // Conditions explicites, qui priment sur la règle de la locale.
    [PLAYERS]: '{0} Aucun joueur|{1} Un joueur|[2,*] :count joueurs',
    [ROUNDS]: '[0,1] :count manche|[2,*] :count manches',
};

function dictionary(
    locale: string,
    messages: TranslationMessages = LINES,
): TranslationSnapshot {
    return { locale, messages };
}

type ChoiceCase = {
    locale: 'fr' | 'en';
    key: TranslationKey;
    count: number;
    expected: string;
};

/*
 * Laravel (`MessageSelector::getPluralIndex`) : le français met 0 et 1 au
 * singulier, l'anglais seulement 1 — « 0 point » contre « 0 points ».
 */
const CASES: ChoiceCase[] = [
    { locale: 'fr', key: POINTS, count: 0, expected: '0 point' },
    { locale: 'fr', key: POINTS, count: 1, expected: '1 point' },
    { locale: 'fr', key: POINTS, count: 2, expected: '2 points' },
    { locale: 'fr', key: POINTS, count: 5, expected: '5 points' },
    { locale: 'en', key: POINTS, count: 0, expected: '0 points' },
    { locale: 'en', key: POINTS, count: 1, expected: '1 point' },
    { locale: 'en', key: POINTS, count: 2, expected: '2 points' },
    { locale: 'en', key: POINTS, count: 5, expected: '5 points' },
    { locale: 'fr', key: PLAYERS, count: 0, expected: 'Aucun joueur' },
    { locale: 'fr', key: PLAYERS, count: 1, expected: 'Un joueur' },
    { locale: 'fr', key: PLAYERS, count: 5, expected: '5 joueurs' },
    { locale: 'en', key: PLAYERS, count: 0, expected: 'Aucun joueur' },
    { locale: 'en', key: PLAYERS, count: 1, expected: 'Un joueur' },
    { locale: 'en', key: PLAYERS, count: 5, expected: '5 joueurs' },
    { locale: 'en', key: ROUNDS, count: 0, expected: '0 manche' },
    { locale: 'en', key: ROUNDS, count: 1, expected: '1 manche' },
    { locale: 'en', key: ROUNDS, count: 2, expected: '2 manches' },
];

describe('translateChoice', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('choisit la même forme plurielle que le sélecteur de Laravel pour 0, 1 et plusieurs', () => {
        for (const { locale, key, count, expected } of CASES) {
            expect(
                translateChoice(dictionary(locale), key, count),
                `${locale} · ${key} · ${count}`,
            ).toBe(expected);
        }
    });

    it('rend la clé telle quelle quand elle manque', () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});

        expect(translateChoice(dictionary('fr', {}), MISSING, 3)).toBe(MISSING);
        expect(
            translateChoice(dictionary('en', {}), MISSING, 1, { count: '1' }),
        ).toBe(MISSING);

        // Signalée en développement, une seule fois par clé (spec 05).
        expect(error).toHaveBeenCalledTimes(1);
    });

    it('conserve un count fourni, comme Translator::choice', () => {
        const fr = dictionary('fr');

        // Nombre déjà formaté : il s'affiche tel quel, la forme suit `count`.
        expect(translateChoice(fr, POINTS, 12345, { count: '12 345' })).toBe(
            '12 345 points',
        );
        expect(translateChoice(fr, POINTS, 1, { count: '1,0' })).toBe(
            '1,0 point',
        );

        // Absent des remplacements : `count` est injecté d'office.
        expect(translateChoice(fr, POINTS, 3, { total: 9 })).toBe('3 points');
    });
});
