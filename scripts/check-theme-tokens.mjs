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
 * Méta-vérification, règle `[unclassified]` (spec 100 § 7.4, 90 § 9.3) :
 * `WATCHED` est une liste blanche, donc un répertoire nouveau y échapperait
 * par simple oubli de déclaration. Le script échoue aussi sur tout fichier
 * `.ts` ou `.tsx` de `resources/js` qui n'est ni sous un chemin `WATCHED`, ni
 * dans `EXEMPT`, ni sous une exemption permanente (fichiers générés). Et,
 * règle `[stale-exempt]`, sur toute entrée d'`EXEMPT` périmée : fichier
 * supprimé, ou déjà couvert par `WATCHED` ou une exemption permanente.
 *
 * Options :
 *
 *   --list-unclassified  imprime, un par ligne et triés, les fichiers `.ts` et
 *                        `.tsx` de `resources/js` qu'aucun chemin `WATCHED` ni
 *                        aucune exemption permanente ne couvre — c'est-à-dire
 *                        le contenu qu'`EXEMPT` doit avoir —, sans rien
 *                        vérifier. Sert à régénérer `EXEMPT` au commit de gel
 *                        (L90-1), qui relit la liste avant de la coller.
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

/**
 * Fichiers hérités, hors de `WATCHED` au commit de gel (spec 100 § 7.4, 90
 * § 9.3) : ceux du starter, plus les trois fichiers du client d'i18n nés
 * avant la méta-vérification (`language-switcher.tsx`, `use-translations.ts`,
 * `lib/i18n.ts`). Ils ne sont pas balayés : la plupart seront réécrits, et
 * les nettoyer en bloc serait du travail perdu.
 *
 * Cette liste NE FAIT QUE DÉCROÎTRE. Un fichier en sort — et entre dans
 * `WATCHED` — dans le commit qui le réécrit ou le supprime ; aucun fichier n'y
 * entre jamais. Un fichier nouveau se range sous un chemin `WATCHED`, jamais
 * ici : sinon la méta-vérification ne garantirait plus rien.
 *
 * Contenu produit par `--list-unclassified` ; L90-1 le régénère de la même
 * façon à son commit de gel, et le relit.
 */
const EXEMPT = [
    'resources/js/app.tsx',
    'resources/js/components/alert-error.tsx',
    'resources/js/components/app-content.tsx',
    'resources/js/components/app-header.tsx',
    'resources/js/components/app-logo-icon.tsx',
    'resources/js/components/app-logo.tsx',
    'resources/js/components/app-shell.tsx',
    'resources/js/components/app-sidebar-header.tsx',
    'resources/js/components/app-sidebar.tsx',
    'resources/js/components/appearance-tabs.tsx',
    'resources/js/components/breadcrumbs.tsx',
    'resources/js/components/delete-user.tsx',
    'resources/js/components/heading.tsx',
    'resources/js/components/input-error.tsx',
    'resources/js/components/language-switcher.tsx',
    'resources/js/components/manage-passkeys.tsx',
    'resources/js/components/manage-two-factor.tsx',
    'resources/js/components/nav-footer.tsx',
    'resources/js/components/nav-main.tsx',
    'resources/js/components/nav-user.tsx',
    'resources/js/components/passkey-item.tsx',
    'resources/js/components/passkey-register.tsx',
    'resources/js/components/passkey-verify.tsx',
    'resources/js/components/password-input.tsx',
    'resources/js/components/text-link.tsx',
    'resources/js/components/two-factor-recovery-codes.tsx',
    'resources/js/components/two-factor-setup-modal.tsx',
    'resources/js/components/user-info.tsx',
    'resources/js/components/user-menu-content.tsx',
    'resources/js/hooks/use-appearance.tsx',
    'resources/js/hooks/use-clipboard.ts',
    'resources/js/hooks/use-current-url.ts',
    'resources/js/hooks/use-flash-toast.ts',
    'resources/js/hooks/use-initials.tsx',
    'resources/js/hooks/use-mobile-navigation.ts',
    'resources/js/hooks/use-mobile.tsx',
    'resources/js/hooks/use-translations.ts',
    'resources/js/hooks/use-two-factor-auth.ts',
    'resources/js/layouts/app-layout.tsx',
    'resources/js/layouts/app/app-header-layout.tsx',
    'resources/js/layouts/app/app-sidebar-layout.tsx',
    'resources/js/layouts/auth-layout.tsx',
    'resources/js/layouts/auth/auth-card-layout.tsx',
    'resources/js/layouts/auth/auth-simple-layout.tsx',
    'resources/js/layouts/auth/auth-split-layout.tsx',
    'resources/js/layouts/settings/layout.tsx',
    'resources/js/lib/i18n.ts',
    'resources/js/lib/utils.ts',
    'resources/js/pages/auth/confirm-password.tsx',
    'resources/js/pages/auth/forgot-password.tsx',
    'resources/js/pages/auth/login.tsx',
    'resources/js/pages/auth/register.tsx',
    'resources/js/pages/auth/reset-password.tsx',
    'resources/js/pages/auth/two-factor-challenge.tsx',
    'resources/js/pages/auth/verify-email.tsx',
    'resources/js/pages/dashboard.tsx',
    'resources/js/pages/settings/appearance.tsx',
    'resources/js/pages/settings/profile.tsx',
    'resources/js/pages/settings/security.tsx',
    'resources/js/pages/welcome.tsx',
    'resources/js/types/auth.ts',
    'resources/js/types/global.d.ts',
    'resources/js/types/index.ts',
    'resources/js/types/navigation.ts',
    'resources/js/types/ui.ts',
    'resources/js/types/vite-env.d.ts',
];

/**
 * Exemptions permanentes, hors de toute liste nominative : fichiers générés
 * (shadcn, Wayfinder, `lang:types`), que le dépôt s'interdit d'éditer.
 */
const PERMANENTLY_EXEMPT = [
    'resources/js/components/ui',
    'resources/js/routes',
    'resources/js/actions',
    'resources/js/wayfinder',
    'resources/js/types/translations.d.ts',
];

/** Racine et extensions soumises à la règle `[unclassified]`. */
const CLASSIFIED_ROOT = 'resources/js';
const CLASSIFIED_EXTENSIONS = ['.ts', '.tsx'];

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

async function collectFiles(target, extensions = EXTENSIONS) {
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
            return extensions.some((extension) => target.endsWith(extension))
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
            files.push(...(await collectFiles(path, extensions)));
            continue;
        }

        if (extensions.some((extension) => entry.name.endsWith(extension))) {
            files.push(path);
        }
    }

    return files;
}

/** Chemin relatif à la racine du dépôt, séparateur `/` sur tout système. */
function toRelative(file) {
    return relative(ROOT, file).split(sep).join('/');
}

/**
 * Vrai si une entrée de la liste désigne ce fichier : le fichier lui-même, ou
 * un répertoire qui le contient. Jamais un simple préfixe de nom :
 * `pages/admin` ne couvre pas `pages/administration.tsx`.
 */
function covers(entries, path) {
    return entries.some(
        (entry) => path === entry || path.startsWith(`${entry}/`),
    );
}

/**
 * Fichiers `.ts` et `.tsx` de `resources/js` qu'aucun chemin `WATCHED` ni
 * aucune exemption permanente ne couvre, triés : ce qu'`EXEMPT` doit contenir,
 * et rien d'autre.
 */
async function outsideWatched() {
    return (await classifiable()).filter(
        (path) => !covers(WATCHED, path) && !covers(PERMANENTLY_EXEMPT, path),
    );
}

/** Tous les fichiers `.ts` et `.tsx` de `resources/js`, relatifs et triés. */
async function classifiable() {
    const all = await collectFiles(
        join(ROOT, CLASSIFIED_ROOT),
        CLASSIFIED_EXTENSIONS,
    );

    return all.map(toRelative).sort();
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

if (process.argv.includes('--list-unclassified')) {
    for (const path of await outsideWatched()) {
        console.log(path);
    }

    process.exit(0);
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

            return inspect(source, toRelative(file));
        }),
    )
).flat();

const outside = await outsideWatched();

const unclassified = outside.filter((path) => !EXEMPT.includes(path));

/**
 * Entrées périmées d'`EXEMPT` : fichier supprimé, ou déjà couvert par
 * `WATCHED` ou par une exemption permanente. Laissée en place, une telle
 * entrée exempterait en silence un fichier recréé plus tard au même chemin :
 * elle sort d'`EXEMPT` dans le commit qui réécrit ou supprime le fichier.
 */
const stale = EXEMPT.filter((path) => !outside.includes(path));

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
}

if (unclassified.length > 0) {
    console.error(
        `\nTokens de thème : ${unclassified.length} fichier(s) non classé(s) sous ${CLASSIFIED_ROOT}\n`,
    );

    for (const path of unclassified) {
        console.error(
            `  ${path}  [unclassified]\n    ni sous un chemin WATCHED, ni dans EXEMPT — l'ajouter à WATCHED (son répertoire, s'il est nouveau) dans ce commit`,
        );
    }

    console.error(
        "\nEXEMPT ne fait que décroître : un fichier nouveau n'y entre jamais (spec 100 § 7.4, 90 § 9.3).\n",
    );
}

if (stale.length > 0) {
    console.error(
        `\nTokens de thème : ${stale.length} entrée(s) périmée(s) dans EXEMPT\n`,
    );

    for (const path of stale) {
        console.error(
            `  ${path}  [stale-exempt]\n    fichier supprimé, ou déjà sous WATCHED ou une exemption permanente — le retirer d'EXEMPT dans ce commit`,
        );
    }

    console.error(
        '\nUne entrée périmée exempterait en silence un fichier recréé au même chemin (spec 100 § 7.4, 90 § 9.3).\n',
    );
}

if (findings.length > 0 || unclassified.length > 0 || stale.length > 0) {
    process.exit(1);
}

const exempted = outside.filter((path) => EXEMPT.includes(path)).length;

console.log(
    `Tokens de thème : ${files.length} fichier(s) vérifié(s), aucune couleur ni taille en dur ; ${exempted} fichier(s) hérité(s) exempté(s), aucun non classé.`,
);
