import { useEffect, useEffectEvent } from 'react';
import type { Translator } from '@/hooks/use-translations';
import { useTranslations } from '@/hooks/use-translations';
import { announce, ANNOUNCER_MERGE_WINDOW_MS } from '@/lib/game/announcer';
import { announcementThresholds } from '@/lib/game/round-timeline';
import type {
    AnnouncementThreshold,
    LiveRoundTimeline,
} from '@/lib/game/round-timeline';
import { subscribeServerClock } from '@/lib/game/server-clock';

/**
 * Les annonces `aria-live` d'une manche (spec 90 § 7.4, contrat C16 § 2.6 et
 * § 4) : seuils relatifs de la chronologie (`announcementThresholds()`),
 * ouverture de chaque palier après le premier, et fin de manche.
 *
 * Tout passe par `announce()` : l'annonceur fusionne ce qui tombe dans la
 * même seconde et le remet à la seule région qui parle, `GameAnnouncer`.
 * L'apparition du QCM (`game.a11y.choices_shown`) n'est pas un seuil : elle
 * suit l'arrivée des propositions et relève de l'écran de saisie de 70
 * (70 § 16) ; la reconnexion relève du hook d'état de 60 (60 § 12.6).
 *
 * Règles, et pourquoi :
 *
 * - **Un seul minuteur**, armé vers le prochain seuil non annoncé, recalculé
 *   à chaque resynchronisation — une chronologie reçue qui diffère, ou un
 *   décalage de l'horloge serveur corrigé (poignée de main, recalage sur
 *   une enveloppe) — et nettoyé au démontage : le double montage de
 *   `strictMode` en arme deux puis en retire un, jamais deux qui courent
 *   ensemble (`CLAUDE.md` §8). Recalculer sur l'horloge ne marque rien : un
 *   seuil encore futur au moment de la correction part à son heure recalée.
 * - **Jamais rétroactivement** : un seuil déjà passé quand le minuteur est
 *   armé (arrivée en cours de manche, resynchronisation) est marqué
 *   annoncé sans être lu. Un minuteur réveillé en retard d'au moins
 *   `ANNOUNCER_MERGE_WINDOW_MS` — onglet en arrière-plan, minuteurs bridés,
 *   ou horloge relevée d'un coup au-delà d'un seuil — saute de même les
 *   seuils qu'il a laissés passer : lue si tard, « mi-manche » serait
 *   fausse.
 * - **Une fois par couple (`timeline.key`, `id`)** : le registre des seuils
 *   déjà traités vit au niveau du module, pour résister au double montage et
 *   à un remontage de l'écran ; il est purgé des manches précédentes à
 *   l'arrivée d'une nouvelle.
 * - **La fin n'est annoncée qu'au passage de `closed` à vrai**, donc sur
 *   l'événement serveur (`round.closed`, paquet `solo.state`), fin anticipée
 *   « saisie close » comprise — jamais parce que le chrono du client a
 *   atteint `D`. Une manche déjà close à sa première lecture ne l'annonce
 *   pas : rien n'a été vu se clore. Une fois close, plus aucun seuil n'est
 *   armé ni lu, même si une chronologie de même clé repasse ouverte (paquet
 *   de resynchronisation demandé avant `round.closed` et reçu après) : une
 *   manche close par le serveur ne rouvre jamais.
 *
 * **Ne décide rien** (règle 1, règle 8 reformulée) : ce hook n'écrit rien, ne
 * soumet rien et n'influence aucun score ; les annonces restent dans le
 * navigateur. Le minuteur ne sert qu'à parler au bon moment.
 */

/** Une annonce de manche, en données : le texte est composé à la lecture. */
export type RoundAnnouncement =
    | { kind: 'halfway' }
    | { kind: 'last_quarter' }
    | { kind: 'last_tenth'; secondsLeft: number }
    | { kind: 'tier'; tierIndex: number; tierCount: number }
    | { kind: 'round_ended' };

/** Conversion d'unité des secondes restantes, jamais une valeur de jeu. */
const MS_PER_SECOND = 1000;

/** Entrée du registre marquant la fin de manche traitée (annoncée ou vue close). */
const ROUND_ENDED_ID = 'round_ended';

/**
 * Registre du module : `timeline.key` → identifiants des seuils déjà
 * traités, annoncés ou sautés. La présence d'une clé dit aussi que la manche
 * a été vue ouverte, condition de l'annonce de sa fin.
 */
const handled = new Map<string, Set<string>>();

/**
 * Les seuils traités de la manche `key`, et si elle est lue pour la première
 * fois. L'arrivée d'une nouvelle manche purge les précédentes : un écran de
 * jeu ne suit qu'une manche à la fois.
 */
function entryFor(key: string): { seen: Set<string>; firstSight: boolean } {
    const known = handled.get(key);

    if (known !== undefined) {
        return { seen: known, firstSight: false };
    }

    handled.clear();

    const seen = new Set<string>();

    handled.set(key, seen);

    return { seen, firstSight: true };
}

/** L'annonce portée par un seuil, en données. */
function thresholdAnnouncement(
    timeline: LiveRoundTimeline,
    threshold: AnnouncementThreshold,
): RoundAnnouncement {
    switch (threshold.kind) {
        case 'halfway':
            return { kind: 'halfway' };
        case 'last_quarter':
            return { kind: 'last_quarter' };
        case 'last_tenth':
            return {
                kind: 'last_tenth',
                secondsLeft: Math.ceil(
                    (timeline.durationMs - threshold.atMs) / MS_PER_SECOND,
                ),
            };
        case 'tier':
            return {
                kind: 'tier',
                tierIndex: threshold.tierIndex ?? 0,
                tierCount: timeline.tiers.length,
            };
    }
}

/**
 * Partie pure du hook, sans React : arme les annonces d'une chronologie et
 * rend le nettoyage. `serverNow` rend l'instant serveur (epoch ms) ; `speak`
 * reçoit chaque annonce due, en données ; `subscribeClock` prévient à chaque
 * correction du décalage de cette horloge, pour que le minuteur soit
 * recalculé (par défaut : aucune correction n'est signalée).
 *
 * Le hook l'appelle à chaque chronologie reçue qui diffère ; Vitest l'éprouve
 * sans DOM (100 § 6 : « parties pures des hooks »).
 */
export function armRoundAnnouncements(
    timeline: LiveRoundTimeline,
    serverNow: () => number,
    speak: (announcement: RoundAnnouncement) => void,
    subscribeClock: (listener: () => void) => () => void = () => () =>
        undefined,
): () => void {
    const { seen, firstSight } = entryFor(timeline.key);

    if (timeline.closed) {
        if (!seen.has(ROUND_ENDED_ID)) {
            seen.add(ROUND_ENDED_ID);

            if (!firstSight) {
                speak({ kind: 'round_ended' });
            }
        }

        return () => undefined;
    }

    // Une manche close par le serveur ne rouvre jamais : une chronologie de
    // même clé reçue ouverte après la clôture (resynchronisation demandée
    // avant `round.closed`) n'arme plus rien.
    if (seen.has(ROUND_ENDED_ID)) {
        return () => undefined;
    }

    const thresholds = announcementThresholds(timeline);
    const elapsed = (): number => serverNow() - timeline.startedAtMs;

    // Arrivée en cours de manche ou resynchronisation : ce qui est déjà
    // passé est marqué, jamais lu. Un seuil qui tombe à l'instant même n'est
    // pas passé : il part aussitôt.
    const armedAt = elapsed();

    for (const threshold of thresholds) {
        if (threshold.atMs < armedAt) {
            seen.add(threshold.id);
        }
    }

    let timer: ReturnType<typeof setTimeout> | null = null;

    const schedule = (): void => {
        const next = thresholds.find((threshold) => !seen.has(threshold.id));

        if (next === undefined) {
            timer = null;

            return;
        }

        timer = setTimeout(fire, Math.max(0, next.atMs - elapsed()));
    };

    function fire(): void {
        const now = elapsed();

        for (const threshold of thresholds) {
            if (seen.has(threshold.id) || threshold.atMs > now) {
                continue;
            }

            seen.add(threshold.id);

            if (now - threshold.atMs < ANNOUNCER_MERGE_WINDOW_MS) {
                speak(thresholdAnnouncement(timeline, threshold));
            }
        }

        // Vers le prochain seuil non traité ; réveillé trop tôt (horloge
        // abaissée entre-temps par une poignée de main), rien n'était dû et
        // c'est le même seuil qui est visé de nouveau.
        schedule();
    }

    schedule();

    // Décalage corrigé (poignée de main, recalage sur une enveloppe) : le
    // minuteur est recalculé sur l'horloge nouvelle, sans rien marquer. Un
    // seuil encore futur part à son heure recalée ; un seuil que la
    // correction a fait passer relève de `fire()` et de sa règle du retard.
    const unsubscribe = subscribeClock(() => {
        if (timer !== null) {
            clearTimeout(timer);
        }

        schedule();
    });

    return () => {
        unsubscribe();

        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };
}

/**
 * Le texte d'une annonce dans la langue du joueur ; les nombres passent par
 * `Intl.NumberFormat` de la locale (05). Partie pure du hook.
 */
export function roundAnnouncementText(
    announcement: RoundAnnouncement,
    translator: Pick<Translator, 'locale' | 't' | 'tChoice'>,
): string {
    const { t, tChoice } = translator;
    const fmt = (value: number): string =>
        new Intl.NumberFormat(translator.locale).format(value);

    switch (announcement.kind) {
        case 'halfway':
            return t('game.a11y.halfway');
        case 'last_quarter':
            return t('game.a11y.last_quarter');
        case 'last_tenth':
            return tChoice('game.a11y.seconds_left', announcement.secondsLeft, {
                count: fmt(announcement.secondsLeft),
            });
        case 'tier':
            return t('game.a11y.tier_opened', {
                index: fmt(announcement.tierIndex),
                total: fmt(announcement.tierCount),
            });
        case 'round_ended':
            return t('game.a11y.round_ended');
    }
}

/**
 * Empreinte de ce qui compte pour les annonces : deux chronologies de même
 * empreinte arment le même minuteur. Un re-rendu, ou un événement qui ne
 * change que la manche (`tier.opened`, verrouillage d'un joueur), ne réarme
 * donc rien — sans quoi un seuil tout juste échu, dont le minuteur n'a pas
 * encore couru, serait marqué passé sans être lu.
 */
function timelineSignature(timeline: LiveRoundTimeline): string {
    const tiers = timeline.tiers
        .map(
            (tier) =>
                `${tier.tierIndex}@${tier.startsAtOffsetMs}+${tier.durationMs}`,
        )
        .join(',');

    return [
        timeline.key,
        timeline.startedAtMs,
        timeline.durationMs,
        timeline.closed ? 'closed' : 'open',
        tiers,
    ].join('|');
}

/**
 * Annonce les seuils et la fin de la manche `timeline` (nulle : aucune
 * manche suivie, rien n'est armé). `serverNow` est l'horloge resynchronisée
 * de 60 (`lib/game/server-clock.ts`), dont le hook suit les corrections par
 * `subscribeServerClock()` pour recalculer son minuteur. Appelé par les
 * états de manche de 60,
 * montés par `game/lobby` en partie et par `game/solo`, **sous**
 * `GameLayout`, qui monte la région avant son premier message.
 */
export function useRoundAnnouncements(
    timeline: LiveRoundTimeline | null,
    serverNow: () => number,
): void {
    const translator = useTranslations();
    const signature = timeline === null ? null : timelineSignature(timeline);

    // Lus au moment de parler : la langue active et l'horloge courante, sans
    // réarmer le minuteur quand leur identité change.
    const speak = useEffectEvent((announcement: RoundAnnouncement): void => {
        announce(roundAnnouncementText(announcement, translator));
    });
    const now = useEffectEvent((): number => serverNow());

    const arm = useEffectEvent((): (() => void) | undefined =>
        timeline === null
            ? undefined
            : armRoundAnnouncements(
                  timeline,
                  () => now(),
                  (announcement) => speak(announcement),
                  subscribeServerClock,
              ),
    );

    useEffect(() => arm(), [signature]);
}
