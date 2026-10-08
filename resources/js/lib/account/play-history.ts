import type { Replacements } from '@/lib/i18n';
import type { RevealMovie } from '@/types/game-wire';
import type { TranslationKey } from '@/types/translations';

/**
 * « Mes parties » — types de la charge et mise en forme client (spec 40
 * § 13.4, L40-13, D66 du 07/10). Miroir des formes de
 * `App\Support\Account\PlayHistory` (`HistoryRowPayload`, `SummaryPayload`,
 * `DetailPayload`).
 *
 * **Données, jamais phrases** : le serveur n'envoie que des entiers, des
 * instants ISO et des paquets de titres ; nombres, dates et pourcentages sont
 * formatés ici par `Intl` dans la langue du joueur (05). Module pur, sans
 * DOM ni React : il s'éprouve sous Vitest.
 */

/** Une partie de la liste. `rank` est nul en solo ou s'il n'existe pas. */
export type HistoryRow = {
    publicId: string;
    endedAt: string;
    mode: 'multiplayer' | 'solo';
    roomCode: string | null;
    status: 'completed' | 'interrupted';
    roundsCompleted: number;
    roundsCount: number;
    framesPerRound: number;
    roundsPlayed: number;
    correctAnswers: number;
    finalScore: number;
    rank: number | null;
};

/** Le meilleur score et sa partie (n° 29 : parties à score non nul seules). */
export type BestScore = {
    score: number;
    endedAt: string;
    roundsCount: number;
    framesPerRound: number;
    publicId: string;
};

/** Les quatre compteurs, parties multijoueur seules (`80` § 13). */
export type HistoryCounters = {
    gamesPlayed: number;
    correctAnswers: number;
    roundsPlayed: number;
    /** Entre 0 et 1 ; nul sous `successRateMinRounds`. */
    successRate: number | null;
    bestScore: BestScore | null;
};

export type HistorySummary = {
    counters: HistoryCounters;
    oldestKeptAt: string | null;
    windowMonths: number;
    successRateMinRounds: number;
};

export type HistoryPagination = {
    page: number;
    lastPage: number;
    total: number;
};

export type HistoryRoundOutcome = 'found' | 'missed' | 'absent';

/** Un film du détail d'une partie : ni image, ni pseudo d'un tiers. */
export type HistoryRound = {
    number: number;
    movie: RevealMovie;
    outcome: HistoryRoundOutcome;
    points: number | null;
};

/** Une traduction : la clé et ses remplacements, rendue par l'appelant. */
export type TranslatedLine = {
    key: TranslationKey;
    replacements: Replacements;
};

/** Clé de l'issue d'une manche : table de constantes typées. */
export const OUTCOME_KEYS: Record<HistoryRoundOutcome, TranslationKey> = {
    found: 'account.history.show.found',
    missed: 'account.history.show.missed',
    absent: 'account.history.show.absent',
};

/** Un entier dans la langue du joueur. */
export function formatInteger(value: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(value);
}

/**
 * Le taux de réussite en pourcentage entier, borné à [0 ; 100 %] — jamais
 * plus de cent pour cent, même sur une donnée incohérente. Nul quand le
 * serveur n'en publie pas (sous le seuil de manches jouées).
 */
export function formatSuccessRate(
    rate: number | null,
    locale: string,
): string | null {
    if (rate === null || Number.isNaN(rate)) {
        return null;
    }

    return new Intl.NumberFormat(locale, {
        style: 'percent',
        maximumFractionDigits: 0,
    }).format(Math.min(1, Math.max(0, rate)));
}

/** Une date de partie, sans l'heure, dans la langue du joueur. */
export function formatHistoryDate(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'long' }).format(
        new Date(iso),
    );
}

/**
 * Le rang affiché, formaté, ou nul (« — ») en solo ou sans rang.
 */
export function formatRank(row: HistoryRow, locale: string): string | null {
    return row.mode === 'solo' || row.rank === null
        ? null
        : formatInteger(row.rank, locale);
}

/**
 * La ligne « manches » d'une partie : « k manches sur M », ou « interrompue
 * à la manche k sur M » pour une partie interrompue.
 */
export function roundsLine(row: HistoryRow, locale: string): TranslatedLine {
    return {
        key:
            row.status === 'interrupted'
                ? 'account.history.list.interrupted'
                : 'account.history.list.rounds',
        replacements: {
            completed: formatInteger(row.roundsCompleted, locale),
            total: formatInteger(row.roundsCount, locale),
        },
    };
}

/** L'origine d'une partie : son salon, ou « Solo ». */
export function originLine(row: HistoryRow): TranslatedLine {
    return row.mode === 'solo' || row.roomCode === null
        ? { key: 'account.history.list.solo', replacements: {} }
        : {
              key: 'account.history.list.room',
              replacements: { code: row.roomCode },
          };
}
