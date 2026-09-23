#!/usr/bin/env node
/**
 * Garde-fou de design découplé (CLAUDE.md § 7, règle 5).
 *
 * La v1 n'a pas de direction artistique, elle a une structure. La contrepartie
 * est obligatoire : tokens de thème partout, ZÉRO couleur ou taille en dur,
 * pour qu'un re-skin ne touche que le thème et la présentation.
 *
 * Ce script balaie les arbres surveillés et échoue sur :
 *
 *   - un littéral hexadécimal de couleur (`#fff`, `#1a1a1a`) ;
 *   - un appel `rgb(`, `rgba(`, `hsl(`, `hsla(`, `oklch(`, `lab(`… ;
 *   - une valeur en `px` — les largeurs se bornent en unités relatives ou par
 *     les utilitaires de grille (`min-w-[24rem]` passe, `min-w-[380px]` non) ;
 *   - une variante `dark:` — les deux forçages (back-office en clair, écran de
 *     jeu en sombre) passent par la classe `dark` posée à la racine, jamais
 *     par une variante écrite dans un composant ;
 *   - un utilitaire de couleur littérale : `bg-white`, `text-black`,
 *     `bg-neutral-*`, `text-gray-*`, `border-zinc-*`, `bg-slate-*`…
 *
 * Ne passent que les tokens : `bg-background`, `text-foreground`,
 * `border-border`, `bg-muted`, `text-muted-foreground`, `bg-card`,
 * `bg-sidebar`, `text-primary-foreground`, `bg-destructive`…
 *
 * `resources/js/components/ui/**` est HORS périmètre, et c'est volontaire :
 * ces fichiers sont générés par shadcn et le dépôt s'interdit de les éditer.
 * Le même script accueillera `resources/js/pages/game/**` quand l'écran de jeu
 * arrivera — c'est le forçage symétrique annoncé.
 *
 * Branché sur `npm run check` et `npm run check:fix`.
 */

import { readdir, readFile } from 'node:fs/promises';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));

/**
 * Arbres et fichiers surveillés. Un chemin absent n'est pas une erreur :
 * l'écran n'existe pas encore.
 *
 * Le back-office ne tient pas tout entier dans ses trois répertoires : ses
 * utilitaires vivent dans `resources/js/lib/admin-*.ts`, son forçage de thème
 * dans un hook, son contrat de props dans un type. Les laisser hors périmètre
 * ferait passer dans `resources/js/lib/` un `bg-neutral-200` que le même
 * script refuse deux répertoires plus loin.
 */
const WATCHED = [
    'resources/js/pages/admin',
    'resources/js/components/admin',
    'resources/js/layouts/admin',
    'resources/js/lib/admin-catalog-query.ts',
    'resources/js/lib/admin-enum-keys.ts',
    'resources/js/lib/admin-format.ts',
    'resources/js/lib/roles.ts',
    'resources/js/hooks/use-forced-appearance.ts',
    'resources/js/types/admin.ts',
];

const EXTENSIONS = ['.ts', '.tsx', '.css'];

/**
 * Palettes Tailwind littérales. La liste est celle de Tailwind 4 : toute
 * couleur nommée est une couleur en dur, quel que soit son ton.
 */
const LITERAL_PALETTES = [
    'slate',
    'gray',
    'zinc',
    'neutral',
    'stone',
    'red',
    'orange',
    'amber',
    'yellow',
    'lime',
    'green',
    'emerald',
    'teal',
    'cyan',
    'sky',
    'blue',
    'indigo',
    'violet',
    'purple',
    'fuchsia',
    'pink',
    'rose',
].join('|');

/** Préfixes d'utilitaires qui prennent une couleur. */
const COLOR_UTILITIES =
    'bg|text|border|ring|fill|stroke|from|via|to|outline|decoration|shadow|accent|caret|divide|placeholder';

const RULES = [
    {
        id: 'hex-color',
        pattern: /#[0-9a-fA-F]{3,8}\b/g,
        message:
            'couleur hexadécimale en dur — utilise un token (`bg-background`, `text-foreground`, …)',
    },
    {
        id: 'color-function',
        pattern: /\b(?:rgba?|hsla?|oklch|oklab|lab|lch|color-mix)\(/g,
        message:
            'fonction de couleur en dur — la palette vit dans `resources/css/app.css`, pas dans un composant',
    },
    {
        id: 'px-value',
        pattern: /\b\d+(?:\.\d+)?px\b/g,
        message:
            'valeur en `px` — borne en unités relatives (`rem`) ou par les utilitaires de grille',
    },
    {
        id: 'dark-variant',
        pattern: /(?:^|[\s"'`:[])dark:/g,
        message:
            'variante `dark:` — le forçage de thème passe par la classe posée à la racine, jamais par un composant',
    },
    {
        id: 'literal-color-utility',
        // `-current`, `-inherit` et `-transparent` ne sont PAS bannis : ils
        // héritent de la couleur ambiante au lieu d'en poser une, ce qui est
        // exactement ce qu'on demande à un composant découplé (`fill-current`).
        pattern: new RegExp(
            `\\b(?:${COLOR_UTILITIES})-(?:white|black|(?:${LITERAL_PALETTES})-\\d{2,3})\\b`,
            'g',
        ),
        message:
            'utilitaire de couleur littérale — utilise un token de thème (`bg-muted`, `text-muted-foreground`, `border-border`, …)',
    },
];

/**
 * Blanchit les commentaires en préservant longueurs et retours à la ligne —
 * les numéros de ligne restent donc exacts.
 *
 * Sans cela, la prose qui EXPLIQUE la règle (« ne jamais écrire `text-white` »)
 * la déclencherait : un commentaire ne peint rien.
 */
function blankComments(source) {
    const out = [];
    let mode = 'code';
    let quote = '';

    for (let index = 0; index < source.length; index += 1) {
        const char = source[index];
        const next = source[index + 1];

        if (mode === 'code') {
            if (char === '/' && next === '/') {
                mode = 'line';
                out.push('  ');
                index += 1;
                continue;
            }

            if (char === '/' && next === '*') {
                mode = 'block';
                out.push('  ');
                index += 1;
                continue;
            }

            if (char === "'" || char === '"' || char === '`') {
                mode = 'string';
                quote = char;
            }

            out.push(char);
            continue;
        }

        if (mode === 'string') {
            if (char === '\\') {
                out.push(char, next ?? '');
                index += 1;
                continue;
            }

            if (char === quote) {
                mode = 'code';
                quote = '';
            }

            out.push(char);
            continue;
        }

        if (mode === 'line') {
            if (char === '\n') {
                mode = 'code';
                out.push(char);
                continue;
            }

            out.push(' ');
            continue;
        }

        // mode === 'block'
        if (char === '*' && next === '/') {
            mode = 'code';
            out.push('  ');
            index += 1;
            continue;
        }

        out.push(char === '\n' ? char : ' ');
    }

    return out.join('');
}

/**
 * Échappatoire nominative, ligne par ligne : `// theme-tokens-ignore <motif>`
 * sur la ligne précédente. Elle existe pour les rares cas physiques (une
 * bordure d'un pixel matériel), elle n'est PAS un raccourci — chaque usage se
 * relit en revue.
 */
const IGNORE_MARKER = 'theme-tokens-ignore';

async function collectFiles(target) {
    let entries;

    try {
        entries = await readdir(target, { withFileTypes: true });
    } catch (error) {
        if (error.code === 'ENOENT') {
            return [];
        }

        // Un fichier nommé directement dans `WATCHED` : `readdir` refuse, et
        // c'est le seul signal portable que la cible n'est pas un répertoire.
        if (error.code === 'ENOTDIR') {
            return EXTENSIONS.some((extension) => target.endsWith(extension))
                ? [target]
                : [];
        }

        throw error;
    }

    const directory = target;

    const files = [];

    for (const entry of entries) {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            files.push(...(await collectFiles(path)));
            continue;
        }

        if (EXTENSIONS.some((extension) => entry.name.endsWith(extension))) {
            files.push(path);
        }
    }

    return files;
}

function inspect(source, relativePath) {
    const rawLines = source.split(/\r?\n/);
    const lines = blankComments(source).split(/\r?\n/);
    const findings = [];

    lines.forEach((line, index) => {
        const raw = rawLines[index] ?? '';
        const previousRaw = index > 0 ? (rawLines[index - 1] ?? '') : '';

        if (
            raw.includes(IGNORE_MARKER) ||
            previousRaw.includes(IGNORE_MARKER)
        ) {
            return;
        }

        for (const rule of RULES) {
            rule.pattern.lastIndex = 0;

            const match = rule.pattern.exec(line);

            if (match === null) {
                continue;
            }

            findings.push({
                file: relativePath,
                line: index + 1,
                rule: rule.id,
                match: match[0].trim(),
                message: rule.message,
            });
        }
    });

    return findings;
}

const files = (
    await Promise.all(
        WATCHED.map((directory) => collectFiles(join(ROOT, directory))),
    )
).flat();

const findings = (
    await Promise.all(
        files.map(async (file) => {
            const source = await readFile(file, 'utf8');

            return inspect(source, relative(ROOT, file).split(sep).join('/'));
        }),
    )
).flat();

if (findings.length > 0) {
    console.error(
        `\nTokens de thème : ${findings.length} entorse(s) dans ${WATCHED.join(', ')}\n`,
    );

    for (const finding of findings) {
        console.error(
            `  ${finding.file}:${finding.line}  [${finding.rule}]  « ${finding.match} »\n    ${finding.message}`,
        );
    }

    console.error(
        "\nLa v1 n'a pas de direction artistique, elle a une structure : un re-skin ne doit toucher que le thème.\n",
    );

    process.exit(1);
}

console.log(
    `Tokens de thème : ${files.length} fichier(s) vérifié(s), aucune couleur ni taille en dur.`,
);
