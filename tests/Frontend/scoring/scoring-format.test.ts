import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import { translate } from '@/lib/i18n';
import type { TranslationSnapshot } from '@/lib/i18n';
import {
    formatDuration,
    ORDINAL_KEYS,
    ordinalCategory,
    ordinalKey,
    recapTitles,
    titleSegments,
} from '@/lib/game/scoring-format';
import type { RevealMovie } from '@/types/game-wire';
import type { TranslationKey } from '@/types/translations';

/*
 * Mise en forme client du classement, du récapitulatif et du podium (spec
 * 80 § 15.1, lot L80-7) : clé ordinale, fragment du titre, durée.
 *
 * Les lignes traduites sont lues dans les dictionnaires `lang/{fr,en}/game.php`
 * tels que le dépôt les porte — `TranslationCoverageTest` (Pest) les garde
 * symétriques et en accord avec `types/translations.d.ts` —, pour que ces
 * tests éprouvent le texte réellement rendu. Module pur, aucun DOM (C18
 * § 2.4). Titres fictifs : aucun titre de catalogue réel n'est une donnée de
 * test nécessaire.
 */

function repositoryFile(path: string): string {
    return readFileSync(new URL(`../../../${path}`, import.meta.url), 'utf8');
}

/** Les littéraux d'un type union `export type <name> = … ;` des traductions. */
function translationKeys(): Set<string> {
    const declaration = /export type TranslationKey =([^;]+);/.exec(
        repositoryFile('resources/js/types/translations.d.ts'),
    );

    return new Set(
        [...(declaration?.[1] ?? '').matchAll(/'([^']+)'/g)].map(
            (match) => match[1],
        ),
    );
}

/** Déséchappe une chaîne PHP : `\u{XXXX}` (guillemets doubles) et `\'`. */
function unescapePhp(value: string): string {
    return value
        .replace(/\\u\{([0-9A-Fa-f]+)\}/g, (_, hex: string) =>
            String.fromCodePoint(Number.parseInt(hex, 16)),
        )
        .replace(/\\'/g, "'");
}

/**
 * Les lignes du dictionnaire `game` d'une locale, en clés pointées
 * (`game.leaderboard.answer_time`). Lecture ligne à ligne du fichier tel
 * qu'il est écrit (une entrée par ligne, nœuds `'nom' => [` … `],`), chaînes
 * entre guillemets simples ou doubles.
 */
function gameDictionary(locale: 'fr' | 'en'): Map<string, string> {
    const lines = new Map<string, string>();
    const path: string[] = [];

    for (const row of repositoryFile(`lang/${locale}/game.php`).split(
        /\r?\n/,
    )) {
        const node = /^\s*'([^']+)' => \[$/.exec(row);

        if (node !== null) {
            path.push(node[1]);

            continue;
        }

        if (/^\s*\],?$/.test(row)) {
            path.pop();

            continue;
        }

        const leaf =
            /^\s*'([^']+)' => (?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"),$/.exec(
                row,
            );

        if (leaf !== null) {
            lines.set(
                ['game', ...path, leaf[1]].join('.'),
                unescapePhp(leaf[2] ?? leaf[3]),
            );
        }
    }

    return lines;
}

/** Une ligne réelle du dictionnaire `game`, par sa clé. */
function gameLine(locale: 'fr' | 'en', key: TranslationKey): string {
    const line = gameDictionary(locale).get(key);

    expect(line, `${key} (${locale})`).toBeTypeOf('string');

    return line ?? '';
}

/** Un dictionnaire client d'une ligne, sur une vraie clé. */
function snapshot(
    locale: 'fr' | 'en',
    key: TranslationKey,
    line: string,
): TranslationSnapshot {
    return { locale, messages: { [key]: line } };
}

/** U+2060 WORD JOINER, entre `:rank` et son suffixe (§ 15.1). */
const JOINER = String.fromCodePoint(0x2060);

describe('scoring-format', () => {
    it('choisit la clé ordinale par Intl.PluralRules en français et en anglais', () => {
        // Les quatre clés, chacune présente dans les types générés — donc
        // dans les deux dictionnaires, symétriques —, jamais construites par
        // gabarit.
        const known = translationKeys();

        expect(Object.keys(ORDINAL_KEYS)).toEqual([
            'one',
            'two',
            'few',
            'other',
        ]);

        for (const key of Object.values(ORDINAL_KEYS)) {
            expect(known.has(key)).toBe(true);
        }

        // Anglais : 1st, 2nd, 3rd, 4th ; 11th à 13th ; 21st, 22nd, 23rd ;
        // 101st, 111th, 112th.
        const english: Array<[number, string]> = [
            [1, 'one'],
            [2, 'two'],
            [3, 'few'],
            [4, 'other'],
            [11, 'other'],
            [12, 'other'],
            [13, 'other'],
            [21, 'one'],
            [22, 'two'],
            [23, 'few'],
            [101, 'one'],
            [111, 'other'],
            [112, 'other'],
        ];

        for (const [rank, category] of english) {
            expect(ordinalCategory(rank, 'en')).toBe(category);
        }

        // Français : `one` pour 1 seulement, `other` partout ailleurs —
        // `two` et `few` n'existent que pour la symétrie des clés.
        expect(ordinalCategory(1, 'fr')).toBe('one');

        for (const rank of [2, 3, 4, 11, 21, 22, 23, 101]) {
            expect(ordinalCategory(rank, 'fr')).toBe('other');
        }

        // Une catégorie hors des quatre retombe sur `other` : `many` de
        // l'italien (8e), `zero` du gallois.
        expect(new Intl.PluralRules('it', { type: 'ordinal' }).select(8)).toBe(
            'many',
        );
        expect(ordinalCategory(8, 'it')).toBe('other');
        expect(new Intl.PluralRules('cy', { type: 'ordinal' }).select(0)).toBe(
            'zero',
        );
        expect(ordinalKey(0, 'cy')).toBe('game.leaderboard.ordinal.other');

        // Rendu réel, lignes des dictionnaires : le suffixe collé à `:rank`
        // par U+2060, invisible et insécable.
        const rendered = (locale: 'fr' | 'en', rank: number): string => {
            const key = ordinalKey(rank, locale);

            return translate(
                snapshot(locale, key, gameLine(locale, key)),
                key,
                { rank: new Intl.NumberFormat(locale).format(rank) },
            );
        };

        expect(rendered('fr', 1)).toBe(`1${JOINER}er`);
        expect(rendered('fr', 2)).toBe(`2${JOINER}e`);
        expect(rendered('fr', 3)).toBe(`3${JOINER}e`);
        expect(rendered('fr', 21)).toBe(`21${JOINER}e`);
        expect(rendered('en', 1)).toBe(`1${JOINER}st`);
        expect(rendered('en', 2)).toBe(`2${JOINER}nd`);
        expect(rendered('en', 3)).toBe(`3${JOINER}rd`);
        expect(rendered('en', 4)).toBe(`4${JOINER}th`);
        expect(rendered('en', 12)).toBe(`12${JOINER}th`);
        expect(rendered('en', 22)).toBe(`22${JOINER}nd`);
        expect(rendered('en', 113)).toBe(`113${JOINER}th`);
    });

    it('insère le titre dans un fragment portant son attribut lang', () => {
        const bestKey = 'game.podium.highlights.best_answer' as const;
        const fastestKey = 'game.podium.highlights.fastest_find' as const;

        // Français : le titre servi en français, entre guillemets français,
        // les autres paramètres interpolés, `:title` laissé en place par
        // `t()` puis remplacé par un fragment.
        const best = translate(
            snapshot('fr', bestKey, gameLine('fr', bestKey)),
            bestKey,
            { nickname: 'Alice', points: '424' },
        );

        expect(
            titleSegments(best, {
                text: 'Le Voyage du lanternier',
                lang: 'fr',
            }),
        ).toEqual([
            { kind: 'text', text: 'Meilleure réponse : Alice, « ' },
            { kind: 'title', text: 'Le Voyage du lanternier', lang: 'fr' },
            { kind: 'text', text: ' » (+424)' },
        ]);

        // Anglais, titre obtenu par repli sur la translittération : le
        // fragment porte la langue du titre, jamais celle du joueur. Le titre
        // en tête de phrase ne laisse aucun morceau de texte vide.
        const fastest = translate(
            snapshot('en', fastestKey, gameLine('en', fastestKey)),
            fastestKey,
            { nickname: 'Bob', duration: '2 sec' },
        );

        expect(
            titleSegments(fastest, {
                text: 'Chōchin-mori no tabi',
                lang: 'ja-Latn',
            }),
        ).toEqual([
            { kind: 'text', text: 'Fastest find: “' },
            { kind: 'title', text: 'Chōchin-mori no tabi', lang: 'ja-Latn' },
            { kind: 'text', text: '”, by Bob in 2 sec' },
        ]);

        expect(titleSegments(':title !', { text: 'X', lang: 'fr' })).toEqual([
            { kind: 'title', text: 'X', lang: 'fr' },
            { kind: 'text', text: ' !' },
        ]);

        // Jamais interpolé en texte brut : un titre qui porterait lui-même
        // `:points`, `:nickname` ou `:title` reste tel quel.
        const trap = 'Mission :points :nickname :title';

        expect(titleSegments(best, { text: trap, lang: 'en' })[1]).toEqual({
            kind: 'title',
            text: trap,
            lang: 'en',
        });

        // Seul le paramètre `:title` est découpé, jamais `:titles` ; une ligne
        // sans lui est rendue entière, en texte.
        expect(titleSegments('Les :titles', { text: 'X', lang: 'fr' })).toEqual(
            [{ kind: 'text', text: 'Les :titles' }],
        );

        // Le titre du fragment vient de l'assistant unique de la révélation
        // (`revealTitles()`), dans la locale du joueur.
        const packet: RevealMovie = {
            titles: {
                fr: { text: 'Le Voyage du lanternier', lang: 'fr' },
                en: { text: 'The Lantern Keeper’s Journey', lang: 'en' },
            },
            originalTitle: '提灯守の旅',
            originalTitleLatin: 'Chōchin-mori no tabi',
            originalLanguage: 'ja',
            year: 2003,
        };

        expect(recapTitles(packet, 'fr')).toEqual({
            title: { text: 'Le Voyage du lanternier', lang: 'fr' },
            original: { text: 'Chōchin-mori no tabi', lang: 'ja-Latn' },
            year: 2003,
        });
        expect(recapTitles(packet, 'en').title).toEqual({
            text: 'The Lantern Keeper’s Journey',
            lang: 'en',
        });
        // Locale absente du paquet (que le registre rend impossible) : la
        // première qu'il porte, jamais un titre vide.
        expect(recapTitles(packet, 'de').title.text).toBe(
            'Le Voyage du lanternier',
        );
    });

    it('formate une durée en millisecondes en secondes à une décimale dans la locale du joueur', () => {
        // Séparateur décimal et unité de la locale ; l'espace entre le
        // nombre et l'unité est celle d'ICU (insécable en français).
        expect(formatDuration(14_200, 'fr')).toMatch(/^14,2\ss$/u);
        expect(formatDuration(14_200, 'en')).toMatch(/^14\.2\ssec$/u);

        // Une décimale au plus : arrondie, et jamais « ,0 ».
        expect(formatDuration(12_345, 'fr')).toMatch(/^12,3\ss$/u);
        expect(formatDuration(12_360, 'en')).toMatch(/^12\.4\ssec$/u);
        expect(formatDuration(2_000, 'fr')).toMatch(/^2\ss$/u);
        expect(formatDuration(2_000, 'en')).toMatch(/^2\ssec$/u);
        expect(formatDuration(0, 'en')).toMatch(/^0\ssec$/u);

        // Exactement le format de la spec (§ 15.1), secondes = ms / 1 000.
        expect(formatDuration(87_650, 'fr')).toBe(
            new Intl.NumberFormat('fr', {
                style: 'unit',
                unit: 'second',
                maximumFractionDigits: 1,
            }).format(87.65),
        );

        // Grands totaux groupés à la manière de la locale.
        expect(formatDuration(3_600_000, 'en')).toMatch(/^3,600\ssec$/u);
        expect(formatDuration(3_600_000, 'fr')).toMatch(/^3\s600\ss$/u);

        // Dans la phrase du classement, ligne réelle du dictionnaire.
        const key = 'game.leaderboard.answer_time' as const;

        expect(
            translate(snapshot('fr', key, gameLine('fr', key)), key, {
                duration: formatDuration(14_200, 'fr'),
            }),
        ).toMatch(/^Temps cumulé : 14,2\ss$/u);
        expect(
            translate(snapshot('en', key, gameLine('en', key)), key, {
                duration: formatDuration(14_200, 'en'),
            }),
        ).toMatch(/^Total time: 14\.2\ssec$/u);
    });
});
