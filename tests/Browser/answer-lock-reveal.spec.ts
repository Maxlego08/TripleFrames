import { expect, test } from '@playwright/test';
import { DEMO_TITLES_EN } from './support/demo-titles';
import {
    createRoom,
    guessUntilLocked,
    joinRoom,
    LABELS,
    launchGame,
    newPlayer,
    revealedTitle,
    seatRow,
} from './support/players';
import type { GuessOutcome } from './support/players';

/**
 * Parcours 2 — répondre, verrouiller, révéler (spec 100 § 19).
 *
 * Écrans traversés : lobby, manche 1 (saisie libre en Normal, réglage par
 * défaut), écran « trouvé » du joueur verrouillé, liste des joueurs de
 * l'observateur, révélation.
 *
 * Personne ne connaît la réponse d'avance (règle 3) : deux joueurs la
 * cherchent en se partageant les titres du catalogue de démonstration, à la
 * cadence du salon ; l'hôte observe sans répondre. Ce qui est prouvé : le
 * premier bon répondant est verrouillé, l'observateur voit « Found it » sur
 * sa ligne SANS le titre, la manche continue jusqu'à la révélation, et le
 * titre révélé est bien celui qui a été accepté.
 */
test('le bon répondant est verrouillé, les autres voient « trouvé » sans le titre, puis la révélation', async ({
    browser,
}, testInfo) => {
    const host = await newPlayer(browser, testInfo, 'Observateur E2E');
    const seeker = await newPlayer(browser, testInfo, 'Chercheur A');
    const other = await newPlayer(browser, testInfo, 'Chercheur B');

    const code = await createRoom(host);

    await joinRoom(seeker, code);
    await joinRoom(other, code);
    await launchGame(host, [seeker, other]);

    const half = Math.ceil(DEMO_TITLES_EN.length / 2);
    let outcome: GuessOutcome = null;
    const stop = (): boolean => outcome !== null;

    const results = await Promise.all([
        guessUntilLocked(seeker, DEMO_TITLES_EN.slice(0, half), stop).then(
            (found) => (outcome ??= found),
        ),
        guessUntilLocked(other, DEMO_TITLES_EN.slice(half), stop).then(
            (found) => (outcome ??= found),
        ),
    ]);

    const found = results.find((result) => result !== null) ?? null;

    expect(
        found,
        'Aucun titre du catalogue de démonstration accepté : la préproduction porte-t-elle bien ce catalogue ?',
    ).not.toBeNull();

    if (found === null) {
        return;
    }

    // Chez l'observateur, avant toute révélation : la ligne du verrouillé
    // porte « Found it », et rien dans la page ne nomme le film, sauf les
    // quatre propositions du QCM si le dernier palier est déjà ouvert (elles
    // sont affichées à tous, et ne désignent pas la bonne).
    const row = seatRow(host.page, found.finder.nickname);

    await expect(row).toContainText(LABELS.found);
    await expect(
        host.page.getByRole('heading', {
            name: new RegExp(`^${LABELS.revealHeading}`),
        }),
    ).toHaveCount(0);

    const choicesShown = await host.page
        .getByRole('group', { name: LABELS.choices })
        .isVisible();
    const observed = choicesShown
        ? await row.innerText()
        : await host.page.locator('body').innerText();

    expect(observed).not.toContain(found.title);

    // Le premier bon répondant ne coupe pas la manche : elle continue, puis
    // révèle le titre accepté, chez chacun.
    for (const player of [host, seeker, other]) {
        expect(await revealedTitle(player.page)).toBe(found.title);
    }

    await expect(
        host.page.getByRole('region', { name: LABELS.found }),
    ).toContainText(found.finder.nickname);
});
