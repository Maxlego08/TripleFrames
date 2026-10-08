import { defineConfig, devices } from '@playwright/test';
import { resolveTarget } from './tests/Browser/support/target';

/**
 * Parcours de bout en bout (spec 100 § 19, L100-16), joués À LA MAIN depuis le
 * poste, contre la préproduction, avant chaque promotion (`promote.yml`).
 * Jamais en CI ni dans `composer ci:check` : `resolveTarget` refuse de partir
 * sous `CI`, et seule une cible `preprod.*` (ou une machine locale) est admise.
 *
 * Variables du poste, jamais dans le dépôt ni dans `.env.example` :
 * `E2E_BASE_URL`, `E2E_HTTP_USER`, `E2E_HTTP_PASSWORD`. Navigateur installé à
 * la main (`npx playwright install chromium`), `.npmrc` interdisant tout
 * script d'installation.
 */
const target = resolveTarget(process.env);

export default defineConfig({
    testDir: './tests/Browser',
    // Les parcours partagent la préproduction : un seul à la fois, dans
    // l'ordre, aucun nouvel essai qui masquerait une instabilité.
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: true,
    timeout: 240_000,
    expect: { timeout: 15_000 },
    reporter: [
        ['list'],
        [
            'html',
            {
                open: 'never',
                outputFolder: 'storage/framework/testing/playwright-report',
            },
        ],
    ],
    outputDir: 'storage/framework/testing/playwright',
    use: {
        baseURL: target.baseURL,
        httpCredentials: target.httpCredentials,
        locale: 'en-US',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            // Viewport minimal du produit (90 § 9), clavier ouvert simulé
            // pendant la saisie (`withKeyboardOpen`).
            name: 'mobile-360',
            use: {
                ...devices['Pixel 5'],
                viewport: { width: 360, height: 640 },
            },
        },
        {
            name: 'desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1280, height: 800 },
            },
        },
    ],
});
