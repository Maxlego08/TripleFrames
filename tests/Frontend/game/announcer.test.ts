import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    armRoundAnnouncements,
    roundAnnouncementText,
} from '@/hooks/game/use-round-announcements';
import type { RoundAnnouncement } from '@/hooks/game/use-round-announcements';
import {
    announce,
    ANNOUNCER_MERGE_WINDOW_MS,
    currentAnnouncement,
    resetAnnouncer,
    subscribeAnnouncements,
} from '@/lib/game/announcer';
import { toLiveTimeline } from '@/lib/game/round-timeline';
import type { LiveRoundTimeline } from '@/lib/game/round-timeline';
import type { Replacements, TranslationSnapshot } from '@/lib/i18n';
import { translate, translateChoice } from '@/lib/i18n';
import type { TierWindow } from '@/types/scoring';
import type { TranslationKey } from '@/types/translations';

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
 *
 * Les annonces de manche (L90-6b) sont éprouvées sur la partie pure du hook,
 * `armRoundAnnouncements()` (100 § 6) : un montage = un appel, un démontage =
 * son nettoyage, exactement ce que fait l'effet du hook — deux fois de suite
 * sous `strictMode`. L'horloge serveur est `Date.now()` (factice) plus un
 * décalage que le test relève ou abaisse comme le ferait une poignée de main.
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

/** Début de la manche des scénarios : 23/09/2026 14:05:03.000 UTC. */
const ROUND_START_MS = Date.UTC(2026, 8, 23, 14, 5, 3, 0);

/** Lignes réelles de `lang/fr/game.php` et `lang/en/game.php` (`a11y.*`). */
const FR: TranslationSnapshot = {
    locale: 'fr',
    messages: {
        'game.a11y.halfway': 'Mi-manche.',
        'game.a11y.last_quarter': 'Dernier quart de la manche.',
        'game.a11y.round_ended': 'Manche terminée.',
        'game.a11y.seconds_left':
            'Plus que :count seconde.|Plus que :count secondes.',
        'game.a11y.tier_opened': 'Nouvelle image, :index sur :total.',
    },
};

const EN: TranslationSnapshot = {
    locale: 'en',
    messages: {
        'game.a11y.halfway': 'Halfway through the round.',
        'game.a11y.last_quarter': 'Last quarter of the round.',
        'game.a11y.round_ended': 'Round over.',
        'game.a11y.seconds_left': ':count second left.|:count seconds left.',
        'game.a11y.tier_opened': 'New frame, :index of :total.',
    },
};

/** Le traducteur que le hook tient de `useTranslations()`, sur un dictionnaire. */
function translatorOf(snapshot: TranslationSnapshot) {
    return {
        locale: snapshot.locale,
        t: (key: TranslationKey, replacements?: Replacements): string =>
            translate(snapshot, key, replacements),
        tChoice: (
            key: TranslationKey,
            count: number,
            replacements?: Replacements,
        ): string => translateChoice(snapshot, key, count, replacements),
    };
}

/** Étiquette courte d'une annonce, pour relever ce que le hook a dit. */
function labelOf(announcement: RoundAnnouncement): string {
    return announcement.kind === 'tier'
        ? `tier:${announcement.tierIndex}`
        : announcement.kind;
}

/** Fenêtres de paliers contiguës, depuis leurs durées (valeurs sans objet ici). */
function tiersOf(durations: readonly number[]): TierWindow[] {
    let offset = 0;

    return durations.map((durationMs, index): TierWindow => {
        const tier = {
            tierIndex: index + 1,
            startsAtOffsetMs: offset,
            durationMs,
            points: 100,
        };

        offset += durationMs;

        return tier;
    });
}

let gameSequence = 0;

/**
 * Une partie de scénario : sa chronologie (par défaut le réglage par défaut,
 * `D` = 30 s, `N` = 3), l'horloge serveur et le montage du hook. Chaque
 * partie a sa propre clé : le registre du module ne mêle jamais deux
 * scénarios.
 */
function scenario(durations: readonly number[] = [10_000, 10_000, 10_000]) {
    gameSequence += 1;

    const gameRef = `scenario-${gameSequence}`;
    const tiers = tiersOf(durations);
    const round = {
        sequenceIndex: 1,
        roundNumber: 1,
        roundsCount: 3,
        startsAt: new Date(ROUND_START_MS).toISOString(),
        durationMs: tiers.reduce((sum, tier) => sum + tier.durationMs, 0),
        tiers,
        choicesAtTierIndex: null,
    };
    const spoken: string[] = [];
    const listeners = new Set<() => void>();
    let offsetMs = 0;

    /**
     * L'horloge serveur du scénario : comme celle de `server-clock.ts`, elle
     * prévient ses abonnés à chaque changement de décalage.
     */
    const clock = {
        get offsetMs(): number {
            return offsetMs;
        },
        set offsetMs(next: number) {
            if (next === offsetMs) {
                return;
            }

            offsetMs = next;

            for (const listener of listeners) {
                listener();
            }
        },
    };
    const serverNow = (): number => Date.now() + offsetMs;
    const subscribeClock = (listener: () => void): (() => void) => {
        listeners.add(listener);

        return () => {
            listeners.delete(listener);
        };
    };
    const translator = translatorOf(FR);
    let cleanup: (() => void) | null = null;

    const timeline = (closed: boolean): LiveRoundTimeline =>
        toLiveTimeline(gameRef, round, closed);

    /** Monte le hook sur la chronologie (ouverte ou close) : un effet. */
    const mount = (closed = false): void => {
        cleanup = armRoundAnnouncements(
            timeline(closed),
            serverNow,
            (announcement) => {
                spoken.push(labelOf(announcement));
                announce(roundAnnouncementText(announcement, translator));
            },
            subscribeClock,
        );
    };

    /** Démonte : le nettoyage de l'effet. */
    const unmount = (): void => {
        cleanup?.();
        cleanup = null;
    };

    /** Double montage de `strictMode` : monte, démonte, remonte. */
    const strictMount = (closed = false): void => {
        mount(closed);
        unmount();
        mount(closed);
    };

    return {
        clock,
        spoken,
        mount,
        unmount,
        strictMount,
        listenerCount: (): number => listeners.size,
    };
}

/** Avance l'horloge (et les minuteurs) jusqu'à `elapsedMs` depuis le début. */
function advanceTo(elapsedMs: number): void {
    vi.advanceTimersByTime(ROUND_START_MS + elapsedMs - Date.now());
}

/** Combien de fois l'étiquette `label` a été dite. */
function timesSpoken(spoken: readonly string[], label: string): number {
    return spoken.filter((entry) => entry === label).length;
}

describe('annonces de manche', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(ROUND_START_MS);
        resetAnnouncer();
    });

    afterEach(() => {
        resetAnnouncer();
        vi.useRealTimers();
    });

    it('ne rejoue jamais un seuil déjà annoncé après un remontage', () => {
        const round = scenario();
        const { batches, stop } = recordBatches();

        // Double montage de `strictMode` dès l'ouverture : un seul minuteur
        // court, chaque seuil est lu une fois.
        round.strictMount();
        advanceTo(10_000 + ANNOUNCER_MERGE_WINDOW_MS);

        expect(round.spoken).toEqual(['tier:2']);
        expect(batches).toEqual([['Nouvelle image, 2 sur 3.']]);

        advanceTo(15_000);
        expect(round.spoken).toEqual(['tier:2', 'halfway']);

        // Remontage de l'écran juste après la mi-manche, sur une horloge
        // recalée deux secondes en arrière par une poignée de main : la
        // mi-manche redevient future, mais le registre du module l'a déjà
        // lue — elle ne l'est pas une seconde fois.
        round.unmount();
        round.clock.offsetMs = -2_000;
        round.strictMount();
        advanceTo(17_000 + ANNOUNCER_MERGE_WINDOW_MS);

        expect(round.spoken).toEqual(['tier:2', 'halfway']);

        // Le seuil suivant, lui, part à son heure, une seule fois.
        advanceTo(22_000);
        expect(round.spoken).toEqual(['tier:2', 'halfway', 'tier:3']);

        // Remontage sur une chronologie reconstruite à l'identique : rien de
        // ce qui a été lu ne revient, la suite part une fois.
        round.unmount();
        round.mount();
        advanceTo(31_000 + ANNOUNCER_MERGE_WINDOW_MS);

        expect(round.spoken).toEqual([
            'tier:2',
            'halfway',
            'tier:3',
            'last_quarter',
            'last_tenth',
        ]);

        round.unmount();

        // Deux seuils à une demi-seconde l'un de l'autre (paliers de 15,5 s
        // et 14,5 s : mi-manche à 15 s, palier 2 à 15,5 s). Remontage entre
        // les deux sur une horloge recalée en arrière : au réveil du minuteur
        // du palier 2, la mi-manche, déjà lue, ne revient pas avec lui.
        vi.setSystemTime(ROUND_START_MS);

        const close = scenario([15_500, 14_500]);

        close.mount();
        advanceTo(15_200);

        expect(close.spoken).toEqual(['halfway']);

        close.unmount();
        close.clock.offsetMs = -2_000;
        close.strictMount();
        advanceTo(17_500 + ANNOUNCER_MERGE_WINDOW_MS);

        expect(close.spoken).toEqual(['halfway', 'tier:2']);
        close.unmount();
        stop();
    });

    it('saute les seuils déjà passés à la resynchronisation', () => {
        // Arrivée en cours de manche, à 16 s : le palier 2 et la mi-manche
        // sont passés, marqués sans être lus.
        advanceTo(16_000);

        const late = scenario();
        const { batches, stop } = recordBatches();

        late.strictMount();
        advanceTo(16_000 + ANNOUNCER_MERGE_WINDOW_MS);

        expect(late.spoken).toEqual([]);
        expect(batches).toEqual([]);

        advanceTo(29_000);
        expect(late.spoken).toEqual(['tier:3', 'last_quarter', 'last_tenth']);
        expect(currentAnnouncement()).toEqual(['Plus que 3 secondes.']);
        late.unmount();

        // Resynchronisation après une coupure : l'écran suivait la manche
        // depuis son début, le minuteur n'a pas couru de 5 s à 21 s ; ce qui
        // est tombé entre-temps n'est jamais lu, la suite part à son heure.
        vi.setSystemTime(ROUND_START_MS);

        const resync = scenario();

        resync.mount();
        advanceTo(5_000);
        resync.unmount();
        vi.setSystemTime(ROUND_START_MS + 21_000);
        resync.mount();
        advanceTo(23_000);

        expect(resync.spoken).toEqual(['last_quarter']);
        resync.unmount();

        // Arrivée 200 ms après l'ouverture du palier 2 : même tout juste
        // passé, un seuil n'est jamais lu en retard.
        vi.setSystemTime(ROUND_START_MS + 10_200);

        const justAfter = scenario();

        justAfter.strictMount();
        advanceTo(16_000);

        expect(justAfter.spoken).toEqual(['halfway']);
        justAfter.unmount();

        // Horloge relevée de 7 s par une poignée de main pendant que le
        // minuteur du palier 2 attendait : le serveur est à 7 s, le palier 2
        // (10 s) et la mi-manche (15 s) sont encore futurs. La correction
        // recalcule le minuteur sans rien marquer : chaque seuil part à son
        // heure recalée.
        vi.setSystemTime(ROUND_START_MS);

        const recalibrated = scenario();

        recalibrated.mount();
        recalibrated.clock.offsetMs = 7_000;

        vi.advanceTimersByTime(3_000);
        expect(recalibrated.spoken).toEqual(['tier:2']);

        vi.advanceTimersByTime(5_000);
        expect(recalibrated.spoken).toEqual(['tier:2', 'halfway']);

        vi.advanceTimersByTime(5_000);
        expect(recalibrated.spoken).toEqual(['tier:2', 'halfway', 'tier:3']);

        // Relevée encore de 4 s au serveur à 20 s : le serveur saute à 24 s,
        // par-dessus le dernier quart (22,5 s), passé depuis plus d'une
        // fenêtre de fusion et sauté ; le dernier dixième (27 s), encore
        // futur, part à son heure recalée.
        recalibrated.clock.offsetMs = 11_000;
        vi.advanceTimersByTime(ANNOUNCER_MERGE_WINDOW_MS);

        expect(recalibrated.spoken).toEqual(['tier:2', 'halfway', 'tier:3']);

        vi.advanceTimersByTime(3_000 - ANNOUNCER_MERGE_WINDOW_MS);
        expect(recalibrated.spoken).toEqual([
            'tier:2',
            'halfway',
            'tier:3',
            'last_tenth',
        ]);

        // Le démontage retire aussi l'abonnement à l'horloge.
        recalibrated.unmount();
        expect(recalibrated.listenerCount()).toBe(0);
        stop();
    });

    it("n'annonce la fin que lorsque le serveur clôt la manche", () => {
        const round = scenario();

        round.strictMount();

        // Le chrono du client dépasse D : aucun minuteur n'annonce la fin.
        advanceTo(40_000);

        expect(round.spoken).toEqual([
            'tier:2',
            'halfway',
            'tier:3',
            'last_quarter',
            'last_tenth',
        ]);
        expect(currentAnnouncement()).toEqual(['Plus que 3 secondes.']);

        // `round.closed` arrive : la chronologie passe à `closed`, l'effet
        // démonte puis remonte (deux fois sous `strictMode`) — la fin est
        // lue une fois.
        round.unmount();
        round.strictMount(true);
        advanceTo(40_000 + ANNOUNCER_MERGE_WINDOW_MS);

        expect(timesSpoken(round.spoken, 'round_ended')).toBe(1);
        expect(currentAnnouncement()).toEqual(['Manche terminée.']);

        round.unmount();
        round.mount(true);
        expect(timesSpoken(round.spoken, 'round_ended')).toBe(1);
        round.unmount();

        // Fin anticipée (tous les joueurs connectés ont trouvé, saisie
        // close) : annoncée à l'événement serveur, puis plus aucun seuil.
        vi.setSystemTime(ROUND_START_MS);

        const early = scenario();

        early.mount();
        advanceTo(12_000);
        early.unmount();
        early.mount(true);
        advanceTo(60_000);

        expect(early.spoken).toEqual(['tier:2', 'round_ended']);
        early.unmount();

        // Paquet de resynchronisation demandé avant `round.closed` et reçu
        // après : la chronologie de même clé repasse ouverte, mais une manche
        // close par le serveur ne rouvre jamais — plus aucun seuil n'est lu.
        vi.setSystemTime(ROUND_START_MS);

        const reopened = scenario();

        reopened.mount();
        advanceTo(12_000);
        reopened.unmount();
        reopened.mount(true);
        reopened.unmount();
        advanceTo(12_500);
        reopened.strictMount(false);
        advanceTo(60_000);

        expect(reopened.spoken).toEqual(['tier:2', 'round_ended']);
        expect(reopened.listenerCount()).toBe(0);
        reopened.unmount();

        // Manche déjà close à sa première lecture (arrivée pendant la
        // révélation) : rien n'a été vu se clore, rien n'est annoncé.
        const alreadyClosed = scenario();

        alreadyClosed.strictMount(true);
        advanceTo(70_000);

        expect(alreadyClosed.spoken).toEqual([]);
        alreadyClosed.unmount();
    });

    it('compose chaque annonce dans la langue du joueur', () => {
        const announcements: RoundAnnouncement[] = [
            { kind: 'tier', tierIndex: 2, tierCount: 3 },
            { kind: 'halfway' },
            { kind: 'last_quarter' },
            { kind: 'last_tenth', secondsLeft: 1 },
            { kind: 'last_tenth', secondsLeft: 5 },
            { kind: 'round_ended' },
        ];

        expect(
            announcements.map((announcement) =>
                roundAnnouncementText(announcement, translatorOf(FR)),
            ),
        ).toEqual([
            'Nouvelle image, 2 sur 3.',
            'Mi-manche.',
            'Dernier quart de la manche.',
            'Plus que 1 seconde.',
            'Plus que 5 secondes.',
            'Manche terminée.',
        ]);
        expect(
            announcements.map((announcement) =>
                roundAnnouncementText(announcement, translatorOf(EN)),
            ),
        ).toEqual([
            'New frame, 2 of 3.',
            'Halfway through the round.',
            'Last quarter of the round.',
            '1 second left.',
            '5 seconds left.',
            'Round over.',
        ]);
    });
});
