import type { TranslationKey, TranslationMessages } from '@/types/translations';

/**
 * `t()` maison, aucune bibliothèque i18n (spec 05, § Dictionnaire front).
 *
 * Le besoin se limite à une recherche de clé, une interpolation `:placeholder`
 * et un sélecteur de pluriel à deux formes. Une bibliothèque doublerait
 * Laravel comme source de vérité : le dictionnaire vient **toujours** de
 * `lang/`, expédié par la prop Inertia `translations`, jamais d'un fichier
 * importé côté front.
 */

export type Replacements = Record<string, string | number>;

/** Une entrée du registre des locales activées, telle que le serveur l'expédie. */
export type LocaleOption = {
    value: string;
    /** Libellé **natif**, jamais traduit : une langue s'affiche dans sa propre langue. */
    label: string;
    bcp47: string;
    dir: 'ltr' | 'rtl';
};

/** Dictionnaire actif : la locale et ses messages aplatis en clés pointées. */
export type TranslationSnapshot = {
    locale: string;
    messages: TranslationMessages;
};

const FALLBACK_LOCALE = 'en';

/**
 * Dictionnaire actif du module. Il est alimenté par `useTranslations()`, qui
 * lit les props Inertia : il n'existe que pour les appelants **hors rendu
 * React** (gestionnaire d'événement, callback de routeur). Un composant passe
 * par le hook, dont les fonctions changent d'identité à chaque bascule de
 * langue — sans quoi React Compiler mémoïserait un sous-arbre traduit dans
 * l'ancienne langue.
 */
let active: TranslationSnapshot = {
    locale: FALLBACK_LOCALE,
    messages: {},
};

export function getActiveTranslations(): TranslationSnapshot {
    return active;
}

export function setActiveTranslations(next: TranslationSnapshot): void {
    active = next;
}

/**
 * `<html lang>` et `<html dir>` sans rechargement de page : le rendu initial
 * est posé par `app.blade.php`, la bascule de langue les met à jour en place
 * pour qu'un lecteur d'écran change de voix sans que la page soit remontée.
 */
export function applyDocumentLocale(
    locale: string,
    option?: LocaleOption,
): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const lang = option?.bcp47 ?? locale;
    const dir = option?.dir ?? 'ltr';

    if (root.lang !== lang) {
        root.lang = lang;
    }

    if (root.dir !== dir) {
        root.dir = dir;
    }
}

const reported = new Set<string>();

/**
 * Clé absente à l'exécution : la clé est renvoyée telle quelle et un
 * `console.error` est émis en développement — jamais une chaîne vide, jamais
 * un `undefined` rendu. Une seule fois par clé, pour ne pas noyer la console
 * d'un écran qui rend la même liste à chaque frappe.
 */
function reportMissing(key: string): void {
    if (!import.meta.env.DEV || reported.has(key)) {
        return;
    }

    reported.add(key);

    console.error(
        `[i18n] Clé de traduction absente de la charge utile : « ${key} ». ` +
            'Le domaine est-il déclaré sur la route (middleware `translations:…`) ?',
    );
}

function lookup(
    snapshot: TranslationSnapshot,
    key: TranslationKey,
): string | null {
    const value = snapshot.messages[key];

    return typeof value === 'string' ? value : null;
}

function ucfirst(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

/**
 * Interpolation `:placeholder`, à l'identique de `Translator::makeReplacements`
 * : `:name`, `:NAME` (valeur en capitales) et `:Name` (initiale en capitale).
 * Les clés les plus longues d'abord, sinon `:n` mangerait le début de `:name`.
 */
function makeReplacements(line: string, replacements?: Replacements): string {
    if (replacements === undefined) {
        return line;
    }

    const entries = Object.entries(replacements).sort(
        ([left], [right]) => right.length - left.length,
    );

    let result = line;

    for (const [key, raw] of entries) {
        const value = String(raw);

        result = result
            .replaceAll(`:${key.toUpperCase()}`, value.toUpperCase())
            .replaceAll(`:${ucfirst(key)}`, ucfirst(value))
            .replaceAll(`:${key}`, value);
    }

    return result;
}

const CONDITION = /^[{[]([^[\]{}]*)[\]}]([\s\S]*)/;
const LEADING_CONDITION = /^[{[][^[\]{}]*[\]}]/;

function isNumeric(value: string): boolean {
    return value.trim() !== '' && !Number.isNaN(Number(value));
}

/**
 * Un segment conditionné (`{0} …`, `[2,*] …`) s'applique-t-il à ce compte ?
 * Transcription littérale de `MessageSelector::extractFromString`.
 */
function extractFromString(part: string, count: number): string | null {
    const matches = CONDITION.exec(part);

    if (matches === null) {
        return null;
    }

    const condition = matches[1];
    const value = matches[2];

    if (condition.includes(',')) {
        const separator = condition.indexOf(',');
        const from = condition.slice(0, separator);
        const to = condition.slice(separator + 1);

        if (to === '*' && isNumeric(from) && count >= Number(from)) {
            return value;
        }

        if (from === '*' && isNumeric(to) && count <= Number(to)) {
            return value;
        }

        if (
            isNumeric(from) &&
            isNumeric(to) &&
            count >= Number(from) &&
            count <= Number(to)
        ) {
            return value;
        }

        return null;
    }

    return isNumeric(condition) && Number(condition) === count ? value : null;
}

function stripConditions(segments: string[]): string[] {
    return segments.map((part) => part.replace(LEADING_CONDITION, ''));
}

/**
 * Index de forme plurielle, transcription de `MessageSelector::getPluralIndex`
 * pour les locales activées. FR et EN sont à **deux formes**, et leur seule
 * différence est le zéro : « 0 point » en français, « 0 points » en anglais.
 *
 * Ce n'est volontairement **pas** `Intl.PluralRules` : `ext-intl` est absent du
 * serveur, et le serveur doit produire exactement la même phrase que le client
 * pour un e-mail ou un message de validation. Une locale ajoutée au registre
 * ajoute son cas ici, en miroir de Laravel.
 */
function pluralIndex(locale: string, count: number): number {
    switch (locale) {
        case 'fr':
            return count === 0 || count === 1 ? 0 : 1;
        default:
            return count === 1 ? 0 : 1;
    }
}

/** Transcription de `MessageSelector::choose`, segments `|` compris. */
function choose(line: string, count: number, locale: string): string {
    const segments = line.split('|');

    for (const segment of segments) {
        const extracted = extractFromString(segment, count);

        if (extracted !== null) {
            return extracted.trim();
        }
    }

    const stripped = stripConditions(segments);
    const index = pluralIndex(locale, count);

    if (stripped.length === 1 || stripped[index] === undefined) {
        return stripped[0];
    }

    return stripped[index];
}

/** Rend une clé depuis un dictionnaire donné. Cœur partagé par `t()` et le hook. */
export function translate(
    snapshot: TranslationSnapshot,
    key: TranslationKey,
    replacements?: Replacements,
): string {
    const line = lookup(snapshot, key);

    if (line === null) {
        reportMissing(key);

        return key;
    }

    return makeReplacements(line, replacements);
}

/**
 * Rend une clé plurielle, à la parité de `Translator::choice`.
 *
 * La forme se choisit toujours sur `count`, le nombre brut. `:count`, lui, n'est
 * injecté que s'il manque aux remplacements (`isset($replace['count'])` côté
 * serveur) : un `count` fourni est conservé, ce qui permet d'afficher un nombre
 * déjà formaté par `Intl.NumberFormat` (`{ count: fmt(n) }`, spec 05
 * § Dictionnaire front).
 */
export function translateChoice(
    snapshot: TranslationSnapshot,
    key: TranslationKey,
    count: number,
    replacements?: Replacements,
): string {
    const line = lookup(snapshot, key);

    if (line === null) {
        reportMissing(key);

        return key;
    }

    return makeReplacements(choose(line, count, snapshot.locale), {
        count,
        ...replacements,
    });
}

/**
 * Traduction hors rendu React. Un composant utilise `useTranslations()` :
 * cette fonction lit le dictionnaire du module, mis à jour après le rendu, et
 * ne déclenche aucun re-rendu.
 */
export function t(key: TranslationKey, replacements?: Replacements): string {
    return translate(active, key, replacements);
}

/** Pluriel hors rendu React. Voir `t()` pour la réserve d'usage. */
export function tChoice(
    key: TranslationKey,
    count: number,
    replacements?: Replacements,
): string {
    return translateChoice(active, key, count, replacements);
}
