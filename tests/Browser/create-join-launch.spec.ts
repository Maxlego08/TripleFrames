import { expect, test } from '@playwright/test';
import {
    createRoom,
    joinRoom,
    launchGame,
    LABELS,
    newPlayer,
    seatRow,
} from './support/players';

/**
 * Parcours 1 — créer, rejoindre, lancer (spec 100 § 19).
 *
 * Écrans traversés : `room/create` (pseudo seul), `game/lobby` de l'hôte,
 * `room/join` par le lien `/r/{code}`, `game/lobby` de l'invité, décompte de
 * lancement, manche 1 (image du palier 1) chez les deux joueurs.
 */
test('un hôte crée un salon, un invité le rejoint par son lien, la partie se lance', async ({
    browser,
}, testInfo) => {
    const host = await newPlayer(browser, testInfo, 'Hote E2E');
    const guest = await newPlayer(browser, testInfo, 'Invite E2E');

    const code = await createRoom(host);

    // Une URL portant un room_code n'est jamais indexable (90 § 5).
    const room = await guest.page.request.get(`/r/${code}`);

    expect(room.headers()['x-robots-tag'] ?? '').toContain('noindex');

    await joinRoom(guest, code);

    // Chacun voit l'autre au lobby, sans recharger : présence par Reverb.
    await expect(seatRow(host.page, guest.nickname)).toBeVisible();
    await expect(seatRow(guest.page, host.nickname)).toBeVisible();

    // L'invité n'a pas le bouton de lancement.
    await expect(
        guest.page.getByRole('button', { name: LABELS.launch }),
    ).toHaveCount(0);

    await launchGame(host, [guest]);

    // Même manche des deux côtés, servie par le serveur.
    const hostRound = await host.page
        .getByText(LABELS.roundNumber)
        .first()
        .innerText();

    await expect(guest.page.getByText(LABELS.roundNumber).first()).toHaveText(
        hostRound,
    );
});
