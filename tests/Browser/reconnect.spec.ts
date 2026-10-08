import { expect, test } from '@playwright/test';
import {
    createRoom,
    expectRoundOpen,
    joinRoom,
    LABELS,
    launchGame,
    newPlayer,
    revealedTitle,
} from './support/players';

/**
 * Parcours 3 — reconnexion (spec 100 § 19).
 *
 * Écrans traversés : lobby, manche 1, rechargement de l'invité en pleine
 * manche (état de reconnexion puis resynchronisation), révélation.
 *
 * Ce qui est prouvé : après un rechargement, l'invité retrouve SON siège dans
 * la MÊME manche (jeton de siège, aucune nouvelle saisie de pseudo), peut
 * encore répondre, et le chrono est celui du serveur : la révélation tombe au
 * même instant chez l'invité rechargé et chez l'hôte, à la tolérance d'une
 * frontière près — un minuteur client relancé au rechargement la
 * repousserait de tout le temps déjà écoulé.
 */
const CLOCK_TOLERANCE_MS = 2_500;

test('un joueur qui recharge en pleine manche retrouve son siège, la même manche et le chrono du serveur', async ({
    browser,
}, testInfo) => {
    const host = await newPlayer(browser, testInfo, 'Hote Reprise');
    const guest = await newPlayer(browser, testInfo, 'Invite Reprise');

    const code = await createRoom(host);

    await joinRoom(guest, code);
    await launchGame(host, [guest]);

    const roundBefore = await guest.page
        .getByText(LABELS.roundNumber)
        .first()
        .innerText();

    // Laisser courir le chrono avant de couper : le rechargement doit
    // reprendre au temps du serveur, pas repartir de zéro.
    await guest.page.waitForTimeout(4_000);
    await guest.page.reload();

    // Même adresse, aucun formulaire d'entrée : le siège est repris.
    expect(new URL(guest.page.url()).pathname).toBe(`/r/${code}`);
    await expect(guest.page.getByLabel(LABELS.nickname)).toHaveCount(0);

    await expectRoundOpen(guest.page);
    await expect(guest.page.getByText(LABELS.roundNumber).first()).toHaveText(
        roundBefore,
    );
    await expect(guest.page.getByLabel(LABELS.answer)).toBeVisible();

    // Révélation au même instant serveur des deux côtés.
    const [hostRevealAt, guestRevealAt] = await Promise.all(
        [host, guest].map(async (player) => {
            await revealedTitle(player.page);

            return Date.now();
        }),
    );

    expect(Math.abs(hostRevealAt - guestRevealAt)).toBeLessThan(
        CLOCK_TOLERANCE_MS,
    );
});
