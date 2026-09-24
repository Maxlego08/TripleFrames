<?php

namespace App\Settings;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Constantes du moteur de partie — propriété de la spec 60 (§ 19.1, contrats C7 § 5 et C8 § 5).
 *
 * Construites depuis `config/game.php › engine`, section lue EXCLUSIVEMENT ici. Chaque
 * valeur de configuration vaut la constante `DEFAULT_*` correspondante, sans `env()` :
 * la constante reste la source unique, ajustable après le test de charge (D33 du
 * 23/09) sans changer les noms. Les défauts sont À CONFIRMER au relevé du VPS.
 *
 * **Ni une limite de confort, ni une règle de score.** Aucune valeur n'est résolue par
 * compte, par plan ni par siège (neutralité de plan), et aucune n'entre dans
 * `scoring_version`. `tierGraceMs` et `preloadLeadMs` n'en font PAS partie : ce sont
 * des constantes d'instance de {@see PlatformLimits}, non surchargeables (§ 2.3).
 *
 * **Une seule garde, sur tous les chemins.** Comme {@see PlatformLimits}, chaque
 * accesseur statique délègue à l'instance construite par {@see self::fromConfig()},
 * mémoïsée DANS LE CONTENEUR (`scoped`, `AppServiceProvider`) et jamais dans une
 * propriété statique. Le constructeur refuse toute valeur hors bornes
 * (`InvalidArgumentException`) : une configuration fautive fait échouer tout
 * accesseur, jamais un seul en silence.
 *
 * **Gardes croisées avec la plateforme.** Le décompte de lancement couvre
 * `PlatformLimits::MAX_PRELOAD_LEAD_MS` plus la marge, et la clôture après pause couvre
 * les décomptes de reprise de toutes les manches, réserve de tirage comprise :
 * `(MAX_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin()) × launchCountdownMs`.
 * Cette dernière garde borne de fait la marge de tirage ; `PlatformLimits` l'inscrit
 * parmi ses propres gardes, aux défauts de cette classe
 * ({@see PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE}), pour qu'une marge
 * légale pour la plateforme ne fasse jamais lever le moteur.
 */
final readonly class EngineConstants
{
    /** Décompte avant la manche 1 et avant la reprise d'une partie en pause, en ms. */
    public const int DEFAULT_LAUNCH_COUNTDOWN_MS = 5000;

    /** Marge de « manche suivante » et de « passer la manche », en ms. */
    public const int DEFAULT_NEXT_ROUND_MARGIN_MS = 1000;

    /** Intervalle du battement de présence d'un siège, en ms. */
    public const int DEFAULT_HEARTBEAT_INTERVAL_MS = 10000;

    /** Silence de battement au-delà duquel un siège passe `disconnected`, en ms. */
    public const int DEFAULT_DISCONNECT_AFTER_MS = 25000;

    /** Clôture d'une partie en pause sans joueur connecté (« 15 min », 00), en ms. */
    public const int DEFAULT_PAUSE_TIMEOUT_MS = 900000;

    /** Attente en processus d'un job de frontière réveillé tôt, en ms (§ 4.2). */
    public const int DEFAULT_TRANSITION_MAX_WAIT_MS = 1000;

    /** Échantillons de la poignée de main d'horloge, pour l'affichage seulement (§ 2.4). */
    public const int DEFAULT_CLOCK_SAMPLES = 3;

    /** Débit du limiteur `game-read`, par minute (§ 10.3). */
    public const int DEFAULT_GAME_READS_PER_MINUTE = 90;

    /** Débit du limiteur `game-write`, par minute (§ 10.3). */
    public const int DEFAULT_GAME_WRITES_PER_MINUTE = 30;

    /** Marge d'expiration d'une URL d'image au-delà de sa fenêtre de service, en ms (C8). */
    public const int DEFAULT_SERVE_URL_EXPIRY_MARGIN_MS = 5000;

    /** Débit du limiteur `frame-serve`, par minute et par jeton (C8). */
    public const int DEFAULT_FRAME_SERVE_PER_MINUTE = 60;

    /**
     * Résolution d'un délai de job, en ms : `InteractsWithTime::availableAt()` tronque
     * tout délai à la seconde, donc un job de frontière peut se réveiller jusqu'à
     * 999 ms trop tôt. L'attente en processus doit couvrir cette troncature.
     */
    public const int JOB_DELAY_RESOLUTION_MS = 1000;

    /** Battements minimaux qu'un siège peut manquer avant de passer `disconnected`. */
    public const int HEARTBEATS_BEFORE_DISCONNECT = 2;

    /**
     * Écritures `game-write` réservées par battement : le battement lui-même et autant
     * pour les gestes du siège (réponse exclue, qui a son propre limiteur).
     */
    public const int WRITES_PER_HEARTBEAT = 2;

    /** Lectures du sondage solo par palier : sa garde de service, puis son ouverture. */
    public const int SOLO_READS_PER_TIER = 2;

    /** Lectures du sondage solo par manche, hors paliers : clôture, révélation, fin. */
    public const int SOLO_READS_PER_ROUND = 3;

    /** Chargements d'image couverts par palier et par jeton : le chargement et une reprise. */
    public const int FRAME_LOADS_PER_TIER = 2;

    /**
     * Chargements d'image hors paliers : aucun. Le préchargement du palier 1 suivant,
     * servi pendant la révélation, compte parmi les paliers de sa propre manche.
     */
    private const int FRAME_LOADS_PER_ROUND = 0;

    /** Préfixe de configuration sous lequel chaque valeur est lue. */
    private const string CONFIG_PREFIX = 'game.engine.';

    /** Millisecondes par seconde : les durées de réglage sont en secondes. */
    private const int MILLISECONDS_PER_SECOND = 1000;

    /** Secondes par minute : les débits sont exprimés par minute. */
    private const int SECONDS_PER_MINUTE = 60;

    /** Borne basse d'une durée, d'un compte ou d'un débit qui ne peut être nul. */
    private const int MIN_POSITIVE = 1;

    /** Borne basse d'une marge qui peut être nulle. */
    private const int MIN_NON_NEGATIVE = 0;

    /**
     * Les onze valeurs, gardées ici et nulle part ailleurs.
     *
     * @param  int  $launchCountdownMs  Décompte de lancement et de reprise, en ms.
     * @param  int  $nextRoundMarginMs  Marge de « manche suivante » et de « passer », en ms.
     * @param  int  $heartbeatIntervalMs  Intervalle du battement, en ms.
     * @param  int  $disconnectAfterMs  Seuil de déconnexion, en ms.
     * @param  int  $pauseTimeoutMs  Clôture après pause, en ms.
     * @param  int  $transitionMaxWaitMs  Attente en processus d'un job réveillé tôt, en ms.
     * @param  int  $clockSamples  Échantillons de la poignée de main d'horloge.
     * @param  int  $gameReadsPerMinute  Débit `game-read`, par minute.
     * @param  int  $gameWritesPerMinute  Débit `game-write`, par minute.
     * @param  int  $serveUrlExpiryMarginMs  Marge d'expiration d'une URL d'image, en ms.
     * @param  int  $frameServePerMinute  Débit `frame-serve`, par minute et par jeton.
     *
     * @throws InvalidArgumentException Une valeur hors de ses bornes, ou une marge de
     *                                  tirage de la plateforme que la clôture après
     *                                  pause ne couvre pas.
     */
    public function __construct(
        public int $launchCountdownMs,
        public int $nextRoundMarginMs,
        public int $heartbeatIntervalMs,
        public int $disconnectAfterMs,
        public int $pauseTimeoutMs,
        public int $transitionMaxWaitMs,
        public int $clockSamples,
        public int $gameReadsPerMinute,
        public int $gameWritesPerMinute,
        public int $serveUrlExpiryMarginMs,
        public int $frameServePerMinute,
    ) {
        self::assertAtLeast('nextRoundMarginMs', $nextRoundMarginMs, self::MIN_POSITIVE);
        // Le palier 1 de la manche programmée doit rester hors de sa fenêtre de service
        // pendant toute l'avance de signature, plus la marge.
        self::assertAtLeast('launchCountdownMs', $launchCountdownMs, PlatformLimits::MAX_PRELOAD_LEAD_MS + $nextRoundMarginMs);
        self::assertAtLeast('heartbeatIntervalMs', $heartbeatIntervalMs, self::MIN_POSITIVE);
        self::assertAtLeast('disconnectAfterMs', $disconnectAfterMs, self::HEARTBEATS_BEFORE_DISCONNECT * $heartbeatIntervalMs);
        self::assertAtLeast('pauseTimeoutMs', $pauseTimeoutMs, self::MIN_POSITIVE);
        self::assertAtLeast(
            'pauseTimeoutMs',
            $pauseTimeoutMs,
            self::resumeCountdownsMs($launchCountdownMs, PlatformLimits::drawSubstituteMargin()),
        );
        self::assertAtLeast('transitionMaxWaitMs', $transitionMaxWaitMs, self::JOB_DELAY_RESOLUTION_MS);
        self::assertAtLeast('clockSamples', $clockSamples, self::MIN_POSITIVE);
        self::assertAtLeast('gameReadsPerMinute', $gameReadsPerMinute, self::soloReadsPerMinuteAtBounds());
        self::assertAtLeast(
            'gameWritesPerMinute',
            $gameWritesPerMinute,
            self::WRITES_PER_HEARTBEAT * self::ceilDiv(self::SECONDS_PER_MINUTE * self::MILLISECONDS_PER_SECOND, $heartbeatIntervalMs),
        );
        self::assertAtLeast('serveUrlExpiryMarginMs', $serveUrlExpiryMarginMs, self::MIN_NON_NEGATIVE);
        self::assertAtLeast('frameServePerMinute', $frameServePerMinute, self::frameLoadsPerMinuteAtBounds());
    }

    /**
     * Instance complète depuis `config/game.php › engine`.
     *
     * @throws InvalidArgumentException Une valeur configurée hors de ses bornes.
     */
    public static function fromConfig(): self
    {
        return new self(
            launchCountdownMs: self::configured('launch_countdown_ms', self::DEFAULT_LAUNCH_COUNTDOWN_MS),
            nextRoundMarginMs: self::configured('next_round_margin_ms', self::DEFAULT_NEXT_ROUND_MARGIN_MS),
            heartbeatIntervalMs: self::configured('heartbeat_interval_ms', self::DEFAULT_HEARTBEAT_INTERVAL_MS),
            disconnectAfterMs: self::configured('disconnect_after_ms', self::DEFAULT_DISCONNECT_AFTER_MS),
            pauseTimeoutMs: self::configured('pause_timeout_ms', self::DEFAULT_PAUSE_TIMEOUT_MS),
            transitionMaxWaitMs: self::configured('transition_max_wait_ms', self::DEFAULT_TRANSITION_MAX_WAIT_MS),
            clockSamples: self::configured('clock_samples', self::DEFAULT_CLOCK_SAMPLES),
            gameReadsPerMinute: self::configured('game_reads_per_minute', self::DEFAULT_GAME_READS_PER_MINUTE),
            gameWritesPerMinute: self::configured('game_writes_per_minute', self::DEFAULT_GAME_WRITES_PER_MINUTE),
            serveUrlExpiryMarginMs: self::configured('serve_url_expiry_margin_ms', self::DEFAULT_SERVE_URL_EXPIRY_MARGIN_MS),
            frameServePerMinute: self::configured('frame_serve_per_minute', self::DEFAULT_FRAME_SERVE_PER_MINUTE),
        );
    }

    /**
     * L'instance du conteneur, construite une fois par cycle de vie (requête, job).
     *
     * Jamais une propriété statique de classe (voir le docblock de la classe).
     */
    public static function current(): self
    {
        return app(self::class);
    }

    /**
     * Décompte avant `T₁` de la manche 1, et avant celui de la manche qui suit une
     * reprise (§ 5.2, § 14.2). Remplace l'ancien `P` (n° 48).
     */
    public static function launchCountdownMs(): int
    {
        return self::current()->launchCountdownMs;
    }

    /** Marge de « manche suivante » et de « passer la manche » (§ 5.4, § 16.5). */
    public static function nextRoundMarginMs(): int
    {
        return self::current()->nextRoundMarginMs;
    }

    /** Intervalle du battement de présence (§ 13.1). */
    public static function heartbeatIntervalMs(): int
    {
        return self::current()->heartbeatIntervalMs;
    }

    /** Silence de battement au-delà duquel un siège passe `disconnected` (§ 13.2). */
    public static function disconnectAfterMs(): int
    {
        return self::current()->disconnectAfterMs;
    }

    /**
     * Échéance « 15 min sans joueur connecté » d'une partie en pause (§ 14.3).
     *
     * À ne jamais confondre avec `disconnectGraceSeconds`, réglage d'hôte : celui-ci
     * est une constante de plateforme, identique pour toutes les parties.
     */
    public static function pauseTimeoutMs(): int
    {
        return self::current()->pauseTimeoutMs;
    }

    /**
     * Plafond de l'attente en processus d'un job de frontière réveillé tôt (§ 4.2) :
     * jamais `Sleep::until()` nu, qui n'a aucun plafond.
     */
    public static function transitionMaxWaitMs(): int
    {
        return self::current()->transitionMaxWaitMs;
    }

    /** Échantillons de la poignée de main d'horloge, affichage seulement (§ 2.4). */
    public static function clockSamples(): int
    {
        return self::current()->clockSamples;
    }

    /** Débit du limiteur `game-read` (§ 10.3). */
    public static function gameReadsPerMinute(): int
    {
        return self::current()->gameReadsPerMinute;
    }

    /** Débit du limiteur `game-write` (§ 10.3). */
    public static function gameWritesPerMinute(): int
    {
        return self::current()->gameWritesPerMinute;
    }

    /** Marge d'expiration d'une URL d'image signée (§ 7.5, contrat C8). */
    public static function serveUrlExpiryMarginMs(): int
    {
        return self::current()->serveUrlExpiryMarginMs;
    }

    /** Débit du limiteur `frame-serve`, par jeton (§ 10.3, contrat C8). */
    public static function frameServePerMinute(): int
    {
        return self::current()->frameServePerMinute;
    }

    private static function configured(string $key, int $default): int
    {
        return Config::integer(self::CONFIG_PREFIX.$key, $default);
    }

    /**
     * Somme des décomptes de reprise que la clôture après pause doit couvrir (§ 17.3,
     * point 3) : une pause au plus entre deux manches, réserve de tirage comprise, et
     * chaque reprise ajoute un décompte que `total_paused_ms` ne compte pas.
     */
    private static function resumeCountdownsMs(int $launchCountdownMs, int $drawSubstituteMargin): int
    {
        return (RoomSettingsBounds::MAX_ROUNDS_COUNT + $drawSubstituteMargin) * $launchCountdownMs;
    }

    /**
     * Lectures par minute du sondage solo au pire cas des bornes (§ 19.1) : le solo
     * sonde à chaque garde de palier et à chaque étape, soit `2N + 3` lectures par
     * cycle `D + R`, au `D` minimal de chaque `N` et à la révélation minimale.
     */
    private static function soloReadsPerMinuteAtBounds(): int
    {
        return self::peakPerMinuteAtBounds(self::SOLO_READS_PER_TIER, self::SOLO_READS_PER_ROUND);
    }

    /**
     * Chargements d'image par minute et par jeton au pire cas des bornes (§ 19.1) :
     * deux chargements par palier, `N` paliers par cycle `D + R` le plus court.
     */
    private static function frameLoadsPerMinuteAtBounds(): int
    {
        return self::peakPerMinuteAtBounds(self::FRAME_LOADS_PER_TIER, self::FRAME_LOADS_PER_ROUND);
    }

    /**
     * Pic de requêtes dans une minute, sur tous les `N` des bornes, pour une cadence
     * de `perTier × N + perRound` requêtes par cycle `D + R` le plus court.
     *
     * Le limiteur `perMinute` compte sur une fenêtre FIXE de 60 s ouverte à la
     * première requête, et non sur une moyenne : cette fenêtre peut s'ouvrir sur une
     * rafale (clôture, révélation, fin). Chaque requête d'un cycle revient au plus
     * `⌈60 ÷ (D + R)⌉` fois dans la fenêtre ; le produit majore le pic quelle que
     * soit la phase, là où la cadence moyenne `⌈k × 60 ÷ (D + R)⌉` le sous-estime.
     */
    private static function peakPerMinuteAtBounds(int $perTier, int $perRound): int
    {
        $worst = 0;

        for ($framesPerRound = RoomSettingsBounds::MIN_FRAMES_PER_ROUND; $framesPerRound <= RoomSettingsBounds::MAX_FRAMES_PER_ROUND; $framesPerRound++) {
            $perCycle = $perTier * $framesPerRound + $perRound;
            $worst = max($worst, $perCycle * self::ceilDiv(self::SECONDS_PER_MINUTE, self::shortestCycleSeconds($framesPerRound)));
        }

        return $worst;
    }

    /** Cycle `D + R` le plus court pour `N` images, en secondes. */
    private static function shortestCycleSeconds(int $framesPerRound): int
    {
        return RoomSettingsBounds::minRoundDuration($framesPerRound) + RoomSettingsBounds::MIN_REVEAL_DURATION;
    }

    /** Division entière arrondie au supérieur, pour deux entiers positifs. */
    private static function ceilDiv(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }

    private static function assertAtLeast(string $name, int $value, int $min): void
    {
        if ($value < $min) {
            throw new InvalidArgumentException(sprintf(
                'EngineConstants : %s vaut %d, sous sa borne basse %d.',
                $name,
                $value,
                $min,
            ));
        }
    }
}
