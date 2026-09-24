import { fileURLToPath } from 'node:url';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    // Sous Vitest (`vp test`, qui pose `VITEST`), aucun greffon de
    // l'application : les tests front ne portent que sur des modules purs
    // (spec 100 § 6), et ces greffons y ont des effets de bord. Wayfinder
    // relancerait `php artisan wayfinder:generate` à chaque passe, et
    // `laravel:fonts` effacerait en sortant `public/fonts-manifest.dev.json`,
    // le manifeste du serveur `vp dev` qui tourne à côté.
    plugins: lazyPlugins(() =>
        process.env.VITEST === undefined
            ? [
                  laravel({
                      input: ['resources/css/app.css', 'resources/js/app.tsx'],
                      refresh: true,
                      fonts: [
                          bunny('Instrument Sans', {
                              weights: [400, 500, 600],
                          }),
                      ],
                  }),
                  inertia(),
                  react(),
                  babel({
                      presets: [reactCompilerPreset()],
                  }),
                  tailwindcss(),
                  wayfinder({
                      formVariants: true,
                  }),
              ]
            : [],
    ),
    // Vitest fourni par Vite+ (spec 100 § 6, contrat C18 § 2.4) : modules purs,
    // aucun environnement DOM au jalon 1, API importée de `vite-plus/test`.
    test: {
        include: ['tests/Frontend/**/*.test.ts'],
        environment: 'node',
        // L'alias `@` → `resources/js` vient du greffon Laravel, absent ici.
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            // Les specs sont la loi du projet et leur mise en forme est
            // rédigée, pas générée : oxfmt fusionne les listes ordonnées
            // qui suivent un paragraphe (l'ordre des migrations du § 12 de
            // `10-catalogue-et-modele-de-donnees.md` devient un pavé) et
            // rembourre chaque cellule de tableau. `CLAUDE.md`, versionné
            // depuis le 23/09 (D9), en est exclu pour la même raison.
            'CLAUDE.md',
            'docs/**',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
