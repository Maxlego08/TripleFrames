<?php

use App\Actions\Room\ApplyRoomPreset;
use App\Actions\Room\UpdateRoomSettings;
use App\Enums\Locale;
use App\Enums\PoolFault;
use App\Enums\PoolRemedyKind;
use App\Enums\SettingPresetKey;
use App\Models\Player;
use App\Models\Room;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use App\Support\Identity\PlayerToken;
use App\Support\Room\RoomSettingsPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Onglet Avancé — spec 50 § 3.3, § 5.3, § 9.2 (lot L50-10)
|--------------------------------------------------------------------------
|
| L'éditeur Avancé ne dérive rien : `D` est la somme des paliers, une liste
| de mauvaise taille est refusée, et le seul rapport propre est `reset` du
| barème quand `N` change. La bascule vers l'onglet Simple réégalise les
| paliers et conserve tout le reste ; un preset rapporte `overwritten` pour
| chaque réglage avancé personnalisé qu'il écrase ; le remède
| `disable_no_repeat` est rendu. Aucune valeur de jeu n'est écrite en
| littéral : tout vient de `RoomSettingsBounds`.
|
*/

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-10-08 10:12:31.250');
    $this->travelTo($this->now);
});

/**
 * Réglages à l'onglet Avancé, construits par le seul constructeur borné.
 *
 * @param  array<string, mixed>  $input
 */
function advancedCurrent(array $input = []): RoomSettings
{
    return RoomSettings::fromInput(['advanced' => true, ...$input]);
}

/**
 * L'aiguillage de l'éditeur puis `fromInput()`, comme l'action d'écriture.
 *
 * @param  array<string, mixed>  $posted
 * @return array{settings: RoomSettings, changes: array<string, string>}
 */
function advancedApply(RoomSettings $current, array $posted): array
{
    $edited = RoomSettingsEditor::edit($current, $posted, []);

    return [
        'settings' => RoomSettingsEditor::toSettings($edited['input']),
        'changes' => $edited['changes'],
    ];
}

/**
 * Les erreurs d'une `ValidationException` levée par `$attempt`.
 *
 * @return array<string, list<string>>
 */
function advancedErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        /** @var array<string, list<string>> */
        return $exception->errors();
    }

    throw new LogicException('Une ValidationException était attendue.');
}

/**
 * Paliers inégaux et légaux pour `N` : le premier au plancher, le dernier
 * absorbant le reste de `D` par défaut.
 *
 * @return list<int>
 */
function advancedUnequalTiers(int $framesPerRound): array
{
    $tiers = Bounds::defaultTierDurations($framesPerRound, Bounds::DEFAULT_ROUND_DURATION);
    $last = count($tiers) - 1;
    $tiers[$last] += $tiers[0] - Bounds::MIN_TIER_DURATION;
    $tiers[0] = Bounds::MIN_TIER_DURATION;

    return $tiers;
}

/**
 * Un salon au lobby, son hôte, et le siège de l'hôte.
 *
 * @return array{0: Room, 1: Player}
 */
function advancedRoom(RoomSettings $settings): array
{
    $room = Room::factory()->withSettings($settings)->create();
    $host = Player::factory()->for($room)->create();

    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

    return [$room->refresh(), $host];
}

it('en Avancé, prend D comme somme des paliers et refuse roundDuration', function (): void {
    app()->setLocale('fr');
    $frames = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $tiers = advancedUnequalTiers($frames);
    $current = advancedCurrent();

    // Paliers postés seuls : `D` devient leur somme, rien n'est réégalisé.
    $result = advancedApply($current, ['tierDurations' => $tiers]);

    expect($result['settings']->tierDurations)->toBe($tiers)
        ->and($result['settings']->roundDuration())->toBe(array_sum($tiers))
        ->and($result['settings']->advanced)->toBeTrue()
        ->and($result['changes'])->toBe([]);

    // `roundDuration` n'est pas une clé de l'onglet Avancé.
    $label = __('validation.attributes.roundDuration');
    $label = $label === 'validation.attributes.roundDuration' ? 'roundDuration' : $label;

    expect(advancedErrors(fn () => RoomSettingsEditor::edit($current, [
        RoomSettings::INPUT_ROUND_DURATION => Bounds::MAX_ROUND_DURATION,
    ], [])))->toBe([
        RoomSettings::INPUT_ROUND_DURATION => [__('validation.room_settings.not_editable', ['attribute' => $label])],
    ]);

    // Chaque champ propre à l'onglet Avancé est accepté, à ses bornes.
    $accepted = advancedApply($current, [
        'tierPoints' => array_fill(0, $frames, Bounds::MAX_TIER_POINTS),
        'speedBonus' => false,
        'noRepeatMovies' => false,
        'attemptsPerSecond' => Bounds::MAX_ATTEMPTS_PER_SECOND,
        'attemptsPerRound' => Bounds::MAX_ATTEMPTS_PER_ROUND,
        'maxAnswerLength' => Bounds::MIN_ANSWER_LENGTH,
        'disconnectGraceSeconds' => Bounds::MAX_DISCONNECT_GRACE_SECONDS,
    ])['settings'];

    expect($accepted->tierPoints)->toBe(array_fill(0, $frames, Bounds::MAX_TIER_POINTS))
        ->and($accepted->speedBonus)->toBeFalse()
        ->and($accepted->noRepeatMovies)->toBeFalse()
        ->and($accepted->attemptsPerSecond)->toBe(Bounds::MAX_ATTEMPTS_PER_SECOND)
        ->and($accepted->attemptsPerRound)->toBe(Bounds::MAX_ATTEMPTS_PER_ROUND)
        ->and($accepted->maxAnswerLength)->toBe(Bounds::MIN_ANSWER_LENGTH)
        ->and($accepted->disconnectGraceSeconds)->toBe(Bounds::MAX_DISCONNECT_GRACE_SECONDS)
        // Rien n'est dérivé : `attemptsPerRound` ne suit plus `D`.
        ->and(advancedApply($accepted, ['tierDurations' => $tiers])['settings']->attemptsPerRound)
        ->toBe(Bounds::MAX_ATTEMPTS_PER_ROUND);

    // Depuis l'onglet Simple, `advanced: true` bascule vers l'onglet Avancé
    // sans rien changer d'autre.
    $switched = advancedApply(RoomSettings::defaults(), ['advanced' => true]);

    expect($switched['settings']->advanced)->toBeTrue()
        ->and($switched['settings']->equals(advancedCurrent()))->toBeTrue()
        ->and($switched['changes'])->toBe([]);

    // Une borne croisée 2 reste bloquante : un palier sous le plancher.
    $tooShort = $tiers;
    $tooShort[0] = Bounds::MIN_TIER_DURATION - 1;

    expect(array_keys(advancedErrors(fn () => advancedApply($current, ['tierDurations' => $tooShort]))))
        ->toBe(['tierDurations.0']);
});

it('en Avancé, refuse une liste de paliers ou de points de mauvaise taille sans rien dériver', function (): void {
    $before = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $after = $before + 1;
    $current = advancedCurrent();

    // `N` changé sans les deux listes : le serveur n'invente aucune durée ni
    // aucune valeur, chaque liste est refusée sous son champ, sans erreur par
    // palier.
    expect(array_keys(advancedErrors(fn () => advancedApply($current, ['framesPerRound' => $after]))))
        ->toBe(['tierDurations', 'tierPoints']);

    // Une liste de mauvaise taille, l'autre juste : seule la fautive est refusée.
    $duration = max($current->roundDuration(), Bounds::minRoundDuration($after));

    expect(array_keys(advancedErrors(fn () => advancedApply($current, [
        'framesPerRound' => $after,
        'tierDurations' => Bounds::defaultTierDurations($after, $duration),
    ]))))->toBe(['tierPoints'])
        ->and(array_keys(advancedErrors(fn () => advancedApply($current, [
            'tierPoints' => Bounds::defaultTierPoints($after),
        ]))))->toBe(['tierPoints']);

    // Les deux listes redimensionnées, comme le client les poste : accepté.
    $resized = advancedApply($current, [
        'framesPerRound' => $after,
        'tierDurations' => Bounds::defaultTierDurations($after, $duration),
        'tierPoints' => Bounds::defaultTierPoints($after),
    ]);

    expect($resized['settings']->framesPerRound)->toBe($after)
        ->and($resized['settings']->tierDurations)->toBe(Bounds::defaultTierDurations($after, $duration))
        ->and($resized['settings']->tierPoints)->toBe(Bounds::defaultTierPoints($after))
        // Barème par défaut avant le changement : aucun rapport.
        ->and($resized['changes'])->toBe([]);
});

it('rapporte reset quand un changement de N remplace un barème personnalisé', function (): void {
    $before = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $after = $before - 1;
    $custom = array_fill(0, $before, Bounds::MAX_TIER_POINTS);
    $current = advancedCurrent(['tierPoints' => $custom]);
    $posted = [
        'framesPerRound' => $after,
        'tierDurations' => Bounds::defaultTierDurations($after, $current->roundDuration()),
        'tierPoints' => Bounds::defaultTierPoints($after),
    ];

    $result = advancedApply($current, $posted);

    expect($result['changes'])->toBe(['tierPoints' => RoomSettings::CHANGE_RESET])
        ->and($result['settings']->tierPoints)->toBe(Bounds::defaultTierPoints($after))
        ->and($result['settings']->advanced)->toBeTrue();

    // `N` inchangé : un barème personnalisé reposté n'est jamais « réinitialisé ».
    expect(advancedApply($current, ['tierPoints' => $custom])['changes'])->toBe([]);

    // Par la route, le rapport revient à l'auteur en flash, sous les clés client.
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token, $current);
    LobbyWrites::actAs($this, $token);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, $posted, $host)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('settingsChanges', ['tierPoints' => RoomSettings::CHANGE_RESET]);

    expect(Room::query()->findOrFail($room->id)->frames_per_round)->toBe($after);
});

it('au retour en Simple, réégalise les paliers, conserve les autres réglages avancés et le rapporte', function (): void {
    $frames = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $tiers = advancedUnequalTiers($frames);
    $custom = array_fill(0, $frames, Bounds::MAX_TIER_POINTS);
    $current = advancedCurrent([
        'tierDurations' => $tiers,
        'tierPoints' => $custom,
        'speedBonus' => false,
        'noRepeatMovies' => false,
        'attemptsPerSecond' => Bounds::MAX_ATTEMPTS_PER_SECOND,
        'attemptsPerRound' => Bounds::MAX_ATTEMPTS_PER_ROUND,
        'maxAnswerLength' => Bounds::MAX_ANSWER_LENGTH,
        'disconnectGraceSeconds' => Bounds::MIN_DISCONNECT_GRACE_SECONDS,
    ]);

    $result = advancedApply($current, ['advanced' => false]);
    $settings = $result['settings'];

    expect($result['changes'])->toBe(['tierDurations' => RoomSettings::CHANGE_EQUALIZED])
        ->and($settings->advanced)->toBeFalse()
        ->and($settings->tierDurations)->toBe(Bounds::defaultTierDurations($frames, array_sum($tiers)))
        ->and($settings->roundDuration())->toBe(array_sum($tiers))
        ->and($settings->tierPoints)->toBe($custom)
        ->and($settings->speedBonus)->toBeFalse()
        ->and($settings->noRepeatMovies)->toBeFalse()
        ->and($settings->attemptsPerSecond)->toBe(Bounds::MAX_ATTEMPTS_PER_SECOND)
        ->and($settings->attemptsPerRound)->toBe(Bounds::MAX_ATTEMPTS_PER_ROUND)
        ->and($settings->maxAnswerLength)->toBe(Bounds::MAX_ANSWER_LENGTH)
        ->and($settings->disconnectGraceSeconds)->toBe(Bounds::MIN_DISCONNECT_GRACE_SECONDS);

    // Le bandeau de l'onglet Simple liste ces réglages, dans l'ordre de
    // FIELDS — les paliers, réégalisés, n'y sont plus.
    expect(RoomSettingsEditor::customizedAdvancedFields($settings))->toBe([
        'tierPoints', 'speedBonus', 'noRepeatMovies', 'attemptsPerSecond', 'attemptsPerRound',
        'maxAnswerLength', 'disconnectGraceSeconds',
    ]);

    [$room] = advancedRoom($settings);
    PoolFixtures::fakeFramesDisk();

    expect(RoomSettingsPresenter::state($room, $this->now)['advancedActive'])
        ->toBe(RoomSettingsEditor::customizedAdvancedFields($settings))
        ->and(RoomSettingsPresenter::state(advancedRoom(RoomSettings::defaults())[0], $this->now)['advancedActive'])
        ->toBe([]);

    // Des paliers égaux ne sont jamais rapportés `equalized`.
    expect(advancedApply(advancedCurrent(), ['advanced' => false])['changes'])->toBe([]);
});

it('rapporte overwritten pour chaque réglage avancé personnalisé qu\'un preset écrase', function (): void {
    $frames = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $preset = SettingPresetCatalog::settingsFor(SettingPresetKey::Classic);

    // Personnalisés : paliers, barème, bonus, longueur de réponse. Un
    // réglage avancé laissé à son défaut n'est jamais rapporté ; un réglage
    // de l'onglet Simple (révélation, retardataires) non plus.
    [$room, $host] = advancedRoom(advancedCurrent([
        'tierDurations' => advancedUnequalTiers($frames),
        'tierPoints' => array_fill(0, $frames, Bounds::MAX_TIER_POINTS),
        'speedBonus' => false,
        'maxAnswerLength' => Bounds::MAX_ANSWER_LENGTH,
        'revealDuration' => Bounds::MAX_REVEAL_DURATION,
        'allowLateJoin' => true,
    ]));

    expect($preset->speedBonus)->toBe(Bounds::DEFAULT_SPEED_BONUS)
        ->and($preset->maxAnswerLength)->toBe(Bounds::DEFAULT_ANSWER_LENGTH);

    $outcome = app(ApplyRoomPreset::class)->handle($room, $host, SettingPresetKey::Classic);

    expect($outcome->isWritten())->toBeTrue()
        ->and($outcome->changes)->toBe([
            'tierDurations' => RoomSettings::CHANGE_OVERWRITTEN,
            'tierPoints' => RoomSettings::CHANGE_OVERWRITTEN,
            'speedBonus' => RoomSettings::CHANGE_OVERWRITTEN,
            'maxAnswerLength' => RoomSettings::CHANGE_OVERWRITTEN,
        ])
        ->and(Room::query()->findOrFail($room->id)->settings->equals($preset))->toBeTrue();

    // Personnalisé mais égal à la valeur du preset : rien n'est écrasé. Le
    // barème personnalisé d'un autre N, lui, change de taille : rapporté.
    $hardcore = SettingPresetCatalog::settingsFor(SettingPresetKey::Hardcore);

    expect(RoomSettingsEditor::overwritten(advancedCurrent(['speedBonus' => false]), advancedCurrent(['speedBonus' => false])))
        ->toBe([])
        ->and(RoomSettingsEditor::overwritten(advancedCurrent(['tierPoints' => array_fill(0, $frames, Bounds::MAX_TIER_POINTS)]), $hardcore))
        ->toBe(['tierPoints' => RoomSettings::CHANGE_OVERWRITTEN])
        // Aucun réglage avancé personnalisé : rapport vide.
        ->and(RoomSettingsEditor::overwritten(RoomSettings::defaults(), $hardcore))->toBe([]);

    // Par la route, le rapport revient à l'auteur en flash.
    $token = PlayerToken::mint(Locale::English);
    [$routed, $routedHost] = LobbyWrites::hostedRoom($token, advancedCurrent(['noRepeatMovies' => false]));
    LobbyWrites::actAs($this, $token);

    LobbyWrites::send($this, 'POST', route('room.settings.preset', $routed), $routed, [
        'preset' => SettingPresetKey::Fast->value,
    ], $routedHost)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('settingsChanges', ['noRepeatMovies' => RoomSettings::CHANGE_OVERWRITTEN]);
});

it('propose l\'interrupteur de non-répétition parmi les remèdes une fois l\'onglet livré', function (): void {
    PoolFixtures::fakeFramesDisk();
    $roundsCount = Bounds::MIN_ROUNDS_COUNT;
    $movies = PoolFixtures::movies($roundsCount);
    [$room, $host] = advancedRoom(RoomSettings::fromInput(['roundsCount' => $roundsCount]));
    PoolFixtures::round(PoolFixtures::game($room), $movies[0], $this->now->subDay());

    expect(RoomSettingsEditor::ADVANCED_TAB_AVAILABLE)->toBeTrue();

    $state = RoomSettingsPresenter::state($room, $this->now);
    $remedies = $state['pool']['remedies'];

    expect($state['pool']['blocked'])->toBeTrue()
        ->and($state['pool']['causes'])->toBe([PoolFault::NoRepeatMovies->value])
        ->and(array_column($remedies, 'kind'))->toBe([
            PoolRemedyKind::OpenNewRoom->value,
            PoolRemedyKind::DisableNoRepeat->value,
        ])
        ->and($remedies[1]['count'])->toBe($roundsCount);

    // Le geste du remède (`{ advanced: true, noRepeatMovies: false }`, § 9.2)
    // passe par l'onglet Avancé, depuis l'onglet Simple, et débloque à lui seul.
    $outcome = app(UpdateRoomSettings::class)->handle($room, $host, ['advanced' => true, 'noRepeatMovies' => false]);
    $after = RoomSettingsPresenter::state($room->refresh(), $this->now);

    expect($outcome->isWritten())->toBeTrue()
        ->and($outcome->changes)->toBe([])
        ->and($room->settings->advanced)->toBeTrue()
        ->and($room->settings->noRepeatMovies)->toBeFalse()
        ->and($after['pool']['blocked'])->toBeFalse()
        ->and($after['pool']['count'])->toBe($roundsCount)
        ->and($after['advancedActive'])->toBe(['noRepeatMovies']);
});

it('avertit waiting_pays quand l\'ouverture d\'un palier paie plus que la fin du précédent, bonus actif seulement', function (): void {
    $frames = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $points = Bounds::defaultTierPoints($frames);
    $points[1] = $points[0] - 1;

    // Strictement décroissant, donc sans `non_decreasing_points`, mais le
    // bonus du deuxième palier fait payer l'attente.
    expect(advancedCurrent(['tierPoints' => $points])->warnings())->toBe([RoomSettings::WARNING_WAITING_PAYS])
        // Bonus coupé : l'attente ne paie plus.
        ->and(advancedCurrent(['tierPoints' => $points, 'speedBonus' => false])->warnings())->toBe([])
        // Jamais en plus de `non_decreasing_points`, qui dit déjà que l'attente paie.
        ->and(advancedCurrent(['tierPoints' => array_fill(0, $frames, Bounds::MAX_TIER_POINTS)])->warnings())
        ->toBe([RoomSettings::WARNING_NON_DECREASING_POINTS]);

    // Aucun barème par défaut ne le déclenche (spec 80 § 3.3).
    for ($n = Bounds::MIN_FRAMES_PER_ROUND; $n <= Bounds::MAX_FRAMES_PER_ROUND; $n++) {
        expect(RoomSettings::fromInput([
            'framesPerRound' => $n,
            RoomSettings::INPUT_ROUND_DURATION => Bounds::MAX_ROUND_DURATION,
        ])->warnings())->not->toContain(RoomSettings::WARNING_WAITING_PAYS);
    }
});
