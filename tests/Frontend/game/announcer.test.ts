import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    announce,
    ANNOUNCER_MERGE_WINDOW_MS,
    currentAnnouncement,
    resetAnnouncer,
    subscribeAnnouncements,
} from '@/lib/game/announcer';

/*
 * Annonceur des écrans de jeu (spec 90 § 7.4, contrat C16 § 2.6 et § 4).
 *
 * Une page de jeu n'a qu'une région qui parle : deux annonces trop proches
 * l'une de l'autre y sont fusionnées en un seul lot, pour que la seconde ne
 * coupe pas la première. La fenêtre de fusion est semi-ouverte : deux
 * annonces séparées d'exactement `ANNOUNCER_MERGE_WINDOW_MS` restent deux
 * lots.
 *
 * Magasin pur, aucun DOM (C18 § 2.4) : on lit ce que la région recevrait,
 * `currentAnnouncement()`, et combien de fois elle serait prévenue.
 */

/** Relève chaque lot remis à la région, dans l'ordre. */
function recordBatches(): {
    batches: Array<readonly string[]>;
    stop: () => void;
} {
    const batches: Array<readonly string[]> = [];
    const stop = subscribeAnnouncements(() => {
        batches.push(currentAnnouncement());
    });

    return { batches, stop };
}

describe('announcer', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetAnnouncer();
    });

    afterEach(() => {
        resetAnnouncer();
        vi.useRealTimers();
    });

    it("fusionne en une seule les annonces séparées de moins d'une seconde", () => {
        const { batches, stop } = recordBatches();

        announce('Mi-manche.');
        vi.advanceTimersByTime(ANNOUNCER_MERGE_WINDOW_MS - 1);

        // Fenêtre encore ouverte : rien n'est remis à la région.
        expect(batches).toEqual([]);
        expect(currentAnnouncement()).toEqual([]);

        announce('Nouvelle image, 2 sur 2.');

        // La seconde annonce rejoint la fenêtre ouverte par la première,
        // sans la prolonger : le lot part à sa fermeture.
        vi.advanceTimersByTime(1);

        expect(batches).toEqual([['Mi-manche.', 'Nouvelle image, 2 sur 2.']]);
        expect(currentAnnouncement()).toEqual([
            'Mi-manche.',
            'Nouvelle image, 2 sur 2.',
        ]);

        // Plus rien ensuite : aucune fenêtre ne reste ouverte.
        vi.advanceTimersByTime(ANNOUNCER_MERGE_WINDOW_MS * 2);
        expect(batches).toHaveLength(1);

        stop();
    });

    it("garde séparées deux annonces distantes d'exactement une seconde", () => {
        const { batches, stop } = recordBatches();

        announce('Mi-manche.');
        vi.advanceTimersByTime(ANNOUNCER_MERGE_WINDOW_MS);

        expect(batches).toEqual([['Mi-manche.']]);

        // Reçue à l'instant exact de la fermeture : la fenêtre est
        // semi-ouverte, cette annonce ouvre la suivante.
        announce('Nouvelle image, 2 sur 2.');

        expect(currentAnnouncement()).toEqual(['Mi-manche.']);

        vi.advanceTimersByTime(ANNOUNCER_MERGE_WINDOW_MS);

        expect(batches).toEqual([['Mi-manche.'], ['Nouvelle image, 2 sur 2.']]);
        expect(currentAnnouncement()).toEqual(['Nouvelle image, 2 sur 2.']);

        stop();
    });
});
