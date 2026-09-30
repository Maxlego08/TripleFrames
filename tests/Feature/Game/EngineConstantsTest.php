<?php

use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsBounds;
use Carbon\CarbonImmutable;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Constantes du moteur — spec 60 § 19.1 (contrats C7 § 5 et C8 § 5)
|--------------------------------------------------------------------------
|
| Chaque garde est prouvée dans les deux sens : les défauts la respectent, sa
| frontière exacte est admise, et un pas au-delà fait lever TOUT accesseur.
| Les pires cas des bornes sont recalculés ICI depuis les bornes nommées de
| `RoomSettingsBounds`, sans appeler les fonctions que la garde appelle : un
| test qui relirait le code prouverait qu'une copie du code est égale au code.
|
| L'instance est mémoïsée dans le conteneur (`scoped`) : un test qui change la
| configuration passe par `engineConstantsConfigure()` ou
| `platformLimitsConfigure()` (tests/Pest.php), qui l'oublient.
|
*/

/**
 * Les onze accesseurs statiques du moteur, un par clé `game.engine.*`.
 *
 * @return list<string>
 */
function engineConstantsAccessors(): array
{
    return array_map(
        static fn (string $key): string => Str::camel($key),
        array_keys((array) config('game.engine')),
    );
}

/**
 * Accesseurs qui lèvent avec la configuration courante, dans l'ordre.
 *
 * @return list<string>
 */
function engineConstantsFailingAccessors(): array
{
    $failing = [];

    foreach (engineConstantsAccessors() as $accessor) {
        try {
            EngineConstants::{$accessor}();
        } catch (InvalidArgumentException) {
            $failing[] = $accessor;
        }
    }

    return $failing;
}

/**
 * `D` minimal pour `N` images, réécrit depuis les bornes nommées : la borne
 * croisée 1 (`D ≥ 5 s × N`) et le plancher de `D`.
 */
function engineConstantsShortestRoundSeconds(int $framesPerRound): int
{
    return max(RoomSettingsBounds::MIN_ROUND_DURATION, RoomSettingsBounds::MIN_TIER_DURATION * $framesPerRound);
}

/**
 * Pire cas, par minute, d'une cadence de `$perCycle($n)` requêtes par cycle
 * `D + R` le plus court, sur tous les `N` des bornes.
 *
 * Pic d'une fenêtre FIXE de 60 s, celle du limiteur `perMinute`, et non
 * cadence moyenne : chaque requête d'un cycle y revient au plus
 * `⌈60 ÷ (D + R)⌉` fois, quelle que soit la phase où la fenêtre s'ouvre.
 *
 * @param  Closure(int): int  $perCycle
 */
function engineConstantsWorstPerMinute(Closure $perCycle): int
{
    $worst = 0;

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        $cycleSeconds = engineConstantsShortestRoundSeconds($framesPerRound) + RoomSettingsBounds::MIN_REVEAL_DURATION;
        $worst = max($worst, $perCycle($framesPerRound) * (int) ceil(60 / $cycleSeconds));
    }

    return $worst;
}

/**
 * Nombre maximal de requêtes d'un calendrier périodique (`$phases` en secondes
 * dans un cycle de `$cycleSeconds`) que compte une fenêtre fixe de 60 s, sur
 * toutes les phases d'ouverture au pas de la seconde.
 *
 * @param  list<int>  $phases
 */
function engineConstantsFixedWindowPeak(array $phases, int $cycleSeconds): int
{
    $peak = 0;

    foreach (range(0, $cycleSeconds - 1) as $opensAt) {
        $count = 0;

        foreach ($phases as $phase) {
            for ($at = $phase; $at < $opensAt + 60; $at += $cycleSeconds) {
                if ($at >= $opensAt) {
                    $count++;
                }
            }
        }

        $peak = max($peak, $count);
    }

    return $peak;
}

it('le décompte de lancement couvre MAX_PRELOAD_LEAD_MS plus la marge', function (): void {
    expect(EngineConstants::launchCountdownMs())
        ->toBeGreaterThanOrEqual(PlatformLimits::MAX_PRELOAD_LEAD_MS + EngineConstants::nextRoundMarginMs());

    $floor = PlatformLimits::MAX_PRELOAD_LEAD_MS + EngineConstants::DEFAULT_NEXT_ROUND_MARGIN_MS;

    engineConstantsConfigure(['launch_countdown_ms' => $floor]);
    expect(EngineConstants::launchCountdownMs())->toBe($floor);

    engineConstantsConfigure(['launch_countdown_ms' => $floor - 1]);
    expect(engineConstantsFailingAccessors())->toBe(engineConstantsAccessors());

    // La marge entre dans la garde : relevée, elle rend le même décompte trop court.
    engineConstantsConfigure([
        'launch_countdown_ms' => $floor,
        'next_round_margin_ms' => EngineConstants::DEFAULT_NEXT_ROUND_MARGIN_MS + 1,
    ]);
    expect(fn (): int => EngineConstants::launchCountdownMs())->toThrow(InvalidArgumentException::class, 'launchCountdownMs');
});

it('le seuil de déconnexion vaut au moins deux battements', function (): void {
    expect(EngineConstants::HEARTBEATS_BEFORE_DISCONNECT)->toBe(2);
    expect(EngineConstants::disconnectAfterMs())->toBeGreaterThanOrEqual(2 * EngineConstants::heartbeatIntervalMs());

    $floor = 2 * EngineConstants::DEFAULT_HEARTBEAT_INTERVAL_MS;

    engineConstantsConfigure(['disconnect_after_ms' => $floor]);
    expect(EngineConstants::disconnectAfterMs())->toBe($floor);

    engineConstantsConfigure(['disconnect_after_ms' => $floor - 1]);
    expect(engineConstantsFailingAccessors())->toBe(engineConstantsAccessors());

    // Un battement allongé au-delà de la moitié du seuil par défaut le fait lever.
    engineConstantsConfigure([
        'disconnect_after_ms' => EngineConstants::DEFAULT_DISCONNECT_AFTER_MS,
        'heartbeat_interval_ms' => intdiv(EngineConstants::DEFAULT_DISCONNECT_AFTER_MS, 2) + 1,
    ]);
    expect(fn (): int => EngineConstants::heartbeatIntervalMs())->toThrow(InvalidArgumentException::class, 'disconnectAfterMs');
});

it('MIN_REVEAL_DURATION en ms dépasse MAX_PRELOAD_LEAD_MS', function (): void {
    // Seul le palier 1 de la manche suivante devient servable pendant la
    // révélation, dans ses `preload_lead_ms` finales : l'avance la plus longue
    // tient toujours dans la révélation la plus courte (contrat C7 § 4.14).
    expect(RoomSettingsBounds::MIN_REVEAL_DURATION * 1000)->toBeGreaterThan(PlatformLimits::MAX_PRELOAD_LEAD_MS);
    expect(RoomSettingsBounds::MIN_REVEAL_DURATION * 1000)->toBeGreaterThan(PlatformLimits::preloadLeadMs());
});

it('transitionMaxWaitMs couvre la troncature à la seconde', function (): void {
    // `availableAt()` tronque tout délai de job à la seconde : un job dû à
    // `S + 999 ms` devient disponible à `S`, donc jusqu'à 999 ms trop tôt.
    $probe = new class
    {
        use InteractsWithTime;

        public function earliestWakeSeconds(DateTimeInterface $dueAt): int
        {
            return $this->availableAt($dueAt);
        }
    };

    $dueAt = CarbonImmutable::parse('2026-09-24T12:00:00.999Z');
    $earlyByMs = (int) $dueAt->format('Uv') - $probe->earliestWakeSeconds($dueAt) * 1000;

    expect($earlyByMs)->toBe(999)
        ->and(EngineConstants::transitionMaxWaitMs())->toBeGreaterThan($earlyByMs)
        ->and(EngineConstants::JOB_DELAY_RESOLUTION_MS)->toBe(1000);

    engineConstantsConfigure(['transition_max_wait_ms' => EngineConstants::JOB_DELAY_RESOLUTION_MS]);
    expect(EngineConstants::transitionMaxWaitMs())->toBe(EngineConstants::JOB_DELAY_RESOLUTION_MS);

    engineConstantsConfigure(['transition_max_wait_ms' => EngineConstants::JOB_DELAY_RESOLUTION_MS - 1]);
    expect(engineConstantsFailingAccessors())->toBe(engineConstantsAccessors());
});

it('le débit de lecture couvre le sondage du solo au pire cas des bornes', function (): void {
    // `2N + 3` lectures par cycle `D + R` (spec 60 § 19.1) : la garde de service
    // et l'ouverture de chaque palier, puis clôture, révélation et fin.
    expect(EngineConstants::SOLO_READS_PER_TIER)->toBe(2)
        ->and(EngineConstants::SOLO_READS_PER_ROUND)->toBe(3);

    $worst = engineConstantsWorstPerMinute(
        static fn (int $framesPerRound): int => EngineConstants::SOLO_READS_PER_TIER * $framesPerRound + EngineConstants::SOLO_READS_PER_ROUND,
    );

    expect($worst)->toBeGreaterThan(0)
        ->and(EngineConstants::gameReadsPerMinute())->toBeGreaterThanOrEqual($worst);

    // Pourquoi le pic et non la moyenne : à `N = 2`, `D` et `R` minimaux, une
    // fenêtre fixe ouverte sur la rafale clôture / révélation / fin compte plus
    // de lectures que la cadence moyenne `⌈(2N + 3) × 60 ÷ (D + R)⌉`.
    $framesPerRound = RoomSettingsBounds::MIN_FRAMES_PER_ROUND;
    $roundSeconds = engineConstantsShortestRoundSeconds($framesPerRound);
    $cycleSeconds = $roundSeconds + RoomSettingsBounds::MIN_REVEAL_DURATION;
    $leadSeconds = intdiv(PlatformLimits::preloadLeadMs(), 1000);
    // Clôture à `D`, révélation à `D` (la grâce tient sous la seconde), fin à
    // `D + R`, soit la phase 0 du cycle suivant ; puis, par palier, la garde de
    // service à `Tᵢ − preloadLead` et l'ouverture à `Tᵢ`.
    $phases = [$roundSeconds, $roundSeconds, 0];

    foreach (range(0, $framesPerRound - 1) as $tier) {
        $opensAt = $tier * RoomSettingsBounds::MIN_TIER_DURATION;
        $phases[] = $opensAt;
        $phases[] = (($opensAt - $leadSeconds) % $cycleSeconds + $cycleSeconds) % $cycleSeconds;
    }

    $average = (int) ceil(count($phases) * 60 / $cycleSeconds);
    $peak = engineConstantsFixedWindowPeak($phases, $cycleSeconds);

    expect($phases)->toHaveCount(EngineConstants::SOLO_READS_PER_TIER * $framesPerRound + EngineConstants::SOLO_READS_PER_ROUND)
        ->and($peak)->toBeGreaterThan($average)
        ->and($peak)->toBeLessThanOrEqual($worst);

    engineConstantsConfigure(['game_reads_per_minute' => $worst]);
    expect(EngineConstants::gameReadsPerMinute())->toBe($worst);

    engineConstantsConfigure(['game_reads_per_minute' => $worst - 1]);
    expect(engineConstantsFailingAccessors())->toBe(engineConstantsAccessors());
});

it('le débit d\'images couvre deux chargements par palier au pire cas des bornes', function (): void {
    expect(EngineConstants::FRAME_LOADS_PER_TIER)->toBe(2);

    $worst = engineConstantsWorstPerMinute(
        static fn (int $framesPerRound): int => EngineConstants::FRAME_LOADS_PER_TIER * $framesPerRound,
    );

    expect($worst)->toBeGreaterThan(0)
        ->and(EngineConstants::frameServePerMinute())->toBeGreaterThanOrEqual($worst);

    engineConstantsConfigure(['frame_serve_per_minute' => $worst]);
    expect(EngineConstants::frameServePerMinute())->toBe($worst);

    engineConstantsConfigure(['frame_serve_per_minute' => $worst - 1]);
    expect(engineConstantsFailingAccessors())->toBe(engineConstantsAccessors());
});

it('un accesseur du moteur refuse une configuration hors bornes', function (): void {
    $declared = (array) config('game.engine');

    // Liste close de la spec 60 § 19.1 ; chaque valeur déclarée vaut la
    // constante `DEFAULT_*`, sans `env()` : la constante reste la source unique.
    expect($declared)->toHaveCount(11)
        ->not->toHaveKey('tier_grace_ms')
        ->not->toHaveKey('preload_lead_ms');

    foreach ($declared as $key => $value) {
        expect($value)->toBe(constant(EngineConstants::class.'::DEFAULT_'.strtoupper($key)), $key);
    }

    $outOfBounds = [
        'launch_countdown_ms' => PlatformLimits::MAX_PRELOAD_LEAD_MS + EngineConstants::DEFAULT_NEXT_ROUND_MARGIN_MS - 1,
        'next_round_margin_ms' => 0,
        'transition_max_wait_ms' => EngineConstants::JOB_DELAY_RESOLUTION_MS - 1,
        'clock_samples' => 0,
        'heartbeat_interval_ms' => 0,
        'disconnect_after_ms' => 2 * EngineConstants::DEFAULT_HEARTBEAT_INTERVAL_MS - 1,
        'pause_timeout_ms' => 0,
        'game_reads_per_minute' => 0,
        'game_writes_per_minute' => 2 * (int) ceil(60_000 / EngineConstants::DEFAULT_HEARTBEAT_INTERVAL_MS) - 1,
        'serve_url_expiry_margin_ms' => -1,
        'frame_serve_per_minute' => 0,
    ];

    // Chaque clé déclarée est réellement lue, et gardée.
    expect(array_keys($outOfBounds))->toEqualCanonicalizing(array_keys($declared));

    foreach ($outOfBounds as $key => $value) {
        engineConstantsConfigure([$key => $value]);

        // Aucun chemin ne contourne la garde : ni un autre accesseur, ni
        // l'instance du conteneur, ni une construction directe.
        expect(engineConstantsFailingAccessors())->toBe(engineConstantsAccessors(), $key);
        expect(fn (): EngineConstants => EngineConstants::current())->toThrow(InvalidArgumentException::class);
        expect(fn (): EngineConstants => EngineConstants::fromConfig())->toThrow(InvalidArgumentException::class);

        engineConstantsConfigure([$key => $declared[$key]]);
        expect(engineConstantsFailingAccessors())->toBe([], $key);
    }

    // Les gardes que les tests dédiés ne bornent pas sont admises à leur
    // frontière exacte : un mutant qui les durcirait d'un cran meurt ici.
    $atBounds = [
        'next_round_margin_ms' => 1,
        'clock_samples' => 1,
        'serve_url_expiry_margin_ms' => 0,
        'game_writes_per_minute' => 2 * (int) ceil(60_000 / EngineConstants::DEFAULT_HEARTBEAT_INTERVAL_MS),
    ];

    foreach ($atBounds as $key => $value) {
        engineConstantsConfigure([$key => $value]);
        expect(engineConstantsFailingAccessors())->toBe([], $key);
        expect(EngineConstants::{Str::camel($key)}())->toBe($value, $key);

        engineConstantsConfigure([$key => $declared[$key]]);
    }

    // Une valeur non entière lève au lieu d'être convertie en silence.
    engineConstantsConfigure(['pause_timeout_ms' => (string) EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS]);
    expect(engineConstantsFailingAccessors())->toBe(engineConstantsAccessors());
});

it('la clôture après pause couvre les décomptes de reprise de toutes les manches aux bornes', function (): void {
    // Une pause au plus entre deux manches, réserve de tirage comprise ; chaque
    // reprise ajoute un décompte que `total_paused_ms` ne compte pas (§ 17.3).
    $resumeCountdownsMs = (RoomSettingsBounds::MAX_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin())
        * EngineConstants::launchCountdownMs();

    expect(EngineConstants::pauseTimeoutMs())->toBeGreaterThanOrEqual($resumeCountdownsMs);

    engineConstantsConfigure(['pause_timeout_ms' => $resumeCountdownsMs]);
    expect(EngineConstants::pauseTimeoutMs())->toBe($resumeCountdownsMs);

    engineConstantsConfigure(['pause_timeout_ms' => $resumeCountdownsMs - 1]);
    expect(engineConstantsFailingAccessors())->toBe(engineConstantsAccessors());

    // Le décompte entre dans la garde : allongé, il n'est plus couvert.
    engineConstantsConfigure([
        'pause_timeout_ms' => EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS,
        'launch_countdown_ms' => intdiv(EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS, RoomSettingsBounds::MAX_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin()) + 1,
    ]);
    expect(fn (): int => EngineConstants::pauseTimeoutMs())->toThrow(InvalidArgumentException::class, 'pauseTimeoutMs');

    // La marge de tirage de la plateforme aussi : relevée, elle n'est plus couverte.
    engineConstantsConfigure([
        'launch_countdown_ms' => EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS,
        'pause_timeout_ms' => (RoomSettingsBounds::MAX_ROUNDS_COUNT + PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN) * EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS,
    ]);
    expect(engineConstantsFailingAccessors())->toBe([]);

    platformLimitsConfigure(['draw_substitute_margin' => PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN + 1]);
    expect(fn (): int => EngineConstants::pauseTimeoutMs())->toThrow(InvalidArgumentException::class, 'pauseTimeoutMs');
});

it('une marge de tirage admise par PlatformLimits ne fait jamais échouer la garde de pauseTimeoutMs aux défauts', function (): void {
    expect(config('game.engine.pause_timeout_ms'))->toBe(EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS)
        ->and(config('game.engine.launch_countdown_ms'))->toBe(EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS);

    $admitted = [];
    $engineRefused = [];

    // Balayage exhaustif, un pas au-delà de chaque borne de la plateforme.
    foreach (range(-1, PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN + 1) as $margin) {
        platformLimitsConfigure(['draw_substitute_margin' => $margin]);

        try {
            PlatformLimits::drawSubstituteMargin();
        } catch (InvalidArgumentException) {
            continue;
        }

        $admitted[] = $margin;

        if (engineConstantsFailingAccessors() !== []) {
            $engineRefused[] = $margin;
        }
    }

    expect($engineRefused)->toBe([])
        ->and($admitted)->toContain(0, PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN);

    // La borne est exacte, et non plus sévère que nécessaire : la plus grande
    // marge admise est la borne de fait `⌊pauseTimeoutMs ÷ launchCountdownMs⌋ −
    // MAX_ROUNDS_COUNT` (150 aux défauts), sous la borne de `sequence_index`.
    $pauseBound = intdiv(EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS, EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS)
        - RoomSettingsBounds::MAX_ROUNDS_COUNT;

    expect(max($admitted))->toBe(min($pauseBound, PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN))
        ->and(PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE)->toBe($pauseBound);
});
