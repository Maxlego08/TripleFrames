/**
 * TripleFrames — test de charge complet (spec 100 § 16, lot L100-12).
 *
 * Scénario k6 joué DEPUIS LE POSTE du porteur, jamais depuis la CI : la CI ne
 * cible jamais la production, et k6 n'est pas une dépendance du dépôt (k6 1.0
 * ou plus récent : `k6/websockets`, `k6/timers`, `http.asyncRequest`). Hors du
 * lint à types de `vite.config.ts` (les modules `k6/*` ne s'y résolvent pas),
 * mis en forme par oxfmt comme le reste du dépôt.
 *
 * ─── Phases (une par exécution, variable PHASE) ───────────────────────────
 *
 * - `A` (§ 16.2, bloquant) : ROOMS salons (20) de 7 et 8 joueurs en
 *   alternance (150), preset `classic`, montée en RAMP_MINUTES (5), puis un
 *   palier d'au moins PLATEAU_MINUTES (15) tenu par des VAGUES de salons neufs
 *   — la non-répétition interdit de rejouer un salon sur le catalogue du J1.
 *   Chaque groupe de joueurs enchaîne, partie finie, sur le salon de la vague
 *   suivante ; le nombre de vagues (au moins 2) est dérivé en `setup()` de la
 *   durée d'une partie lue dans les réglages du salon. Joueurs coopératifs :
 *   une soumission à la fois au rythme du réglage, arrêt au verrouillage, à
 *   l'épuisement ou à la clôture, clic d'une proposition du QCM.
 * - `A2` (§ 16.2) : ROOMS salons PLEINS (`limits.roomSeats` joueurs, 240),
 *   soumission continue au rythme du réglage pendant BURST_MINUTES (5), sans
 *   s'arrêter aux refus : le budget de 240 soumissions par seconde.
 * - `B` (§ 16.3) : mêmes salons pleins, soumission continue à la borne haute
 *   (`bounds…attemptsPerSecond.max`, 5/s : 1 200 requêtes par seconde)
 *   pendant BURST_MINUTES ; l'excédent doit être refusé en 429, sans 5xx.
 * - `neighbours` (§ 16.4, critère 2) : échantillonne à faible cadence le TTFB
 *   de la page d'accueil de chaque site voisin (NEIGHBOUR_URLS). Joué seul
 *   dans les 10 minutes qui précèdent (mesure de référence), puis dans un
 *   second terminal pendant chaque phase, avec NEIGHBOUR_BASELINE_P95_MS.
 *
 * Répétition à petite échelle avant la séance (lot L100-12) : `PHASE=A
 * ROOMS=2`, éventuellement RAMP_MINUTES et PLATEAU_MINUTES réduits.
 *
 *   k6 run -e K6_BASE_URL=https://<DOMAINE> -e PHASE=A \
 *          --console-output tests/Load/.data/console-A-1.log \
 *          tests/Load/game-load.js
 *   k6 run -e PHASE=neighbours -e NEIGHBOUR_URLS=https://…,https://… \
 *          -e NEIGHBOUR_MINUTES=10 tests/Load/game-load.js
 *
 * Toujours depuis la RACINE du dépôt : les fichiers de résultats s'écrivent
 * sous LOAD_DATA_DIR (`tests/Load/.data`, ignoré par git), relatif au
 * répertoire courant. Les phases A, A2 et B se lancent TOUJOURS avec
 * `--console-output tests/Load/.data/console-<phase>-<n>.log`, un nom neuf
 * par exécution (un fichier réutilisé peut être écrasé) : c'est le journal de
 * reprise des codes de salon (§ 16.5, ci-dessous).
 *
 * ─── Préalables (§ 16.2) ──────────────────────────────────────────────────
 *
 * 1. `tests/Load/.data/titles.txt` : la liste des titres publiés, un par
 *    ligne, extraite UNE fois par le porteur en lecture sur la production,
 *    par exemple :
 *      SELECT DISTINCT mt.title FROM movie_title mt
 *        JOIN movie m ON m.id = mt.movie_id
 *       WHERE m.availability = 'published';
 *    Aucune bonne réponse ne quitte le serveur pour le test (règle 3) : les
 *    joueurs simulés tirent au hasard dans cette liste, jamais exposée par une
 *    route. Au volume du J1, ce tirage à l'aveugle trouve régulièrement, ce
 *    qui exerce la transaction de verrouillage de 70.
 * 2. Les limiteurs d'entrée du salon (spec 50 § 17.3) sont clés sur
 *    l'ADRESSE : `room-create` (10 créations par heure) et, pour un visiteur
 *    encore sans jeton, `room-join` (10 entrées par minute). Depuis un seul
 *    poste, la séance les dépasse de loin : ils sont relevés pour la séance
 *    (`game.room.*`, par un déploiement) puis rétablis. La répétition à deux
 *    salons tient dans les valeurs par défaut ; une entrée refusée en 429 est
 *    retentée après `Retry-After`, une création refusée arrête `setup()`.
 * 3. Catalogue publié suffisant pour M plus la marge de tirage ; tier chaud
 *    actif ; sondes actives ; aucun drainage ; aucune partie réelle.
 *
 * ─── Après chaque phase (§ 16.5) ──────────────────────────────────────────
 *
 * `setup()` crée les salons et journalise chaque code dès sa création
 * (`loadtest-room <code>` : le code seul, jamais les cookies) ;
 * `handleSummary()` écrit leurs codes dans `rooms-<phase>-<instant>.txt`,
 * avec le résumé JSON. Une fois toutes les parties terminées et AVANT
 * l'archivage des salons (24 h), le fichier est copié sur le serveur puis,
 * en session SSH de l'abonnement, dans le répertoire de déploiement, avec
 * `PHP=/opt/plesk/php/8.4/bin/php` (ops/mise-en-service.md), jamais le `php`
 * du système :
 *   "$PHP" artisan loadtest:forget <fichier> --dry-run
 *   "$PHP" artisan loadtest:forget <fichier>
 *
 * Reprise : un arrêt brutal de k6 (second Ctrl+C, plantage, mémoire saturée
 * du poste) n'écrit AUCUN fichier de codes, alors que les salons déjà créés
 * ont joué de vrais faits de partie. Si `rooms-<phase>-*.txt` manque, la
 * liste se reprend du journal de la console, puis suit le même chemin, avant
 * l'archivage :
 *   grep -o 'loadtest-room [A-Za-z0-9]*' \
 *        tests/Load/.data/console-<phase>-<n>.log \
 *     | cut -d' ' -f2 > tests/Load/.data/rooms-<phase>-reprise.txt
 * Un salon dont une partie court encore est écarté par la commande : la
 * relancer une fois ces parties terminées.
 *
 * Chaque joueur simulé est un vrai client (§ 16.1) : cookie de siège, jeton
 * CSRF lu dans le cookie `XSRF-TOKEN`, poignée de main d'horloge sur
 * `clock.show`, battement, WebSocket Reverb (protocole Pusher 7) et
 * autorisation des canaux de présence et privé par `/broadcasting/auth`,
 * chargement de chaque image à son `fetchNotBefore`, soumissions au rythme du
 * réglage, clic d'une proposition du QCM quand `seat.choices` arrive. Toutes
 * les valeurs de jeu sont lues sur le serveur (props du lobby) ; les rares
 * constantes qu'aucune prop ne publie sont des miroirs, que
 * `tests/Feature/Deploy/LoadScenarioTest.php` compare au code.
 *
 * Seuils (§ 16.4, critère 1) : aucune 5xx, aucun refus hors des 429 et des
 * clôtures (409 `closed`), aucun échec de WebSocket ni de souscription ;
 * latence des soumissions p95 ≤ 250 ms et p99 ≤ 1 s ; retard de diffusion des
 * frontières (`serverNow` de `tier.opened` − `opensAt`) p95 ≤ tierGraceMs et
 * maximum ≤ transitionMaxWaitMs + tierGraceMs, et AUCUNE frontière perdue
 * (`tf_events_missed`, détaillé par événement) ; chaque image servie, chaque
 * manche lancée close et révélée. Une diffusion en échec est avalée par le
 * serveur (`ShouldRescue`) et son retard, journalisé avant l'envoi, paraît à
 * l'heure : seul le décompte des frontières perdues la voit. Phase B : le
 * premier point seulement. Le reste du critère 1 (file PHP-FPM, page de
 * statut du pool [à confirmer au relevé du VPS] ; `GamesInProgress::count()`
 * revenu à 0 ; cgroups ; Redis) et le critère 2 côté machine se relèvent sur
 * le serveur.
 */

import { SharedArray } from 'k6/data';
import exec from 'k6/execution';
import { parseHTML } from 'k6/html';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import {
    clearInterval,
    clearTimeout,
    setInterval,
    setTimeout,
} from 'k6/timers';
import { WebSocket } from 'k6/websockets';
import { sleep } from 'k6';

// ─── Miroirs du serveur, comparés au code par LoadScenarioTest ────────────

/** `LoadTestForgetCommand::SYNTHETIC_NICKNAME_PREFIX`, suivi d'un numéro. */
const SYNTHETIC_PREFIX = 'k6-';

/** `SettingPresetKey::Classic` : le preset du scénario A (§ 16.2). */
const PRESET_KEY = 'classic';

/**
 * `PlatformLimits::DEFAULT_ROOM_SEATS` : taille d'un salon plein en A2 et B.
 * Le nombre de joueurs simulés se fixe avant tout appel ; `setup()` le
 * compare à `limits.roomSeats` et s'arrête s'ils diffèrent (SEATS=…).
 */
const DEFAULT_ROOM_SEATS = 12;

/** `PlatformLimits::DEFAULT_TIER_GRACE_MS`, constante d'instance non surchargeable. */
const TIER_GRACE_MS = 300;

/** `EngineConstants::DEFAULT_TRANSITION_MAX_WAIT_MS` (surchargeable : TRANSITION_MAX_WAIT_MS). */
const DEFAULT_TRANSITION_MAX_WAIT_MS = 1000;

/** `FRAME_RETRY_DELAY_MS` et `FRAME_MAX_ATTEMPTS` de `lib/game/frame-loader.ts`. */
const FRAME_RETRY_DELAY_MS = 250;
const FRAME_MAX_ATTEMPTS = 8;

// ─── Critères d'exploitation (§ 16.4), pas des règles de jeu ─────────────

const ANSWER_P95_MS = 250;
const ANSWER_P99_MS = 1000;
const NEIGHBOUR_TTFB_FACTOR = 1.2;

// ─── Paramètres du banc (défauts de § 16.2 et § 16.3) ─────────────────────

const PHASES = {
    A: {
        players: [7, 8],
        fullRooms: false,
        maxRate: false,
        persistent: false,
        waves: 'auto',
        criteria: 'full',
    },
    A2: {
        players: null,
        fullRooms: true,
        maxRate: false,
        persistent: true,
        waves: 1,
        criteria: 'full',
    },
    B: {
        players: null,
        fullRooms: true,
        maxRate: true,
        persistent: true,
        waves: 1,
        criteria: 'burst',
    },
};

const PHASE = (__ENV.PHASE || 'A').trim();
const NEIGHBOURS_PHASE = 'neighbours';

if (PHASE !== NEIGHBOURS_PHASE && PHASES[PHASE] === undefined) {
    throw new Error(
        `PHASE inconnue « ${PHASE} » : A, A2, B ou ${NEIGHBOURS_PHASE}.`,
    );
}

const PROFILE = PHASES[PHASE] || null;
const ROOMS = intEnv('ROOMS', 20);
const SEATS = intEnv('SEATS', DEFAULT_ROOM_SEATS);
const RAMP_MS = intEnv('RAMP_MINUTES', 5) * 60000;
const PLATEAU_MS = intEnv('PLATEAU_MINUTES', 15) * 60000;
const BURST_MS = intEnv('BURST_MINUTES', 5) * 60000;
const MAX_WAVES = intEnv('MAX_WAVES', 4);
const MAX_DURATION = __ENV.MAX_DURATION || '90m';
const TRANSITION_MAX_WAIT_MS = intEnv(
    'TRANSITION_MAX_WAIT_MS',
    DEFAULT_TRANSITION_MAX_WAIT_MS,
);
const DATA_DIR = (__ENV.LOAD_DATA_DIR || 'tests/Load/.data').replace(
    /\/+$/,
    '',
);

const NEIGHBOUR_URLS = listEnv('NEIGHBOUR_URLS');
const NEIGHBOUR_BASELINE_P95_MS = listEnv('NEIGHBOUR_BASELINE_P95_MS').map(
    Number,
);
const NEIGHBOUR_MINUTES = intEnv('NEIGHBOUR_MINUTES', 10);
const NEIGHBOUR_INTERVAL_S = intEnv('NEIGHBOUR_INTERVAL_SECONDS', 10);

/** Relevé du lobby par l'hôte, pour décider du lancement (sous `game-read`). */
const LOBBY_POLL_MS = 5000;

/** Au-delà de l'échéance d'arrivée, l'hôte lance avec les présents. */
const LAUNCH_GRACE_MS = 90000;

/** Sans lancement si longtemps après l'échéance, le siège abandonne. */
const LAUNCH_GIVE_UP_MS = 180000;

const JOIN_MAX_ATTEMPTS = 8;
const RETRY_FALLBACK_MS = 10000;
const CONNECT_TIMEOUT_MS = 15000;
const MAX_IN_FLIGHT = 25;

/** Diffusions dont la perte est comptée (`tf_events_missed`, tag `name`). */
const MISSABLE_EVENTS = ['round.scheduled', 'tier.opened', 'game.ended'];

/** Attente, par pas, de la soumission en vol avant le clic du QCM. */
const SUBMISSION_WAIT_MS = 50;
const REQUEST_TIMEOUT = '30s';

const BASE = parseBase(__ENV.K6_BASE_URL || '');

if (PHASE !== NEIGHBOURS_PHASE && BASE === null) {
    throw new Error(
        'K6_BASE_URL manquante ou invalide : https://<DOMAINE>, jamais un littéral du dépôt.',
    );
}

if (PHASE === NEIGHBOURS_PHASE && NEIGHBOUR_URLS.length === 0) {
    throw new Error(
        'NEIGHBOUR_URLS manquante : les pages d’accueil des sites voisins, séparées par des virgules.',
    );
}

const TITLES =
    PHASE === NEIGHBOURS_PHASE
        ? []
        : new SharedArray('titles', () => {
              let raw;

              try {
                  raw = open('./.data/titles.txt');
              } catch (error) {
                  throw new Error(
                      `tests/Load/.data/titles.txt illisible (${error}) : extraire d’abord la liste des titres publiés.`,
                  );
              }

              return raw
                  .split(/\r?\n/)
                  .map((line) => line.trim())
                  .filter((line) => line !== '');
          });

if (PHASE !== NEIGHBOURS_PHASE && TITLES.length === 0) {
    throw new Error('tests/Load/.data/titles.txt est vide.');
}

const SLOTS = PROFILE === null ? [] : buildSlots();

// ─── Métriques ────────────────────────────────────────────────────────────

const metrics = {
    http5xx: new Counter('tf_http_5xx'),
    httpNetwork: new Counter('tf_http_network_errors'),
    unexpected: new Counter('tf_unexpected'),
    wsFailures: new Counter('tf_ws_failures'),
    subscribeFailures: new Counter('tf_subscribe_failures'),
    joinFailures: new Counter('tf_join_failures'),
    joinThrottled: new Counter('tf_join_throttled'),
    launchFailures: new Counter('tf_launch_failures'),
    heartbeatFailures: new Counter('tf_heartbeat_failures'),
    answers: new Counter('tf_answers'),
    answerDuration: new Trend('tf_answer_duration', true),
    answerAccepted: new Counter('tf_answer_accepted'),
    answerRejected: new Counter('tf_answer_rejected'),
    answerClosed: new Counter('tf_answer_closed'),
    answerThrottled: new Counter('tf_answer_throttled'),
    answersSkipped: new Counter('tf_answers_skipped'),
    tierLag: new Trend('tf_tier_lag_ms', true),
    eventsMissed: new Counter('tf_events_missed'),
    graceMismatch: new Counter('tf_grace_mismatch'),
    frameDuration: new Trend('tf_frame_duration', true),
    frameFailures: new Counter('tf_frame_failures'),
    gamesJoined: new Counter('tf_games_joined'),
    gamesEnded: new Counter('tf_games_ended'),
    gamesUnfinished: new Counter('tf_games_unfinished'),
    gamesPaused: new Counter('tf_games_paused'),
    roundsRevealed: new Counter('tf_rounds_revealed'),
    roundsUnrevealed: new Counter('tf_rounds_unrevealed'),
    neighbourTtfb: new Trend('tf_neighbour_ttfb', true),
    neighbourFailures: new Counter('tf_neighbour_failures'),
};

// ─── Options ──────────────────────────────────────────────────────────────

export const options =
    PHASE === NEIGHBOURS_PHASE
        ? {
              scenarios: {
                  neighbours: {
                      executor: 'constant-vus',
                      vus: 1,
                      duration: `${NEIGHBOUR_MINUTES}m`,
                      exec: 'neighbours',
                  },
              },
              thresholds: neighbourThresholds(),
              summaryTrendStats: ['avg', 'med', 'p(95)', 'max'],
          }
        : {
              setupTimeout: '20m',
              teardownTimeout: '1m',
              scenarios: {
                  players: {
                      executor: 'per-vu-iterations',
                      vus: SLOTS.length,
                      iterations:
                          PROFILE.waves === 'auto' ? MAX_WAVES : PROFILE.waves,
                      maxDuration: MAX_DURATION,
                      gracefulStop: '2m',
                      exec: 'players',
                  },
              },
              thresholds: playerThresholds(),
              summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
          };

function playerThresholds() {
    const thresholds = {
        tf_http_5xx: ['count==0'],
        tf_http_network_errors: ['count==0'],
        tf_unexpected: ['count==0'],
        tf_ws_failures: ['count==0'],
        tf_subscribe_failures: ['count==0'],
        tf_join_failures: ['count==0'],
        tf_launch_failures: ['count==0'],
        tf_games_joined: ['count>0'],
    };

    if (PROFILE !== null && PROFILE.criteria === 'full') {
        Object.assign(thresholds, {
            tf_answer_duration: [
                `p(95)<=${ANSWER_P95_MS}`,
                `p(99)<=${ANSWER_P99_MS}`,
            ],
            tf_tier_lag_ms: [
                `p(95)<=${TIER_GRACE_MS}`,
                `max<=${TRANSITION_MAX_WAIT_MS + TIER_GRACE_MS}`,
            ],
            // Une frontière perdue est un retard sans borne : même point du
            // critère 1 que le retard des frontières, donc hors de la phase B.
            tf_events_missed: ['count==0'],
            tf_grace_mismatch: ['count==0'],
            tf_frame_failures: ['count==0'],
            tf_rounds_unrevealed: ['count==0'],
            tf_games_unfinished: ['count==0'],
        });

        // Une condition par événement : le rapport dit lequel s'est perdu.
        for (const name of MISSABLE_EVENTS) {
            thresholds[`tf_events_missed{name:${name}}`] = ['count==0'];
        }
    }

    return thresholds;
}

function neighbourThresholds() {
    const thresholds = { tf_neighbour_failures: ['count==0'] };

    // Une condition par site, toujours déclarée : sans elle, k6 ne calcule
    // pas le p95 de chaque site, que la mesure de référence doit rendre.
    NEIGHBOUR_URLS.forEach((url, site) => {
        const baseline = NEIGHBOUR_BASELINE_P95_MS[site];

        thresholds[`tf_neighbour_ttfb{site:${site}}`] = [
            Number.isFinite(baseline) && baseline > 0
                ? `p(95)<=${Math.round(baseline * NEIGHBOUR_TTFB_FACTOR)}`
                : 'p(95)>=0',
        ];
    });

    return thresholds;
}

// ─── Préparation : les salons, leur hôte, le profil lu sur le serveur ─────

export function setup() {
    if (PHASE === NEIGHBOURS_PHASE) {
        return { rooms: [] };
    }

    const first = createRoom(nicknameFor(0, hostSlotIndex(0)), true);
    const profile = readProfile(first.props);
    const waves =
        PROFILE.waves === 'auto'
            ? Math.min(
                  MAX_WAVES,
                  Math.max(2, Math.ceil(PLATEAU_MS / profile.gameEstimateMs)),
              )
            : PROFILE.waves;

    if (
        PROFILE.waves === 'auto' &&
        Math.ceil(PLATEAU_MS / profile.gameEstimateMs) > MAX_WAVES
    ) {
        console.warn(
            `Palier tronqué : ${MAX_WAVES} vagues au plus (MAX_WAVES).`,
        );
    }

    const rooms = [first.room];

    for (let index = 1; index < waves * ROOMS; index += 1) {
        const wave = Math.floor(index / ROOMS);

        rooms.push(
            createRoom(nicknameFor(wave, hostSlotIndex(index % ROOMS)), false)
                .room,
        );
    }

    return { t0: Date.now(), waves, rooms, profile };
}

/**
 * Crée un salon comme un hôte réel (`room.store`), applique le preset du
 * scénario (`room.settings.preset`), et rend ce que son hôte simulé reprendra :
 * code, cookies et jeton d'onglet. Aucun avatar : le serveur l'attribue
 * (D55 du 02/10).
 */
function createRoom(nickname, withProps) {
    const jar = new http.CookieJar();

    http.get(`${BASE.url}/`, { jar, tags: { name: 'home' } });

    let res = http.post(
        `${BASE.url}/r`,
        { nickname },
        {
            jar,
            redirects: 0,
            headers: writeHeaders(jar, null, 'application/json'),
            tags: { name: 'room.store' },
        },
    );

    if (res.status === 429) {
        throw new Error(
            'room-create a refusé la création (429) : le limiteur par adresse doit être relevé pour la séance (spec 50 § 17.3).',
        );
    }

    const code = roomCodeFrom(res);

    if (res.status !== 303 || code === null) {
        throw new Error(
            `room.store a répondu ${res.status} au lieu de 303 vers le salon.`,
        );
    }

    // Journal de reprise, dès que le salon existe : le code seul, jamais les
    // cookies (voir « Après chaque phase » en tête).
    console.log(`loadtest-room ${code}`);

    res = http.get(`${BASE.url}/r/${code}`, {
        jar,
        tags: { name: 'room.show' },
    });
    let page = inertiaPage(res);

    if (
        res.status !== 200 ||
        page === null ||
        page.component !== 'game/lobby'
    ) {
        throw new Error(
            `room.show a répondu ${res.status} pour le salon créé.`,
        );
    }

    const seatToken = page.props.seatToken;

    res = http.post(
        `${BASE.url}/r/${code}/settings/preset`,
        { preset: PRESET_KEY },
        {
            jar,
            redirects: 0,
            headers: writeHeaders(jar, seatToken, 'text/html'),
            tags: { name: 'room.settings.preset' },
        },
    );

    if (res.status !== 302 && res.status !== 303) {
        throw new Error(`room.settings.preset a répondu ${res.status}.`);
    }

    if (withProps) {
        res = http.get(`${BASE.url}/r/${code}`, {
            jar,
            headers: { 'X-Seat-Token': seatToken },
            tags: { name: 'room.show' },
        });
        page = inertiaPage(res);
    }

    return {
        room: { code, seatToken, cookies: exportCookies(jar) },
        props: page.props,
    };
}

/** Le profil de la séance, lu dans les props du lobby après le preset. */
function readProfile(props) {
    const settings = props.settings.settings;
    const bounds =
        props.bounds.byFramesPerRound[String(settings.framesPerRound)];
    const roundSeconds = settings.tierDurations.reduce(
        (sum, seconds) => sum + seconds,
        0,
    );
    const players = PROFILE.fullRooms ? SEATS : Math.max(...PROFILE.players);

    if (props.maintenance === true) {
        throw new Error('Drainage en cours : aucun lancement ne passera.');
    }

    if (props.settings.pool.blocked) {
        throw new Error(
            `Vivier insuffisant : ${props.settings.pool.count} films pour ${settings.roundsCount} manches.`,
        );
    }

    if (PROFILE.fullRooms && props.limits.roomSeats !== SEATS) {
        throw new Error(
            `limits.roomSeats vaut ${props.limits.roomSeats} : relancer avec SEATS=${props.limits.roomSeats}.`,
        );
    }

    if (players > settings.capacity) {
        throw new Error(
            `Capacité du salon (${settings.capacity}) sous le nombre de joueurs simulés (${players}).`,
        );
    }

    return {
        minConnected: props.launch.minConnected,
        attemptsPerSecond: PROFILE.maxRate
            ? bounds.attemptsPerSecond.max
            : settings.attemptsPerSecond,
        maxAnswerLength: settings.maxAnswerLength,
        gameEstimateMs:
            settings.roundsCount *
            (roundSeconds + settings.revealDuration) *
            1000,
    };
}

// ─── Joueurs ──────────────────────────────────────────────────────────────

export async function players(data) {
    const wave = exec.vu.iterationInScenario;
    const slotIndex = exec.vu.idInTest - 1;
    const slot = SLOTS[slotIndex];

    if (wave >= data.waves || slot === undefined) {
        return;
    }

    const room = data.rooms[wave * ROOMS + slot.room];

    if (wave === 0 && slot.seat > 0) {
        await delay(data.t0 + slot.offsetMs - Date.now());
    }

    await new Seat(data, room, slot, wave, nicknameFor(wave, slotIndex)).play();
}

class Seat {
    constructor(data, room, slot, wave, nickname) {
        this.data = data;
        this.room = room;
        this.slot = slot;
        this.wave = wave;
        this.nickname = nickname;
        this.intervalMs = Math.ceil(1000 / data.profile.attemptsPerSecond);
        this.seatToken = null;
        this.publicId = null;
        this.channels = null;
        this.realtime = null;
        this.isHost = false;
        this.offsetMs = 0;
        this.phase = 'lobby';
        this.done = false;
        this.timers = new Set();
        this.rounds = new Map();
        this.frames = new Set();
        this.currentSeq = null;
        this.inFlight = 0;
        this.burst = null;
        this.burstOver = false;
        this.graceChecked = false;
        this.ws = null;
        this.socketId = null;
        this.subscribed = new Set();
        /** Instant serveur estimé où les deux canaux sont souscrits. */
        this.subscribedAtMs = Infinity;
        /** Une révélation déjà reçue : le siège était abonné avant elle. */
        this.heardReveal = false;
        this.polling = false;
        this.launchedAt = null;
        this.finished = new Promise((resolve) => {
            this.resolveFinished = resolve;
        });
    }

    async play() {
        try {
            if (!(await this.takeSeat()) || !(await this.openPage())) {
                return;
            }

            await this.handshake();

            if (!(await this.connect())) {
                this.finish('socket');

                return;
            }

            this.startHeartbeat();
            this.armLobby();
            await this.finished;
        } finally {
            this.finish('teardown');
        }
    }

    // ── Siège ──

    async takeSeat() {
        if (this.slot.seat === 0) {
            const jar = http.cookieJar();

            for (const [name, value] of Object.entries(this.room.cookies)) {
                jar.set(BASE.url, name, value);
            }

            this.seatToken = this.room.seatToken;

            return true;
        }

        await this.request('GET', '/', null, {
            name: 'home',
            accept: 'text/html',
            xhr: false,
        });

        for (let attempt = 1; attempt <= JOIN_MAX_ATTEMPTS; attempt += 1) {
            const res = await this.request(
                'POST',
                `/r/${this.room.code}/join`,
                { nickname: this.nickname },
                { name: 'room.join', redirects: 0 },
            );

            if (res.status === 303 && roomCodeFrom(res) === this.room.code) {
                return true;
            }

            if (res.status !== 429) {
                metrics.joinFailures.add(1, { status: String(res.status) });

                return false;
            }

            metrics.joinThrottled.add(1);
            await delay(retryAfterMs(res));
        }

        metrics.joinFailures.add(1, { status: '429' });

        return false;
    }

    async openPage() {
        const res = await this.request('GET', `/r/${this.room.code}`, null, {
            name: 'room.show',
            accept: 'text/html',
            xhr: false,
            seat: true,
        });
        const page = inertiaPage(res);

        if (
            res.status !== 200 ||
            page === null ||
            page.component !== 'game/lobby'
        ) {
            metrics.unexpected.add(1, {
                name: 'room.show',
                status: String(res.status),
            });

            return false;
        }

        const state = page.props.state;

        this.seatToken = page.props.seatToken;
        this.realtime = page.props.realtime;
        this.publicId = state.self.publicId;
        this.isHost = state.self.isHost;
        this.channels = state.channels;

        return this.channels !== null;
    }

    // ── Horloge (60 § 2.4) ──

    async handshake() {
        const offsets = [];

        for (let sample = 0; sample < this.realtime.clockSamples; sample += 1) {
            const sentAt = Date.now();
            const res = await this.request('GET', '/clock', null, {
                name: 'clock.show',
            });
            const receivedAt = Date.now();

            if (res.status === 200) {
                offsets.push(
                    parseIsoMs(res.json('serverNow')) -
                        (sentAt + receivedAt) / 2,
                );
            }
        }

        if (offsets.length > 0) {
            offsets.sort((left, right) => left - right);
            this.offsetMs = Math.round(offsets[Math.floor(offsets.length / 2)]);
        }
    }

    serverNow() {
        return Date.now() + this.offsetMs;
    }

    recalibrate(serverNow) {
        const lowerBound = parseIsoMs(serverNow) - Date.now();

        if (Number.isFinite(lowerBound) && lowerBound > this.offsetMs) {
            this.offsetMs = lowerBound;
        }
    }

    // ── Temps réel : Reverb, protocole Pusher 7 ──

    connect() {
        return new Promise((resolve) => {
            let settled = false;
            const settle = (ok) => {
                if (!settled) {
                    settled = true;
                    resolve(ok);
                }
            };

            this.settleConnect = settle;
            this.later(CONNECT_TIMEOUT_MS, () => {
                if (!settled) {
                    this.socketFailed('timeout');
                }
            });

            const ws = new WebSocket(this.socketUrl(), null, {
                headers: { Origin: BASE.origin },
                tags: { name: 'reverb' },
            });

            this.ws = ws;
            ws.onmessage = (event) => this.onSocketMessage(event.data);
            ws.onerror = () => this.socketFailed('error');
            ws.onclose = () => this.socketFailed('close');
        });
    }

    socketUrl() {
        const scheme = this.realtime.scheme || BASE.scheme;
        const host = this.realtime.host || BASE.host;
        const port = this.realtime.port || BASE.port;

        return `${scheme === 'https' ? 'wss' : 'ws'}://${host}${port ? `:${port}` : ''}/app/${this.realtime.key}?protocol=7&client=js&version=8.4.0&flash=false`;
    }

    socketFailed(reason) {
        if (this.done) {
            return;
        }

        metrics.wsFailures.add(1, { reason });
        this.settleConnect(false);
        this.finish('socket');
    }

    send(message) {
        if (this.ws !== null && !this.done) {
            this.ws.send(JSON.stringify(message));
        }
    }

    onSocketMessage(raw) {
        const message = parseJson(raw);

        if (message === null || typeof message.event !== 'string') {
            return;
        }

        // Pusher transporte la charge en chaîne JSON.
        const data =
            typeof message.data === 'string'
                ? parseJson(message.data)
                : message.data;

        switch (message.event) {
            case 'pusher:connection_established':
                if (data === null || typeof data.socket_id !== 'string') {
                    this.socketFailed('handshake');

                    return;
                }

                this.socketId = data.socket_id;
                this.every((data.activity_timeout || 30) * 1000, () =>
                    this.send({ event: 'pusher:ping', data: {} }),
                );
                void this.subscribeAll();

                return;
            case 'pusher:ping':
                this.send({ event: 'pusher:pong', data: {} });

                return;
            case 'pusher_internal:subscription_succeeded':
                this.subscribed.add(message.channel);

                if (this.subscribed.size === 2) {
                    this.subscribedAtMs = this.serverNow();
                    this.settleConnect(true);
                }

                return;
            case 'pusher:subscription_error':
            case 'pusher:error':
                metrics.subscribeFailures.add(1, { event: message.event });
                this.settleConnect(false);

                return;
            default:
                if (!message.event.startsWith('pusher')) {
                    this.onGameEvent(message.event, data);
                }
        }
    }

    async subscribeAll() {
        for (const channel of [
            `presence-${this.channels.room}`,
            `private-${this.channels.seat}`,
        ]) {
            const res = await this.request(
                'POST',
                '/broadcasting/auth',
                { socket_id: this.socketId, channel_name: channel },
                { name: 'broadcasting.auth' },
            );

            if (res.status !== 200) {
                metrics.subscribeFailures.add(1, {
                    status: String(res.status),
                });
                this.settleConnect(false);

                return;
            }

            const auth = res.json();

            this.send({
                event: 'pusher:subscribe',
                data: {
                    channel,
                    auth: auth.auth,
                    channel_data: auth.channel_data,
                },
            });
        }
    }

    // ── Battement (60 § 13.1) ──

    startHeartbeat() {
        let busy = false;
        const beat = async () => {
            if (busy || this.done) {
                return;
            }

            busy = true;

            try {
                const res = await this.request(
                    'POST',
                    `/r/${this.room.code}/heartbeat`,
                    null,
                    {
                        name: 'room.heartbeat',
                        seat: true,
                    },
                );

                if (res.status === 403) {
                    metrics.unexpected.add(1, {
                        name: 'room.heartbeat',
                        status: '403',
                    });
                    this.finish('refused');
                } else if (res.status !== 204) {
                    metrics.heartbeatFailures.add(1, {
                        status: String(res.status),
                    });
                }
            } finally {
                busy = false;
            }
        };

        this.later(0, beat);
        this.every(this.realtime.heartbeatIntervalMs, beat);
    }

    // ── Lobby : l'hôte lance quand son salon est au complet ──

    armLobby() {
        const arrival = this.wave === 0 ? this.data.t0 + RAMP_MS : Date.now();

        this.launchDeadline = arrival + LAUNCH_GRACE_MS;
        this.giveUpAt = this.launchDeadline + LAUNCH_GIVE_UP_MS;
        this.every(LOBBY_POLL_MS, () => void this.pollLobby());
    }

    async pollLobby() {
        if (this.phase !== 'lobby' || this.polling || this.done) {
            return;
        }

        if (Date.now() >= this.giveUpAt) {
            metrics.launchFailures.add(1);
            this.finish('launch');

            return;
        }

        // Tout siège relit le lobby passé l'échéance : le rôle d'hôte a pu
        // passer à un autre siège (réparation d'hôte, 50 § 11.1).
        if (!this.isHost && Date.now() < this.launchDeadline) {
            return;
        }

        this.polling = true;

        try {
            const res = await this.request(
                'GET',
                `/r/${this.room.code}/state`,
                null,
                {
                    name: 'room.state',
                    seat: true,
                },
            );

            if (res.status !== 200 || this.phase !== 'lobby') {
                return;
            }

            const state = res.json();

            this.recalibrate(state.serverNow);
            this.isHost = state.self.isHost;

            if (state.status === 'running' || state.status === 'paused') {
                this.enterGame(state.round);

                return;
            }

            const connected = state.seats.filter(
                (seat) => seat.connection === 'connected' && !seat.kicked,
            ).length;
            const ready =
                connected >= this.slot.players ||
                (Date.now() >= this.launchDeadline &&
                    connected >= this.data.profile.minConnected);

            if (this.isHost && ready) {
                const launch = await this.request(
                    'POST',
                    `/r/${this.room.code}/launch`,
                    null,
                    {
                        name: 'room.launch',
                        seat: true,
                        redirects: 0,
                        accept: 'text/html',
                    },
                );

                if (launch.status !== 302 && launch.status !== 303) {
                    metrics.unexpected.add(1, {
                        name: 'room.launch',
                        status: String(launch.status),
                    });
                }
            }
        } finally {
            this.polling = false;
        }
    }

    enterGame(round) {
        if (this.phase !== 'lobby') {
            return;
        }

        this.phase = 'playing';
        this.launchedAt = Date.now();
        metrics.gamesJoined.add(1);

        if (round) {
            this.scheduleRound(round, null);
            (round.images || []).forEach((image) =>
                this.scheduleFrame(round.sequenceIndex, image),
            );
        }

        // Garde-fou : une partie dure au plus deux fois son estimation.
        this.later(
            this.data.profile.gameEstimateMs * 2 + LAUNCH_GIVE_UP_MS,
            () => void this.gameOverdue(),
        );
    }

    async gameOverdue() {
        if (this.done) {
            return;
        }

        const res = await this.request(
            'GET',
            `/r/${this.room.code}/state`,
            null,
            { name: 'room.state', seat: true },
        );
        const status = res.status === 200 ? res.json('status') : null;

        if (status === 'completed' || status === 'interrupted') {
            // Partie close sans que `game.ended` soit arrivé : perdu.
            metrics.eventsMissed.add(1, { name: 'game.ended' });
            this.finish('ended');

            return;
        }

        metrics.gamesUnfinished.add(1);
        this.finish('overdue');
    }

    // ── Événements du salon et du siège (contrat C7 § 2.3) ──

    onGameEvent(name, payload) {
        if (this.done || payload === null || typeof payload !== 'object') {
            return;
        }

        if (typeof payload.serverNow === 'string') {
            this.recalibrate(payload.serverNow);
        }

        switch (name) {
            case 'host.changed':
                this.isHost = payload.hostPublicId === this.publicId;

                return;
            case 'game.launched':
                this.enterGame(null);

                return;
            case 'round.scheduled':
                this.enterGame(null);
                this.scheduleRound(payload.round, payload.image);

                return;
            case 'tier.opened':
                this.onTierOpened(payload);

                return;
            case 'seat.choices':
                void this.clickChoice(payload);

                return;
            case 'round.closed':
                this.onRoundClosed(payload);

                return;
            case 'round.revealed':
                this.onRoundRevealed(payload);

                return;
            case 'round.cancelled':
                this.round(payload.sequenceIndex).cancelled = true;

                return;
            case 'game.paused':
                metrics.gamesPaused.add(1);

                return;
            case 'game.ended':
                this.finish('ended');

                return;
            case 'seat.superseded':
            case 'seat.kicked':
            case 'room.archived':
                metrics.unexpected.add(1, { name });
                this.finish(name);

                return;
            default:
        }
    }

    round(sequenceIndex) {
        let round = this.rounds.get(sequenceIndex);

        if (round === undefined) {
            round = {
                // Chronologie reçue : `round.scheduled` ou resynchronisation.
                scheduled: false,
                // Paliers reçus par `tier.opened`.
                tiers: new Set(),
                started: false,
                closed: false,
                revealed: false,
                cancelled: false,
                locked: false,
                textDone: false,
                choiceSent: false,
                startTimer: null,
            };
            this.rounds.set(sequenceIndex, round);
        }

        return round;
    }

    scheduleRound(timeline, image) {
        const round = this.round(timeline.sequenceIndex);

        round.scheduled = true;

        // `round.scheduled` peut être réémis tant que la manche est
        // programmée : le dernier reçu l'emporte (C7 § 4.2).
        if (!round.started) {
            this.clear(round.startTimer);
            round.startTimer = this.at(parseIsoMs(timeline.startsAt), () =>
                this.startRound(timeline.sequenceIndex),
            );
        }

        if (image) {
            this.scheduleFrame(timeline.sequenceIndex, image);
        }
    }

    onTierOpened(payload) {
        const round = this.round(payload.sequenceIndex);

        metrics.tierLag.add(
            parseIsoMs(payload.serverNow) - parseIsoMs(payload.opensAt),
        );
        round.tiers.add(payload.tierIndex);

        if (payload.tierIndex === 1) {
            this.startRound(payload.sequenceIndex);
        }

        if (payload.next) {
            this.scheduleFrame(payload.sequenceIndex, payload.next);
        }
    }

    onRoundClosed(payload) {
        this.round(payload.sequenceIndex).closed = true;

        if (!this.graceChecked) {
            this.graceChecked = true;

            if (
                parseIsoMs(payload.revealStartsAt) -
                    parseIsoMs(payload.endedAt) !==
                TIER_GRACE_MS
            ) {
                metrics.graceMismatch.add(1);
            }
        }
    }

    /**
     * Frontières perdues (§ 16.4, critère 1). Une diffusion en échec est
     * avalée par le serveur (`ShouldRescue`), et son retard est journalisé au
     * canal `game` avant l'envoi : ni ce journal ni `tf_tier_lag_ms` ne la
     * voient. Le décompte se fait à la révélation, dont `images` liste les
     * paliers que le serveur a OUVERTS (`served_at` non nul) : chacun doit
     * être arrivé par son `tier.opened`. Pas à `round.closed` : un rattrapage
     * qui exécute clôture et révélation dans le même passage n'émet que la
     * seconde (60 § 4.4), légitimement, dans la tolérance du retard des
     * frontières ; une révélation perdue est comptée, elle, par
     * `tf_rounds_unrevealed`.
     *
     * Seul ce qui est parti APRÈS l'abonnement du siège est attendu : un
     * siège est compté connecté dès sa prise, et l'hôte peut lancer avant la
     * fin de sa poignée de main. D'où la borne `subscribedAtMs` (palier ouvert
     * après `fetchNotBefore`) et, pour `round.scheduled`, une révélation déjà
     * entendue : celui de la manche k part avec la révélation de k − 1, celui
     * de la manche 1 au lancement, que l'abonnement peut suivre.
     */
    onRoundRevealed(payload) {
        const round = this.round(payload.sequenceIndex);

        if (round.revealed) {
            return;
        }

        round.revealed = true;
        metrics.roundsRevealed.add(1);

        if (!round.scheduled && this.heardReveal) {
            metrics.eventsMissed.add(1, { name: 'round.scheduled' });
        }

        this.heardReveal = true;

        const opened = Array.isArray(payload.images) ? payload.images : [];

        for (const image of opened) {
            if (
                !round.tiers.has(image.tierIndex) &&
                parseIsoMs(image.fetchNotBefore) >= this.subscribedAtMs
            ) {
                metrics.eventsMissed.add(1, { name: 'tier.opened' });
            }
        }
    }

    // ── Images : chacune à son `fetchNotBefore` (C8) ──

    scheduleFrame(sequenceIndex, image) {
        const key = `${sequenceIndex}:${image.tierIndex}`;

        if (this.frames.has(key)) {
            return;
        }

        this.frames.add(key);
        this.at(
            parseIsoMs(image.fetchNotBefore),
            () => void this.fetchFrame(image.url, 1),
        );
    }

    async fetchFrame(url, attempt) {
        if (this.done) {
            return;
        }

        const res = await this.request('GET', url, null, {
            name: 'frame.serve',
            accept: 'image/webp,image/*',
            xhr: false,
            responseType: 'none',
        });

        if (res.status === 200) {
            metrics.frameDuration.add(res.timings.duration);

            return;
        }

        const retriable =
            res.status === 404 ||
            res.status === 429 ||
            res.status === 0 ||
            res.status >= 500;

        if (retriable && attempt < FRAME_MAX_ATTEMPTS) {
            const wait =
                res.status === 429 ? retryAfterMs(res) : FRAME_RETRY_DELAY_MS;

            this.later(
                Math.max(FRAME_RETRY_DELAY_MS, wait),
                () => void this.fetchFrame(url, attempt + 1),
            );

            return;
        }

        metrics.frameFailures.add(1, { status: String(res.status) });
    }

    // ── Saisie (70 § 7) ──

    startRound(sequenceIndex) {
        const round = this.round(sequenceIndex);

        if (round.started || this.done) {
            return;
        }

        round.started = true;
        this.currentSeq = sequenceIndex;

        if (PROFILE.persistent) {
            this.startBurst();
        } else {
            void this.submitLoop(sequenceIndex);
        }
    }

    /** Joueur coopératif : une soumission à la fois, au rythme du réglage. */
    async submitLoop(sequenceIndex) {
        const round = this.round(sequenceIndex);

        while (
            !this.done &&
            !round.closed &&
            !round.locked &&
            !round.textDone
        ) {
            const startedAt = Date.now();

            if (
                (await this.submit('text', sequenceIndex, this.guess())) ===
                'stop'
            ) {
                round.textDone = true;

                return;
            }

            await delay(this.intervalMs - (Date.now() - startedAt));
        }
    }

    /** A2 et B : soumission continue, sans s'arrêter aux refus. */
    startBurst() {
        if (this.burst !== null || this.burstOver) {
            return;
        }

        const endsAt = Date.now() + BURST_MS;

        this.burst = this.every(this.intervalMs, () => {
            if (Date.now() >= endsAt) {
                this.clear(this.burst);
                this.burstOver = true;

                return;
            }

            if (this.inFlight >= MAX_IN_FLIGHT) {
                metrics.answersSkipped.add(1);

                return;
            }

            void this.submit('text', this.currentSeq, this.guess());
        });
    }

    /** Un titre publié tiré au hasard, à la longueur maximale de la saisie. */
    guess() {
        return pick(TITLES).slice(0, this.data.profile.maxAnswerLength);
    }

    async clickChoice(payload) {
        const round = this.round(payload.sequenceIndex);

        if (
            round.locked ||
            round.choiceSent ||
            !Array.isArray(payload.choices)
        ) {
            return;
        }

        round.choiceSent = true;

        // Le QCM remplace la saisie libre à l'écran ; le joueur coopératif
        // n'envoie qu'une soumission à la fois (70 § 16).
        if (!PROFILE.persistent) {
            round.textDone = true;

            while (this.inFlight > 0 && !this.done) {
                await delay(SUBMISSION_WAIT_MS);
            }
        }

        await this.submit(
            'choice',
            payload.sequenceIndex,
            pick(payload.choices),
        );
    }

    /** Une soumission ; rend `continue` ou `stop` pour le joueur coopératif. */
    async submit(kind, sequenceIndex, value) {
        const body =
            kind === 'text'
                ? { round: sequenceIndex, answer: value }
                : { round: sequenceIndex, choice: value };

        this.inFlight += 1;

        let res;

        try {
            res = await this.request(
                'POST',
                `/seat/${this.publicId}/${kind === 'text' ? 'answer' : 'choice'}`,
                JSON.stringify(body),
                {
                    name:
                        kind === 'text'
                            ? 'round.answer.store'
                            : 'round.choice.store',
                    seat: true,
                    json: true,
                },
            );
        } finally {
            this.inFlight -= 1;
        }

        metrics.answers.add(1, { kind });

        if (res.status === 200 || res.status === 409) {
            metrics.answerDuration.add(res.timings.duration, { kind });
        }

        const body409or200 =
            res.status === 200 || res.status === 409 ? safeJson(res) : null;

        if (
            res.status === 200 &&
            body409or200 !== null &&
            body409or200.result === 'accepted'
        ) {
            metrics.answerAccepted.add(1, { kind });
            this.round(sequenceIndex).locked = true;

            return 'stop';
        }

        if (
            res.status === 200 &&
            body409or200 !== null &&
            body409or200.result === 'rejected'
        ) {
            metrics.answerRejected.add(1, { kind });

            return body409or200.inputState === 'open' &&
                body409or200.attemptsLeft > 0
                ? 'continue'
                : 'stop';
        }

        if (
            res.status === 409 &&
            body409or200 !== null &&
            body409or200.result === 'closed'
        ) {
            metrics.answerClosed.add(1, { kind });

            return 'stop';
        }

        if (res.status === 429) {
            metrics.answerThrottled.add(1, { kind });

            return 'continue';
        }

        if (res.status === 0 || res.status >= 500) {
            return 'continue';
        }

        metrics.unexpected.add(1, {
            name: `round.${kind}`,
            status: String(res.status),
        });

        return 'stop';
    }

    // ── Transport ──

    async request(
        method,
        path,
        body,
        {
            name,
            accept = 'application/json',
            xhr = true,
            seat = false,
            json = false,
            redirects = null,
            responseType = null,
        },
    ) {
        const headers = { Accept: accept };

        if (xhr) {
            headers['X-Requested-With'] = 'XMLHttpRequest';
        }

        if (method !== 'GET') {
            const xsrf = xsrfToken(http.cookieJar());

            if (xsrf !== null) {
                headers['X-XSRF-TOKEN'] = xsrf;
            }
        }

        if (json) {
            headers['Content-Type'] = 'application/json';
        }

        if (seat && this.seatToken !== null) {
            headers['X-Seat-Token'] = this.seatToken;
        }

        const params = { headers, tags: { name }, timeout: REQUEST_TIMEOUT };

        if (redirects !== null) {
            params.redirects = redirects;
        }

        if (responseType !== null) {
            params.responseType = responseType;
        }

        const res = await http.asyncRequest(
            method,
            path.startsWith('http') ? path : `${BASE.url}${path}`,
            body,
            params,
        );

        track(res, name);

        return res;
    }

    // ── Minuteurs, tous retirés en fin de siège ──

    later(ms, callback) {
        const id = setTimeout(
            () => {
                this.timers.delete(id);
                callback();
            },
            Math.max(0, ms),
        );

        this.timers.add(id);

        return id;
    }

    at(serverMs, callback) {
        return this.later(serverMs - this.serverNow(), callback);
    }

    every(ms, callback) {
        const id = setInterval(callback, ms);

        this.timers.add(id);

        return id;
    }

    clear(id) {
        if (id !== null && id !== undefined) {
            clearTimeout(id);
            clearInterval(id);
            this.timers.delete(id);
        }
    }

    finish(reason) {
        if (this.done) {
            return;
        }

        this.done = true;

        for (const id of this.timers) {
            clearTimeout(id);
            clearInterval(id);
        }

        this.timers.clear();

        if (reason === 'ended') {
            metrics.gamesEnded.add(1);

            // Manche ouverte (un palier reçu, ou sa clôture : un `tier.opened`
            // perdu ne doit pas masquer une révélation perdue).
            for (const round of this.rounds.values()) {
                if (
                    (round.tiers.size > 0 || round.closed) &&
                    !round.revealed &&
                    !round.cancelled
                ) {
                    metrics.roundsUnrevealed.add(1);
                }
            }
        }

        if (this.ws !== null) {
            try {
                this.ws.close();
            } catch {
                // Déjà fermé.
            }
        }

        this.resolveFinished();
    }
}

// ─── Voisins (critère 2) ──────────────────────────────────────────────────

export function neighbours() {
    NEIGHBOUR_URLS.forEach((url, site) => {
        const tags = { name: 'neighbour', site: String(site) };
        const res = http.get(url, {
            tags,
            redirects: 0,
            timeout: REQUEST_TIMEOUT,
        });

        if (res.status === 0 || res.status >= 500) {
            metrics.neighbourFailures.add(1, tags);
        } else {
            metrics.neighbourTtfb.add(res.timings.waiting, tags);
        }
    });

    sleep(NEIGHBOUR_INTERVAL_S);
}

// ─── Résultats : codes des salons et résumé sous tests/Load/.data ─────────

export function handleSummary(data) {
    const instant = new Date().toISOString();
    const stamp = instant.replace(/[:.]/g, '-');
    const setupData = data.setup_data || {};
    const rooms = Array.isArray(setupData.rooms)
        ? setupData.rooms.map((room) => room.code)
        : [];
    const files = {};
    const summary = Object.assign({}, data);

    // Les cookies des hôtes simulés restent hors de tout fichier.
    delete summary.setup_data;

    if (rooms.length > 0) {
        files[`${DATA_DIR}/rooms-${PHASE}-${stamp}.txt`] =
            `# TripleFrames — test de charge, phase ${PHASE}, ${instant}\n` +
            '# Avant l’archivage des salons (24 h), sur le serveur : "$PHP" artisan loadtest:forget <ce fichier> (PHP=/opt/plesk/php/8.4/bin/php)\n' +
            `${rooms.join('\n')}\n`;
    }

    files[`${DATA_DIR}/summary-${PHASE}-${stamp}.json`] = JSON.stringify(
        summary,
        null,
        2,
    );
    files.stdout = report(data, rooms.length);

    return files;
}

function report(data, roomCount) {
    const lines = [
        `\nTripleFrames — phase ${PHASE}, salons créés : ${roomCount}\n`,
    ];

    for (const [name, metric] of Object.entries(data.metrics).sort(
        ([left], [right]) => left.localeCompare(right),
    )) {
        if (!name.startsWith('tf_')) {
            continue;
        }

        const values = Object.entries(metric.values)
            .map(([key, value]) => `${key}=${Math.round(value * 100) / 100}`)
            .join(' ');
        const thresholds = Object.entries(metric.thresholds || {})
            .map(
                ([condition, result]) =>
                    `${result.ok ? 'ok' : 'ÉCHEC'} ${condition}`,
            )
            .join(', ');

        lines.push(
            `  ${name.padEnd(36)} ${values}${thresholds === '' ? '' : `  [${thresholds}]`}`,
        );
    }

    return `${lines.join('\n')}\n`;
}

// ─── Outils ───────────────────────────────────────────────────────────────

function intEnv(name, fallback) {
    const raw = __ENV[name];

    if (raw === undefined || raw === '') {
        return fallback;
    }

    const value = Number(raw);

    if (!Number.isInteger(value) || value < 1) {
        throw new Error(`${name} doit être un entier au moins égal à 1.`);
    }

    return value;
}

function listEnv(name) {
    return (__ENV[name] || '')
        .split(',')
        .map((item) => item.trim())
        .filter((item) => item !== '');
}

function parseBase(raw) {
    const match = /^(https?):\/\/([^/:?#]+)(?::(\d+))?\/?$/.exec(raw.trim());

    if (match === null) {
        return null;
    }

    const [, scheme, host, port] = match;
    const origin = `${scheme}://${host}${port ? `:${port}` : ''}`;

    return {
        url: origin,
        origin,
        scheme,
        host,
        port: port ? Number(port) : null,
    };
}

/**
 * Les joueurs d'une vague, salon par salon : l'hôte (siège 0) puis les
 * autres ; en A, 7 et 8 joueurs en alternance. Les arrivées de la première
 * vague sont réparties sur la montée, salons entrelacés.
 */
function buildSlots() {
    const slots = [];

    for (let room = 0; room < ROOMS; room += 1) {
        const count = PROFILE.fullRooms
            ? SEATS
            : PROFILE.players[room % PROFILE.players.length];

        for (let seat = 0; seat < count; seat += 1) {
            slots.push({ room, seat, players: count, offsetMs: 0 });
        }
    }

    const joiners = slots
        .filter((slot) => slot.seat > 0)
        .sort(
            (left, right) => left.seat - right.seat || left.room - right.room,
        );

    joiners.forEach((slot, rank) => {
        slot.offsetMs = Math.floor(((rank + 1) * RAMP_MS) / joiners.length);
    });

    return slots;
}

function hostSlotIndex(room) {
    return SLOTS.findIndex((slot) => slot.room === room && slot.seat === 0);
}

/** Pseudo synthétique, unique dans l'exécution : `k6-` suivi d'un numéro. */
function nicknameFor(wave, slotIndex) {
    return `${SYNTHETIC_PREFIX}${wave * SLOTS.length + slotIndex + 1}`;
}

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, Math.max(0, ms)));
}

function parseIsoMs(value) {
    return Date.parse(value);
}

function parseJson(text) {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

function safeJson(res) {
    try {
        return res.json();
    } catch {
        return null;
    }
}

function retryAfterMs(res) {
    const seconds = Number(res.headers['Retry-After']);

    return Number.isFinite(seconds) && seconds > 0
        ? seconds * 1000
        : RETRY_FALLBACK_MS;
}

/** La page Inertia d'une réponse HTML : `<script data-page>` (Inertia 3). */
function inertiaPage(res) {
    if (typeof res.body !== 'string' || res.body === '') {
        return null;
    }

    try {
        return JSON.parse(parseHTML(res.body).find('script[data-page]').text());
    } catch {
        return null;
    }
}

/** Le code du salon visé par une redirection vers `room.show`. */
function roomCodeFrom(res) {
    const match = /\/r\/([A-Za-z0-9]+)\/?$/.exec(res.headers.Location || '');

    return match === null ? null : match[1];
}

/** Le jeton CSRF que les clients envoient : le cookie `XSRF-TOKEN`, décodé. */
function xsrfToken(jar) {
    const values = jar.cookiesForURL(BASE.url)['XSRF-TOKEN'];

    return values && values.length > 0
        ? decodeURIComponent(values[values.length - 1])
        : null;
}

function writeHeaders(jar, seatToken, accept) {
    const headers = { Accept: accept };
    const xsrf = xsrfToken(jar);

    if (xsrf !== null) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    if (seatToken !== null) {
        headers['X-Seat-Token'] = seatToken;
    }

    return headers;
}

function exportCookies(jar) {
    const cookies = {};

    for (const [name, values] of Object.entries(jar.cookiesForURL(BASE.url))) {
        cookies[name] = values[values.length - 1];
    }

    return cookies;
}

function track(res, name) {
    if (res.status === 0) {
        metrics.httpNetwork.add(1, { name });
    } else if (res.status >= 500) {
        metrics.http5xx.add(1, { name, status: String(res.status) });
    }
}
