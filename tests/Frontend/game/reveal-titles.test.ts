import { describe, expect, it } from 'vite-plus/test';
import { revealTitles } from '@/lib/game/reveal-titles';
import type { RevealMovie } from '@/types/game-wire';

/*
 * Assistant client unique de rendu des titres de la révélation (spec 60
 * § 9.5), partagé avec le récapitulatif du podium (80, L80-7).
 *
 * Titres fictifs : aucun titre de catalogue réel n'est une donnée de test
 * nécessaire, seules comptent les formes (translittération, rang 3, titre
 * original identique au titre affiché, année inconnue).
 */

/** Un film au titre original japonais, translittéré, traduit dans les deux locales. */
const TRANSLITERATED: RevealMovie = {
    titles: {
        fr: { text: 'Le Voyage du lanternier', lang: 'fr' },
        en: { text: 'The Lantern Keeper’s Journey', lang: 'en' },
    },
    originalTitle: '提灯守の旅',
    originalTitleLatin: 'Chōchin-mori no tabi',
    originalLanguage: 'ja',
    year: 2003,
};

/**
 * Un film français sans titre anglais : la locale `en` retombe au rang 3,
 * sur le titre original, avec la langue dans laquelle il est écrit.
 */
const SAME_AS_ORIGINAL: RevealMovie = {
    titles: {
        fr: { text: 'La Nuit des lanternes', lang: 'fr' },
        en: { text: 'La Nuit des lanternes', lang: 'fr' },
    },
    originalTitle: 'La Nuit des lanternes',
    originalTitleLatin: null,
    originalLanguage: 'fr',
    year: null,
};

/** Un film espagnol traduit, sans translittération. */
const TRANSLATED: RevealMovie = {
    titles: {
        fr: { text: 'Le Labyrinthe de l’horloger', lang: 'fr' },
        en: { text: 'The Clockmaker’s Labyrinth', lang: 'en' },
    },
    originalTitle: 'El laberinto del relojero',
    originalTitleLatin: null,
    originalLanguage: 'es',
    year: 1987,
};

describe('reveal-titles', () => {
    it("rend le titre de la locale avec son lang, le titre original seulement s'il diffère, et l'année", () => {
        // Titre de la locale, tel que le serveur l'a résolu, avec son `lang`.
        expect(revealTitles(TRANSLITERATED, 'fr')).toEqual({
            title: { text: 'Le Voyage du lanternier', lang: 'fr' },
            // La translittération latine prime sur l'écriture d'origine, et
            // se balise en écriture latine.
            original: { text: 'Chōchin-mori no tabi', lang: 'ja-Latn' },
            year: 2003,
        });
        expect(revealTitles(TRANSLITERATED, 'en').title).toEqual({
            text: 'The Lantern Keeper’s Journey',
            lang: 'en',
        });

        // Sans translittération : le titre original, dans sa langue.
        expect(revealTitles(TRANSLATED, 'en')).toEqual({
            title: { text: 'The Clockmaker’s Labyrinth', lang: 'en' },
            original: { text: 'El laberinto del relojero', lang: 'es' },
            year: 1987,
        });

        // Titre original identique au titre rendu : il n'est pas répété, dans
        // la locale qui le traduit à l'identique comme au rang 3. Année
        // inconnue : nulle, jamais inventée.
        expect(revealTitles(SAME_AS_ORIGINAL, 'fr')).toEqual({
            title: { text: 'La Nuit des lanternes', lang: 'fr' },
            original: null,
            year: null,
        });
        expect(revealTitles(SAME_AS_ORIGINAL, 'en')).toEqual({
            title: { text: 'La Nuit des lanternes', lang: 'fr' },
            original: null,
            year: null,
        });

        // Le paquet n'est jamais modifié : la révélation et le récapitulatif
        // le relisent à chaque changement de langue.
        expect(TRANSLITERATED.titles.fr.text).toBe('Le Voyage du lanternier');
    });
});
