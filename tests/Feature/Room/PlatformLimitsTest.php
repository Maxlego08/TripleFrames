<?php

use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use Database\Factories\GameFactory;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Plafonds de plateforme — contrat C0, spec 50 § 2.3 et § 2.7
|--------------------------------------------------------------------------
|
| Trois propriétés sont prouvées ici, et non relues :
|
| 1. B_MAX(N) EST UNE RÈGLE DE SCORE, PAS UN RÉGLAGE. Pourcentage entier,
|    jamais lu en configuration, jamais écrêté (D22 du 23/09) : sa table est
|    épinglée sous la version de score 1.
| 2. `tierGraceMs` ET `preloadLeadMs` NE SONT PAS SURCHARGEABLES. Une clé posée
|    par un déploiement est ignorée, sans quoi le palier retenu et la fenêtre
|    de service changeraient sans qu'aucune version de règle ne le trace.
| 3. UNE SEULE GARDE, SUR TOUS LES CHEMINS. Le constructeur refuse toute valeur
|    hors bornes, et chaque accesseur statique délègue à l'instance du
|    conteneur : une configuration fautive ne contourne la garde nulle part.
|
| L'instance est mémoïsée dans le conteneur (`scoped`) : un test qui change la
| configuration l'oublie avant de relire, exactement comme le ferait le début
| d'une nouvelle requête ou d'un nouveau job — par `platformLimitsConfigure()`,
| partagée dans `tests/Pest.php`.
|
*/

/**
 * Une instance construite par le constructeur, aux défauts sauf `$overrides`.
 *
 * @param  array<string, int>  $overrides  Arguments nommés du constructeur.
 */
function platformLimitsWith(array $overrides): PlatformLimits
{
    $arguments = array_merge([
        'savedConfigsPerUser' => PlatformLimits::DEFAULT_SAVED_CONFIGS_PER_USER,
        'roomSeats' => PlatformLimits::DEFAULT_ROOM_SEATS,
        'avatarPresets' => PlatformLimits::DEFAULT_AVATAR_PRESETS,
        'historyWindowMonths' => PlatformLimits::DEFAULT_HISTORY_WINDOW_MONTHS,
        'successRateMinRounds' => PlatformLimits::DEFAULT_SUCCESS_RATE_MIN_ROUNDS,
        'frameUploadMaxKilobytes' => PlatformLimits::DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES,
        'tierGraceMs' => PlatformLimits::DEFAULT_TIER_GRACE_MS,
        'preloadLeadMs' => PlatformLimits::DEFAULT_PRELOAD_LEAD_MS,
        'drawSubstituteMargin' => PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN,
        'roomMemoryWindowDays' => PlatformLimits::DEFAULT_ROOM_MEMORY_WINDOW_DAYS,
        'roomMemoryWindowRounds' => PlatformLimits::DEFAULT_ROOM_MEMORY_WINDOW_ROUNDS,
        'themeSelectorMinPool' => PlatformLimits::DEFAULT_THEME_SELECTOR_MIN_POOL,
        'lobbyBroadcastDebounceMs' => PlatformLimits::DEFAULT_LOBBY_BROADCAST_DEBOUNCE_MS,
        'frameCropMaxWidthPercent' => PlatformLimits::DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT,
        'frameCropMinWidthPx' => PlatformLimits::DEFAULT_FRAME_CROP_MIN_WIDTH_PX,
    ], $overrides);

    return new PlatformLimits(...$arguments);
}

/**
 * La table `B_max` telle que la classe la calcule, pour chaque `N` des bornes.
 *
 * @return array<int, int>
 */
function platformLimitsSpeedBonusTable(): array
{
    $table = [];

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        $table[$framesPerRound] = PlatformLimits::speedBonusMaxPercent($framesPerRound);
    }

    return $table;
}

/**
 * Les accesseurs statiques sans argument qui rendent un entier, par réflexion :
 * ni une liste tenue à la main, ni la liste que le code déclare.
 *
 * @return list<string>
 */
function platformLimitsScalarAccessors(): array
{
    $accessors = [];

    foreach ((new ReflectionClass(PlatformLimits::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $type = $method->getReturnType();

        if ($method->isStatic()
            && $method->getNumberOfParameters() === 0
            && $type instanceof ReflectionNamedType
            && $type->getName() === 'int') {
            $accessors[] = $method->getName();
        }
    }

    return $accessors;
}

it('fixe speedBonusMaxPercent à 50, 50, 33 et 25 pour N = 2 à 5 sous la version de score 1', function (): void {
    // Toute modification de cette table incrémente la version de score (spec 80
    // § 6.1). `GameFactory::SCORING_VERSION` en tient lieu jusqu'au lot L80-1,
    // qui la remplace par `ScoringRules::VERSION`.
    expect(GameFactory::SCORING_VERSION)->toBe(1);

    expect(platformLimitsSpeedBonusTable())->toBe([2 => 50, 3 => 50, 4 => 33, 5 => 25]);

    // La raison d'être de la fonction de N (spec 80 § 3.3) : au barème par
    // défaut, la frontière 1 → 2 ne récompense jamais l'attente.
    foreach (platformLimitsSpeedBonusTable() as $framesPerRound => $percent) {
        expect(($framesPerRound - 1) * $percent)->toBeLessThanOrEqual(PlatformLimits::FULL_PERCENT);
        expect($percent)->toBeLessThanOrEqual(PlatformLimits::SPEED_BONUS_MAX_PERCENT_CAP);
    }
});

it('refuse un N hors bornes pour speedBonusMaxPercent au lieu de l\'écrêter', function (int $framesPerRound): void {
    expect(fn (): int => PlatformLimits::speedBonusMaxPercent($framesPerRound))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sous la borne basse' => [RoomSettingsBounds::MIN_FRAMES_PER_ROUND - 1],
    'au-dessus de la borne haute' => [RoomSettingsBounds::MAX_FRAMES_PER_ROUND + 1],
    'zéro' => [0],
    'négatif' => [-1],
]);

it('ignore toute configuration de B_max', function (): void {
    $table = platformLimitsSpeedBonusTable();

    expect(array_filter(
        array_keys((array) config('game.platform')),
        static fn (string $key): bool => str_starts_with($key, 'speed_bonus'),
    ))->toBe([]);

    platformLimitsConfigure([
        'speed_bonus_max_fraction' => 1.0,
        'speed_bonus_max_percent' => 100,
        'speed_bonus_max_percent_cap' => 100,
    ]);

    expect(platformLimitsSpeedBonusTable())->toBe($table);
    expect(PlatformLimits::current()->toArray()['speedBonusMaxPercent'])->toBe($table);
});

it('ignore toute configuration de tier_grace_ms et de preload_lead_ms', function (): void {
    expect(config('game.platform'))
        ->not->toHaveKey('tier_grace_ms')
        ->not->toHaveKey('preload_lead_ms');

    // Des valeurs légales mais différentes : lues, elles changeraient le score.
    platformLimitsConfigure(['tier_grace_ms' => 100, 'preload_lead_ms' => 1600]);

    expect(PlatformLimits::tierGraceMs())->toBe(PlatformLimits::DEFAULT_TIER_GRACE_MS);
    expect(PlatformLimits::preloadLeadMs())->toBe(PlatformLimits::DEFAULT_PRELOAD_LEAD_MS);

    // Des valeurs hors bornes : lues, elles feraient lever la garde.
    platformLimitsConfigure(['tier_grace_ms' => 120_000, 'preload_lead_ms' => 30_000]);

    expect(PlatformLimits::fromConfig()->tierGraceMs)->toBe(PlatformLimits::DEFAULT_TIER_GRACE_MS);
    expect(PlatformLimits::fromConfig()->preloadLeadMs)->toBe(PlatformLimits::DEFAULT_PRELOAD_LEAD_MS);
});

it('n\'a aucun accesseur paramétré par un utilisateur, un plan ou un siège', function (): void {
    $class = new ReflectionClass(PlatformLimits::class);
    $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);

    expect($methods)->not->toBeEmpty();

    foreach ($methods as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $where = $method->getName().'($'.$parameter->getName().')';

            // Seuls des entiers nus : aucun type de classe ne peut donc désigner
            // un compte, un plan, un siège ni un modèle.
            expect($type)->toBeInstanceOf(ReflectionNamedType::class, $where);
            expect($type instanceof ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'int')
                ->toBeTrue($where);
            expect($parameter->getName())->not->toBeIn(['user', 'plan', 'seat', 'player', 'account'], $where);
        }
    }

    // Seul `B_max` prend un argument, et c'est `N`.
    $parameterized = array_values(array_filter(
        $methods,
        static fn (ReflectionMethod $method): bool => $method->isStatic() && $method->getNumberOfParameters() > 0,
    ));

    expect(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $parameterized))
        ->toBe(['speedBonusMaxPercent']);
});

it('déclare une clé game.platform par accesseur configurable, et réciproquement', function (): void {
    // Liste close de la spec 50 § 2.3 : constantes de code, jamais lues en configuration.
    $neverConfigured = ['tierGraceMs', 'preloadLeadMs'];

    $configurable = array_values(array_diff(platformLimitsScalarAccessors(), $neverConfigured));
    $expectedKeys = array_map(static fn (string $accessor): string => Str::snake($accessor), $configurable);
    $declared = (array) config('game.platform');

    expect($configurable)->toHaveCount(13);
    expect(array_keys($declared))->toEqualCanonicalizing($expectedKeys);

    // Chaque valeur déclarée vaut la constante `DEFAULT_*` : la constante reste la source unique.
    foreach ($declared as $key => $value) {
        expect($value)->toBe(constant(PlatformLimits::class.'::DEFAULT_'.strtoupper($key)), $key);
    }

    // Chaque clé est réellement lue par son accesseur : une valeur légale,
    // différente du défaut, en ressort telle quelle.
    $alternatives = [
        'saved_configs_per_user' => 5,
        'room_seats' => 8,
        'avatar_presets' => 30,
        'history_window_months' => 6,
        'success_rate_min_rounds' => 10,
        'frame_upload_max_kilobytes' => 1024,
        'draw_substitute_margin' => 5,
        'room_memory_window_days' => 30,
        'room_memory_window_rounds' => 100,
        'theme_selector_min_pool' => 0,
        'lobby_broadcast_debounce_ms' => 0,
        'frame_crop_max_width_percent' => 83,
        'frame_crop_min_width_px' => 800,
    ];

    expect(array_keys($alternatives))->toEqualCanonicalizing($expectedKeys);

    foreach ($alternatives as $key => $value) {
        expect($value)->not->toBe($declared[$key], $key);

        platformLimitsConfigure([$key => $value]);
        expect(PlatformLimits::{Str::camel($key)}())->toBe($value, $key);

        platformLimitsConfigure([$key => $declared[$key]]);
    }
});

it('garde preloadLeadMs dans [1500, 2500], sous la révélation minimale', function (): void {
    expect(PlatformLimits::MIN_PRELOAD_LEAD_MS)->toBe(1500);
    expect(PlatformLimits::MAX_PRELOAD_LEAD_MS)->toBe(2500);

    expect(PlatformLimits::preloadLeadMs())
        ->toBeGreaterThanOrEqual(PlatformLimits::MIN_PRELOAD_LEAD_MS)
        ->toBeLessThanOrEqual(PlatformLimits::MAX_PRELOAD_LEAD_MS);

    // Seul le palier 1 de la manche suivante devient servable pendant la
    // révélation : l'avance tient toujours dans la révélation la plus courte.
    expect(RoomSettingsBounds::MIN_REVEAL_DURATION * 1000)->toBeGreaterThan(PlatformLimits::MAX_PRELOAD_LEAD_MS);

    expect(fn (): PlatformLimits => platformLimitsWith(['preloadLeadMs' => PlatformLimits::MIN_PRELOAD_LEAD_MS - 1]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): PlatformLimits => platformLimitsWith(['preloadLeadMs' => PlatformLimits::MAX_PRELOAD_LEAD_MS + 1]))
        ->toThrow(InvalidArgumentException::class);

    // L'intervalle est fermé : ses deux bornes sont légales.
    foreach ([PlatformLimits::MIN_PRELOAD_LEAD_MS, PlatformLimits::MAX_PRELOAD_LEAD_MS] as $lead) {
        expect(platformLimitsWith(['preloadLeadMs' => $lead])->preloadLeadMs)->toBe($lead);
    }
});

it('garde deux tier_grace_ms sous la durée minimale de palier', function (): void {
    expect(2 * PlatformLimits::tierGraceMs())->toBeLessThan(RoomSettingsBounds::MIN_TIER_DURATION * 1000);

    $limit = intdiv(RoomSettingsBounds::MIN_TIER_DURATION * 1000, 2);

    expect(platformLimitsWith(['tierGraceMs' => $limit - 1])->tierGraceMs)->toBe($limit - 1);
    expect(fn (): PlatformLimits => platformLimitsWith(['tierGraceMs' => $limit]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): PlatformLimits => platformLimitsWith(['tierGraceMs' => -1]))
        ->toThrow(InvalidArgumentException::class);
});

it('n\'offre jamais moins d\'avatars prédéfinis que de sièges', function (): void {
    // La chaîne entière des garde-fous de sièges (spec 50 § 2.7) : un salon à la
    // capacité minimale peut être lancé, et chaque siège a son avatar.
    expect(RoomSettingsBounds::MIN_CAPACITY)->toBeGreaterThanOrEqual(RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH);
    expect(PlatformLimits::roomSeats())->toBeGreaterThanOrEqual(RoomSettingsBounds::MIN_CAPACITY);
    expect(PlatformLimits::roomSeats())->toBeLessThanOrEqual(PlatformLimits::avatarPresets());

    expect(fn (): PlatformLimits => platformLimitsWith(['roomSeats' => PlatformLimits::DEFAULT_AVATAR_PRESETS + 1]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): PlatformLimits => platformLimitsWith(['roomSeats' => RoomSettingsBounds::MIN_CAPACITY - 1]))
        ->toThrow(InvalidArgumentException::class);

    // Les deux bornes fermées sont légales : la capacité minimale, et autant
    // de sièges que d'avatars prédéfinis.
    expect(platformLimitsWith(['roomSeats' => RoomSettingsBounds::MIN_CAPACITY])->roomSeats)
        ->toBe(RoomSettingsBounds::MIN_CAPACITY);
    expect(platformLimitsWith(['roomSeats' => PlatformLimits::DEFAULT_AVATAR_PRESETS])->roomSeats)
        ->toBe(PlatformLimits::DEFAULT_AVATAR_PRESETS);

    // Par la configuration aussi : trop de sièges, ou trop peu d'avatars.
    platformLimitsConfigure(['room_seats' => PlatformLimits::DEFAULT_AVATAR_PRESETS + 1]);
    expect(fn (): int => PlatformLimits::roomSeats())->toThrow(InvalidArgumentException::class);

    platformLimitsConfigure([
        'room_seats' => PlatformLimits::DEFAULT_ROOM_SEATS,
        'avatar_presets' => PlatformLimits::DEFAULT_ROOM_SEATS - 1,
    ]);
    expect(fn (): int => PlatformLimits::avatarPresets())->toThrow(InvalidArgumentException::class);
});

it('refuse une marge, une fenêtre, un seuil ou un plafond de recadrage hors bornes', function (): void {
    // `round.sequence_index` est un `unsignedTinyInteger` : M + marge doit y tenir.
    expect(PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN)->toBe(255 - RoomSettingsBounds::MAX_ROUNDS_COUNT);

    // Borne de fait sous la clôture après pause du moteur (spec 60 § 19.1) : la
    // plus basse des deux gouverne. Sa preuve côté moteur vit dans
    // `EngineConstantsTest` (« une marge de tirage admise par PlatformLimits… »).
    $maximumMargin = min(PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN, PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE);

    $refused = [
        ['drawSubstituteMargin', -1],
        ['drawSubstituteMargin', PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN + 1],
        ['drawSubstituteMargin', $maximumMargin + 1],
        ['roomMemoryWindowDays', 0],
        ['roomMemoryWindowRounds', 0],
        ['themeSelectorMinPool', -1],
        ['lobbyBroadcastDebounceMs', -1],
        ['lobbyBroadcastDebounceMs', PlatformLimits::MAX_LOBBY_BROADCAST_DEBOUNCE_MS + 1],
        ['frameCropMaxWidthPercent', PlatformLimits::MIN_FRAME_CROP_MAX_WIDTH_PERCENT - 1],
        ['frameCropMaxWidthPercent', PlatformLimits::MAX_FRAME_CROP_MAX_WIDTH_PERCENT + 1],
        ['frameCropMinWidthPx', PlatformLimits::MIN_FRAME_CROP_MIN_WIDTH_PX - PlatformLimits::FRAME_CROP_WIDTH_MULTIPLE_PX],
        ['frameCropMinWidthPx', PlatformLimits::MAX_FRAME_CROP_MIN_WIDTH_PX + PlatformLimits::FRAME_CROP_WIDTH_MULTIPLE_PX],
        ['frameCropMinWidthPx', PlatformLimits::DEFAULT_FRAME_CROP_MIN_WIDTH_PX + 1],
    ];

    foreach ($refused as [$field, $value]) {
        expect(fn (): PlatformLimits => platformLimitsWith([$field => $value]))
            ->toThrow(InvalidArgumentException::class, $field);
    }

    // Les bornes elles-mêmes sont légales.
    $accepted = [
        ['drawSubstituteMargin', 0],
        ['drawSubstituteMargin', $maximumMargin],
        ['roomMemoryWindowDays', 1],
        ['roomMemoryWindowRounds', 1],
        ['themeSelectorMinPool', 0],
        ['lobbyBroadcastDebounceMs', 0],
        ['lobbyBroadcastDebounceMs', PlatformLimits::MAX_LOBBY_BROADCAST_DEBOUNCE_MS],
        ['frameCropMaxWidthPercent', PlatformLimits::MIN_FRAME_CROP_MAX_WIDTH_PERCENT],
        ['frameCropMaxWidthPercent', PlatformLimits::MAX_FRAME_CROP_MAX_WIDTH_PERCENT],
        ['frameCropMinWidthPx', PlatformLimits::MIN_FRAME_CROP_MIN_WIDTH_PX],
        ['frameCropMinWidthPx', PlatformLimits::MAX_FRAME_CROP_MIN_WIDTH_PX],
    ];

    foreach ($accepted as [$field, $value]) {
        expect(platformLimitsWith([$field => $value])->{$field})->toBe($value, $field);
    }
});

it('refuse une fenêtre d\'historique au-delà du plafond de douze mois', function (): void {
    expect(PlatformLimits::DEFAULT_HISTORY_WINDOW_MONTHS)->toBe(12);

    expect(fn (): PlatformLimits => platformLimitsWith(['historyWindowMonths' => PlatformLimits::DEFAULT_HISTORY_WINDOW_MONTHS + 1]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): PlatformLimits => platformLimitsWith(['historyWindowMonths' => 0]))
        ->toThrow(InvalidArgumentException::class);

    // Une surcharge peut raccourcir la fenêtre, jamais l'allonger.
    expect(platformLimitsWith(['historyWindowMonths' => 1])->historyWindowMonths)->toBe(1);

    platformLimitsConfigure(['history_window_months' => PlatformLimits::DEFAULT_HISTORY_WINDOW_MONTHS + 1]);
    expect(fn (): int => PlatformLimits::historyWindowMonths())->toThrow(InvalidArgumentException::class);
});

it('refuse un plafond de téléversement au-delà de sa valeur par défaut', function (): void {
    expect(fn (): PlatformLimits => platformLimitsWith(['frameUploadMaxKilobytes' => PlatformLimits::DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES + 1]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): PlatformLimits => platformLimitsWith(['frameUploadMaxKilobytes' => 0]))
        ->toThrow(InvalidArgumentException::class);

    expect(platformLimitsWith(['frameUploadMaxKilobytes' => 1])->frameUploadMaxKilobytes)->toBe(1);

    // Au-delà, un envoi trop gros finirait en 419 muet au lieu d'une erreur traduite.
    platformLimitsConfigure(['frame_upload_max_kilobytes' => 2048]);
    expect(fn (): int => PlatformLimits::frameUploadMaxKilobytes())->toThrow(InvalidArgumentException::class);
});

it('un accesseur statique refuse une configuration hors bornes', function (string $key, mixed $value, string $accessor): void {
    platformLimitsConfigure([$key => $value]);

    expect(fn (): mixed => PlatformLimits::{$accessor}())->toThrow(InvalidArgumentException::class);

    // Aucun chemin ne contourne la garde : ni un autre accesseur, ni les bornes
    // de réglage qui en dérivent, ni la construction des réglages par défaut.
    expect(fn (): int => PlatformLimits::tierGraceMs())->toThrow(InvalidArgumentException::class);
    expect(fn (): int => RoomSettingsBounds::maxCapacity())->toThrow(InvalidArgumentException::class);
    expect(fn (): RoomSettings => RoomSettings::defaults())->toThrow(InvalidArgumentException::class);
})->with([
    'sièges sous la capacité minimale' => ['room_seats', RoomSettingsBounds::MIN_CAPACITY - 1, 'roomSeats'],
    'configurations nulles' => ['saved_configs_per_user', 0, 'savedConfigsPerUser'],
    'avatars nuls' => ['avatar_presets', 0, 'avatarPresets'],
    'taux sans manche' => ['success_rate_min_rounds', 0, 'successRateMinRounds'],
    'marge négative' => ['draw_substitute_margin', -1, 'drawSubstituteMargin'],
    'marge au-delà de sequence_index' => ['draw_substitute_margin', PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN + 1, 'drawSubstituteMargin'],
    'fenêtre de jours nulle' => ['room_memory_window_days', 0, 'roomMemoryWindowDays'],
    'fenêtre de manches nulle' => ['room_memory_window_rounds', 0, 'roomMemoryWindowRounds'],
    'seuil négatif' => ['theme_selector_min_pool', -1, 'themeSelectorMinPool'],
    'anti-rebond trop long' => ['lobby_broadcast_debounce_ms', PlatformLimits::MAX_LOBBY_BROADCAST_DEBOUNCE_MS + 1, 'lobbyBroadcastDebounceMs'],
    'recadrage trop large' => ['frame_crop_max_width_percent', PlatformLimits::MAX_FRAME_CROP_MAX_WIDTH_PERCENT + 1, 'frameCropMaxWidthPercent'],
    'largeur hors pas de 16' => ['frame_crop_min_width_px', PlatformLimits::DEFAULT_FRAME_CROP_MIN_WIDTH_PX + 1, 'frameCropMinWidthPx'],
    'valeur non entière' => ['room_seats', '12', 'roomSeats'],
]);

it('expose en prop exactement les clés du contrat, B_max en pourcentage entier par N', function (): void {
    $prop = PlatformLimits::current()->toArray();

    expect(array_keys($prop))->toBe([
        'savedConfigsPerUser',
        'roomSeats',
        'avatarPresets',
        'historyWindowMonths',
        'successRateMinRounds',
        'speedBonusMaxPercent',
    ]);

    expect($prop['speedBonusMaxPercent'])->toBe([2 => 50, 3 => 50, 4 => 33, 5 => 25]);
    expect($prop['roomSeats'])->toBe(PlatformLimits::roomSeats());

    array_walk_recursive($prop, static function (mixed $leaf): void {
        expect($leaf)->toBeInt();
    });

    // Indexé par N : un objet JSON aux clés d'entier, jamais une liste.
    expect(json_encode($prop, JSON_THROW_ON_ERROR))
        ->toContain('"speedBonusMaxPercent":{"2":50,"3":50,"4":33,"5":25}');

    // R-07 : aucun écran joueur n'a besoin des constantes de moteur ni de curation.
    expect($prop)->not->toHaveKeys([
        'speedBonusMaxFraction',
        'tierGraceMs',
        'preloadLeadMs',
        'frameUploadMaxKilobytes',
        'frameCropMaxWidthPercent',
        'frameCropMinWidthPx',
    ]);
});
