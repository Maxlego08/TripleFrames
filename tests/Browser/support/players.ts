import { expect } from '@playwright/test';
import type { Browser, Locator, Page, TestInfo } from '@playwright/test';

/**
 * Gestes partagés des trois parcours (spec 100 § 19).
 *
 * Chaque joueur est un CONTEXTE navigateur distinct (cookies, jeton de siège,
 * connexion Reverb propres), comme deux téléphones. L'interface est forcée en
 * anglais par le cookie `locale` (non chiffré, `05`) : les sélecteurs suivent
 * les rôles et les noms accessibles, jamais une classe CSS, et ces noms sont
 * ceux de `lang/en/*.php`, rappelés dans `LABELS`.
 */
export const LABELS = {
    consentRefuse: 'Refuse',
    nickname: 'Nickname',
    createRoom: 'Create room',
    joinRoom: 'Join',
    launch: 'Start game',
    answer: 'Your answer',
    answerSubmit: 'Submit',
    answerLocked: 'Found it!',
    found: 'Found it',
    choices: 'Choices',
    revealHeading: 'The answer',
    roundNumber: /^Round \d+ of \d+$/,
    frame: /^Frame \d+ of \d+ in the current round$/,
} as const;

/** Délai d'une partie qui démarre : décompte de lancement et premier palier. */
export const GAME_START_TIMEOUT_MS = 30_000;

/** Une manche entière au réglage par défaut, révélation comprise, avec marge. */
export const ROUND_TIMEOUT_MS = 90_000;

/**
 * Cadence d'envoi : un peu plus d'une seconde, au-dessus de la cadence par
 * défaut du salon (`attemptsPerSecond` = 1), pour qu'aucune proposition ne
 * soit refusée comme trop rapide.
 */
export const ATTEMPT_PACE_MS = 1_200;

export type Player = { page: Page; nickname: string };

/**
 * Un joueur dans son propre contexte, aux options du projet (viewport 360 × 640
 * ou bureau, identifiants HTTP de la préproduction), en anglais.
 */
export async function newPlayer(
    browser: Browser,
    testInfo: TestInfo,
    nickname: string,
): Promise<Player> {
    const use = testInfo.project.use;
    const baseURL = use.baseURL;

    if (baseURL === undefined) {
        throw new Error('baseURL absente : voir playwright.config.ts.');
    }

    const context = await browser.newContext({
        baseURL,
        viewport: use.viewport,
        isMobile: use.isMobile,
        hasTouch: use.hasTouch,
        deviceScaleFactor: use.deviceScaleFactor,
        userAgent: use.userAgent,
        httpCredentials: use.httpCredentials,
        locale: 'en-US',
    });

    await context.addCookies([{ name: 'locale', value: 'en', url: baseURL }]);

    return { page: await context.newPage(), nickname };
}

/** Ferme la bannière de consentement si elle s'affiche : on refuse. */
export async function dismissConsent(page: Page): Promise<void> {
    const refuse = page.getByRole('button', {
        name: LABELS.consentRefuse,
        exact: true,
    });

    try {
        await refuse.waitFor({ state: 'visible', timeout: 3_000 });
    } catch {
        return;
    }

    await refuse.click();
}

/** L'hôte crée un salon ; rend son code, lu dans l'adresse `/r/{code}`. */
export async function createRoom(host: Player): Promise<string> {
    await host.page.goto('/r/new');
    await dismissConsent(host.page);

    await host.page.getByLabel(LABELS.nickname).fill(host.nickname);
    await host.page.getByRole('button', { name: LABELS.createRoom }).click();
    await host.page.waitForURL(/\/r\/(?!new\b)[A-Za-z0-9]+$/);

    const code = new URL(host.page.url()).pathname.split('/').pop() ?? '';

    expect(code).toMatch(/^[A-Za-z0-9]{6}$/);

    return code;
}

/** Un invité rejoint par le lien `/r/{code}`, pseudo seul. */
export async function joinRoom(guest: Player, code: string): Promise<void> {
    await guest.page.goto(`/r/${code}`);
    await dismissConsent(guest.page);

    await guest.page.getByLabel(LABELS.nickname).fill(guest.nickname);
    await guest.page.getByRole('button', { name: LABELS.joinRoom }).click();
    await guest.page.waitForURL(new RegExp(`/r/${code}$`));
}

/** La ligne d'un joueur dans une liste de joueurs de la page. */
export function seatRow(page: Page, nickname: string): Locator {
    return page.getByRole('listitem').filter({ hasText: nickname }).first();
}

/** L'hôte lance dès que les invités sont connectés ; la manche 1 s'ouvre. */
export async function launchGame(
    host: Player,
    guests: Player[],
): Promise<void> {
    for (const guest of guests) {
        await expect(seatRow(host.page, guest.nickname)).toBeVisible();
    }

    const launch = host.page.getByRole('button', { name: LABELS.launch });

    await expect(launch).toBeEnabled({ timeout: 15_000 });
    await launch.click();

    for (const player of [host, ...guests]) {
        await expectRoundOpen(player.page);
    }
}

/** Une manche ouverte : son numéro et son image servie. */
export async function expectRoundOpen(page: Page): Promise<void> {
    await expect(page.getByText(LABELS.roundNumber).first()).toBeVisible({
        timeout: GAME_START_TIMEOUT_MS,
    });
    await expect(
        page.getByRole('img', { name: LABELS.frame }).first(),
    ).toBeVisible({ timeout: GAME_START_TIMEOUT_MS });
}

/** Le titre révélé, lu sous l'en-tête « The answer ». */
export async function revealedTitle(page: Page): Promise<string> {
    const heading = page.getByRole('heading', {
        name: new RegExp(`^${LABELS.revealHeading}`),
    });

    await expect(heading).toBeVisible({ timeout: ROUND_TIMEOUT_MS });

    const text = (await heading.innerText()).trim();

    return text.slice(LABELS.revealHeading.length).trim();
}

/**
 * Clavier virtuel simulé au viewport 360 × 640 : la hauteur visible tombe à
 * 360 px pendant la saisie (`90` § 9), et le champ comme son bouton d'envoi
 * doivent rester visibles. Sans effet au bureau.
 */
export async function withKeyboardOpen<T>(
    page: Page,
    action: () => Promise<T>,
): Promise<T> {
    const viewport = page.viewportSize();

    if (viewport === null || viewport.width > 480) {
        return action();
    }

    await page.setViewportSize({ width: viewport.width, height: 360 });

    try {
        // Comme un vrai clavier : le champ focalisé est ramené à l'écran.
        await page.getByLabel(LABELS.answer).focus();
        await expect(page.getByLabel(LABELS.answer)).toBeInViewport();
        await expect(
            page.getByRole('button', { name: LABELS.answerSubmit }),
        ).toBeInViewport();

        return await action();
    } finally {
        await page.setViewportSize(viewport);
    }
}

export type GuessOutcome = { finder: Player; title: string } | null;

/**
 * Propose des titres un par un, à la cadence du salon, jusqu'au verrouillage
 * (« Found it! ») ou jusqu'à ce qu'un autre joueur ait trouvé (`stop`).
 */
export async function guessUntilLocked(
    player: Player,
    titles: readonly string[],
    stop: () => boolean,
): Promise<GuessOutcome> {
    const { page } = player;
    const input = page.getByLabel(LABELS.answer);
    const locked = page.getByText(LABELS.answerLocked, { exact: true });

    await expect(input).toBeVisible({ timeout: GAME_START_TIMEOUT_MS });

    return withKeyboardOpen(page, async () => {
        for (const title of titles) {
            if (stop()) {
                return null;
            }

            await input.fill(title);
            await input.press('Enter');

            try {
                await locked.waitFor({
                    state: 'visible',
                    timeout: ATTEMPT_PACE_MS,
                });

                return { finder: player, title };
            } catch {
                // Pas encore : proposition suivante, à la cadence du salon.
            }
        }

        return null;
    });
}
