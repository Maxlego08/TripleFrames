import {
    FINAL_ANNOUNCEMENT_CAP_MS,
    HALFWAY_DIVISOR,
    LAST_QUARTER_DIVISOR,
    LAST_TENTH_DIVISOR,
} from '@/lib/game/announcer';
import { parseIsoMs } from '@/lib/game/wire';
import type { RoundTimeline } from '@/types/game-wire';
import type { TierWindow } from '@/types/scoring';

/**
 * La chronologie cliente d'une manche (spec 90 § 7.3, contrat C16 § 2.6) :
 * **seule** implémentation client du palier courant et de sa valeur (D29 du
 * 23/09, R-35) ; aucun `round-clock.ts` ni `tier-value.ts` n'existe à côté.
 *
 * Elle se construit sur la charge `RoundTimeline` de `round.scheduled` (C7),
 * diffusée au salon : origine `startsAt`, durée `D`, fenêtres des paliers et
 * leurs valeurs. Rien de tout cela ne révèle le film — le barème est un
 * réglage public figé au lancement, et `RoundTimeline` ne porte ni image, ni
 * niveau, ni identifiant interne (règle 3). Les URL d'images voyagent à part.
 *
 * Tous les instants sont en millisecondes entières : `startedAtMs` depuis
 * l'époque Unix (instant serveur), les fenêtres et les seuils depuis le début
 * de la manche. L'instant courant est toujours celui de `serverNow()`
 * (`lib/game/server-clock.ts`), passé par l'appelant, jamais `Date.now()` nu.
 *
 * **Ne décide rien** (règle 1, règle 8 reformulée) : ce module n'écrit rien,
 * ne soumet rien, n'ouvre aucun palier et ne clôt aucune manche. Il sert
 * l'affichage — image et valeur basculent au même instant, celui de
 * `currentTier()` — et les annonces `aria-live` (`use-round-announcements`).
 * Le serveur seul retient le palier d'une réponse, à son instant de
 * réception, grâce comprise (C13 § 4.1).
 *
 * `tierValueAt(tiers, elapsedMs)` est la fonction de 80 (C13 § 2.4, 80 § 14,
 * D29 du 23/09), écrite ici par le lot L80-6 sur la même fenêtre que
 * `currentTier()` : la valeur affichée et l'image basculent au même instant.
 */

/** La chronologie d'une manche telle que l'écran la lit (C16 § 2.6). */
export type LiveRoundTimeline = {
    /** `${gameRef}:${sequenceIndex}` : clé opaque, jamais un identifiant interne. */
    key: string;
    /** `parseIsoMs(RoundTimeline.startsAt)`, epoch ms UTC (instant serveur). */
    startedAtMs: number;
    /** `D`, en millisecondes (`round.duration_ms`). */
    durationMs: number;
    /** Fenêtres des paliers (`round_tier`), par `tierIndex` croissant. */
    tiers: readonly TierWindow[];
    /** Vrai dès l'événement serveur de clôture (`round.closed`, C7). */
    closed: boolean;
};

/** Nature d'un seuil d'annonce (spec 90 § 7.4). */
export type AnnouncementKind =
    | 'halfway'
    | 'last_quarter'
    | 'last_tenth'
    | 'tier';

/**
 * Un seuil d'annonce : `atMs` en millisecondes entières depuis le début de la
 * manche ; `id` stable dans une manche (`halfway`, `last_quarter`,
 * `last_tenth`, `tier:<i>`), clé du registre des seuils déjà annoncés.
 */
export type AnnouncementThreshold = {
    id: string;
    atMs: number;
    kind: AnnouncementKind;
    tierIndex?: number;
};

/**
 * Ordre de lecture de seuils qui tombent au même instant (deux paliers égaux
 * à la borne basse de `D` : mi-manche et palier 2 coïncident) : la nouvelle
 * image d'abord, puis le temps écoulé, du plus tôt au plus tard.
 */
const KIND_ORDER: Record<AnnouncementKind, number> = {
    tier: 0,
    halfway: 1,
    last_quarter: 2,
    last_tenth: 3,
};

/**
 * La chronologie cliente d'une manche, à partir de sa charge. `closed` vient
 * de l'appelant : il ne passe à vrai qu'à l'événement serveur de clôture
 * (`round.closed` en multijoueur, paquet `solo.state` en solo), jamais sur le
 * chrono du client.
 *
 * Un `startsAt` mal formé lève (`parseIsoMs`) : c'est un défaut du serveur,
 * jamais un cas à tolérer en silence.
 */
export function toLiveTimeline(
    gameRef: string,
    round: RoundTimeline,
    closed: boolean,
): LiveRoundTimeline {
    return {
        key: `${gameRef}:${round.sequenceIndex}`,
        startedAtMs: parseIsoMs(round.startsAt),
        durationMs: round.durationMs,
        tiers: round.tiers.toSorted(
            (left, right) => left.tierIndex - right.tierIndex,
        ),
        closed,
    };
}

/**
 * Le palier dont la fenêtre semi-ouverte
 * `[startsAtOffsetMs, startsAtOffsetMs + durationMs)` contient `elapsedMs`,
 * sans grâce ; `null` si aucune ne le contient.
 */
function windowContaining(
    tiers: readonly TierWindow[],
    elapsedMs: number,
): TierWindow | null {
    return (
        tiers.find(
            (tier) =>
                elapsedMs >= tier.startsAtOffsetMs &&
                elapsedMs < tier.startsAtOffsetMs + tier.durationMs,
        ) ?? null
    );
}

/**
 * Le palier ouvert à l'instant serveur `serverNowMs` (epoch ms), ou `null`
 * avant le début de la manche et à partir de `D`.
 *
 * C'est l'instant de bascule **affiché** de l'image et de la valeur :
 * l'événement `tier.opened` confirme et apporte l'URL suivante, il ne
 * déclenche pas la bascule. La clôture (`closed`) ne change pas la fenêtre :
 * masquer la valeur après `round.closed` revient au sélecteur de 60
 * (`visibleTierValue()`), et l'image ouverte reste celle de son palier.
 */
export function currentTier(
    t: LiveRoundTimeline,
    serverNowMs: number,
): TierWindow | null {
    const elapsedMs = serverNowMs - t.startedAtMs;

    if (!(elapsedMs >= 0 && elapsedMs < t.durationMs)) {
        return null;
    }

    return windowContaining(t.tiers, elapsedMs);
}

/**
 * La valeur **entière** du palier courant, **sans bonus** (D29 du 23/09,
 * 80 § 14) : `points` du palier dont la fenêtre semi-ouverte
 * `[startsAtOffsetMs, startsAtOffsetMs + durationMs)` contient `elapsedMs`,
 * `null` hors de `[0, D)`. Un palier à 0 rend `0`, jamais `null` : `null` ne
 * veut dire que « hors manche ».
 *
 * - `tiers` vient de `RoundTimeline.tiers` (C7), soit `round_tier` : un
 *   réglage public figé au lancement, qui ne révèle rien (C16 § 3). L'ordre
 *   reçu est indifférent, seuls comptent les décalages.
 * - `elapsedMs` = `serverNow() − startedAtMs`, sur l'horloge resynchronisée
 *   de 60, **jamais** l'arrivée d'un événement de frontière, qui peut être en
 *   retard (le job diffuse la frontière, il ne la décide pas).
 * - Elle **ignore la grâce et le bonus**. C'est un affichage indicatif, le
 *   serveur décide (règle 1, C7 § 4.1). Écarts connus, à ne pas corriger
 *   (80 § 14) : dans les `tier_grace_ms` qui suivent une frontière, l'écran
 *   montre déjà le palier suivant quand le serveur crédite le précédent,
 *   mieux payé ; un clic envoyé juste avant une frontière et reçu plus de
 *   `tier_grace_ms` après elle est crédité au palier suivant ; le bonus,
 *   jamais affiché, s'ajoute toujours.
 *
 * Rendu par 60 : `tChoice('game.round.tier_value', points, { points:
 * fmt(points) })`, `fmt` = `Intl.NumberFormat(locale)` (C15, C16) ; masqué
 * à la clôture par le sélecteur `visibleTierValue()` de 60, pas ici.
 */
export function tierValueAt(
    tiers: readonly TierWindow[],
    elapsedMs: number,
): number | null {
    return windowContaining(tiers, elapsedMs)?.points ?? null;
}

/**
 * Les seuils d'annonce d'une manche, **relatifs à `D`** (principe 8 : `D` va
 * de la borne basse à la borne haute des réglages, et un seuil absolu n'y
 * aurait aucun sens), en millisecondes entières depuis le début de manche,
 * triés par instant :
 *
 * - mi-manche : `D − ⌊D / 2⌋` ;
 * - dernier quart : `D − ⌊D / 4⌋` ;
 * - dernier dixième : `D − min(⌊D / 10⌋, 5 s)`, plafonné pour qu'une manche
 *   à la borne haute n'annonce pas ses douze dernières secondes ;
 * - chaque palier qui s'ouvre après le début de manche (`i ≥ 2`) : son
 *   `startsAtOffsetMs`.
 *
 * Diviseurs et plafond sont les constantes de présentation de
 * `lib/game/announcer.ts`. Seuls restent les seuils de `]0, D[` : le
 * palier 1 s'ouvre avec la manche, et **la fin n'est jamais un seuil** — elle
 * n'est annoncée qu'au passage de `closed` à vrai, sur l'événement serveur.
 */
export function announcementThresholds(
    t: LiveRoundTimeline,
): ReadonlyArray<AnnouncementThreshold> {
    const durationMs = t.durationMs;
    const thresholds: AnnouncementThreshold[] = [
        {
            id: 'halfway',
            atMs: durationMs - Math.floor(durationMs / HALFWAY_DIVISOR),
            kind: 'halfway',
        },
        {
            id: 'last_quarter',
            atMs: durationMs - Math.floor(durationMs / LAST_QUARTER_DIVISOR),
            kind: 'last_quarter',
        },
        {
            id: 'last_tenth',
            atMs:
                durationMs -
                Math.min(
                    Math.floor(durationMs / LAST_TENTH_DIVISOR),
                    FINAL_ANNOUNCEMENT_CAP_MS,
                ),
            kind: 'last_tenth',
        },
        ...t.tiers.map((tier): AnnouncementThreshold => ({
            id: `tier:${tier.tierIndex}`,
            atMs: tier.startsAtOffsetMs,
            kind: 'tier',
            tierIndex: tier.tierIndex,
        })),
    ];

    return thresholds
        .filter(
            (threshold) => threshold.atMs > 0 && threshold.atMs < durationMs,
        )
        .toSorted(
            (left, right) =>
                left.atMs - right.atMs ||
                KIND_ORDER[left.kind] - KIND_ORDER[right.kind] ||
                (left.tierIndex ?? 0) - (right.tierIndex ?? 0),
        );
}
