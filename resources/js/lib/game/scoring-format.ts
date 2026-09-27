import { revealTitles } from '@/lib/game/reveal-titles';
import type { RevealTitles } from '@/lib/game/reveal-titles';
import type { LocaleCode, RevealTitle } from '@/types/game-wire';
import type { TitlePacket } from '@/types/scoring';
import type { TranslationKey } from '@/types/translations';

/**
 * Mise en forme client du classement, du récapitulatif et du podium (spec
 * 80 § 15.1, lot L80-7) : ce que les composants de `components/game/`
 * (`standings-table.tsx`, `round-recap.tsx`, `podium-highlights.tsx`,
 * `podium.tsx`) rendent à partir des blocs de données de 80, dans la langue
 * du joueur.
 *
 * **Données, jamais phrases** : le serveur n'envoie que des entiers, des
 * millisecondes et des paquets de titres (05 § Nombres, dates et durées) ;
 * tout texte naît ici ou au rendu, depuis le dictionnaire. Module pur, sans
 * DOM ni React : il s'éprouve sous Vitest (C18 § 2.4).
 */

/**
 * Catégories ordinales retenues. `Intl.PluralRules` en connaît six ; les
 * clés du dictionnaire n'en nomment que quatre, et toute autre catégorie
 * retombe sur `other` (§ 15.1). Le français ne rend que `one` et `other` :
 * `two` et `few` y existent pour la symétrie des clés.
 */
export type OrdinalCategory = 'one' | 'two' | 'few' | 'other';

/**
 * Clé de chaque catégorie ordinale : une table de constantes typées
 * `TranslationKey`, **jamais une clé construite par gabarit** (§ 15.1), que
 * la vérification des clés appelées ne verrait pas et que `tsc` refuserait.
 * Dans le dictionnaire, le suffixe est séparé de `:rank` par U+2060 (WORD
 * JOINER), invisible et insécable.
 */
export const ORDINAL_KEYS: Record<OrdinalCategory, TranslationKey> = {
    one: 'game.leaderboard.ordinal.one',
    two: 'game.leaderboard.ordinal.two',
    few: 'game.leaderboard.ordinal.few',
    other: 'game.leaderboard.ordinal.other',
};

/** Règles ordinales par locale, construites une fois. */
const ordinalRules = new Map<string, Intl.PluralRules>();

/**
 * La catégorie ordinale d'un rang dans la locale du joueur :
 * `new Intl.PluralRules(locale, { type: 'ordinal' }).select(rank)`, repliée
 * sur `other` hors de `one`, `two` et `few`.
 */
export function ordinalCategory(rank: number, locale: string): OrdinalCategory {
    let rules = ordinalRules.get(locale);

    if (rules === undefined) {
        rules = new Intl.PluralRules(locale, { type: 'ordinal' });
        ordinalRules.set(locale, rules);
    }

    const category = rules.select(rank);

    return category === 'one' || category === 'two' || category === 'few'
        ? category
        : 'other';
}

/**
 * La clé ordinale d'un rang non nul, à rendre par
 * `t(ordinalKey(rank, locale), { rank: fmt(rank) })`. Un rang nul n'a pas
 * d'ordinal : il se rend par le glyphe « — » décoratif, doublé de
 * `game.leaderboard.unranked` en `sr-only` (§ 8.2).
 */
export function ordinalKey(rank: number, locale: string): TranslationKey {
    return ORDINAL_KEYS[ordinalCategory(rank, locale)];
}

/** Millisecondes par seconde : une unité, pas une valeur de jeu. */
const MS_PER_SECOND = 1000;

/** Formats de durée par locale, construits une fois. */
const durationFormats = new Map<string, Intl.NumberFormat>();

/**
 * Une durée en millisecondes (`totalAnswerTimeMs`, `answeredAtMs`), rendue
 * en secondes à une décimale au plus dans la locale du joueur (§ 15.1) :
 * `Intl.NumberFormat(locale, { style: 'unit', unit: 'second',
 * maximumFractionDigits: 1 })`. Le séparateur décimal, l'espace et l'unité
 * suivent la locale (« 14,2 s », « 14.2 sec ») ; aucun texte n'est écrit ici.
 */
export function formatDuration(ms: number, locale: string): string {
    let format = durationFormats.get(locale);

    if (format === undefined) {
        format = new Intl.NumberFormat(locale, {
            style: 'unit',
            unit: 'second',
            maximumFractionDigits: 1,
        });
        durationFormats.set(locale, format);
    }

    return format.format(ms / MS_PER_SECOND);
}

/**
 * Un morceau d'une ligne qui porte un titre : du texte dans la langue du
 * joueur, ou le titre lui-même, avec la langue dans laquelle il est écrit.
 * L'écran rend le second dans un élément portant `lang` (05 § Attribut
 * `lang`).
 */
export type TitleSegment =
    | { kind: 'text'; text: string }
    | { kind: 'title'; text: string; lang: string };

/**
 * Le paramètre `:title`, lu comme la vérification de symétrie lit un
 * paramètre (`:(?!:)([A-Za-z][A-Za-z0-9_]*)`) : jamais `:titles` ni
 * `:title_x`.
 */
const TITLE_PLACEHOLDER = /:title(?![A-Za-z0-9_])/;

/**
 * Découpe une ligne déjà traduite autour de `:title` et y insère le titre
 * en fragment (§ 15.1) : **`:title` n'est jamais interpolé en texte brut**,
 * sinon un titre obtenu par repli serait prononcé dans la langue du joueur.
 *
 * `line` est la ligne rendue par `t()` avec tous ses autres paramètres
 * (`:nickname`, `:points`, `:duration`) et SANS `title`, que `t()` laisse
 * donc en place. Le titre est inséré après l'interpolation : un titre qui
 * contiendrait lui-même `:points` ou `:nickname` reste tel quel. Une ligne
 * sans `:title` est rendue entière, en texte. Les morceaux de texte vides ne
 * sont pas rendus.
 */
export function titleSegments(
    line: string,
    title: RevealTitle,
): TitleSegment[] {
    const match = TITLE_PLACEHOLDER.exec(line);

    if (match === null) {
        return [{ kind: 'text', text: line }];
    }

    const before = line.slice(0, match.index);
    const after = line.slice(match.index + match[0].length);
    const segments: TitleSegment[] = [];

    if (before !== '') {
        segments.push({ kind: 'text', text: before });
    }

    segments.push({ kind: 'title', text: title.text, lang: title.lang });

    if (after !== '') {
        segments.push({ kind: 'text', text: after });
    }

    return segments;
}

/**
 * Les titres d'un paquet du récapitulatif dans la langue du joueur, par
 * l'assistant client **unique** de la révélation (`revealTitles()`, 60
 * § 9.5, livré par L60-9 ; 80 § 11.4) : révélation et récapitulatif ne
 * divergent jamais.
 *
 * `locale` est la locale active (`useTranslations().locale`, une chaîne) ;
 * le paquet porte un titre pour **chaque** locale activée, donc pour elle.
 * Une locale absente du paquet — que le registre des locales rend
 * impossible — retombe sur la première locale qu'il porte, plutôt que de
 * rendre un titre vide.
 */
export function recapTitles(packet: TitlePacket, locale: string): RevealTitles {
    const codes = Object.keys(packet.titles).filter(
        (code): code is LocaleCode => Object.hasOwn(packet.titles, code),
    );

    return revealTitles(
        packet,
        codes.find((code) => code === locale) ?? codes[0],
    );
}
