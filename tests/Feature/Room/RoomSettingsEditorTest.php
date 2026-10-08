<?php

use App\Models\Theme;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Settings\RoomSettingsEditor;
use App\Support\Draw\PoolQuery;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Éditeur Simple et règle D34 — contrat C0 § 3.2 et § 3.3, spec 50 § 3
|--------------------------------------------------------------------------
|
| L'éditeur est une fonction pure : il compose la charge postée par l'onglet
| Simple avec l'état courant du salon et rend l'entrée complète de
| `fromInput()`, avec son rapport. Les branches `reset` et `equalized` sont
| prouvées ici sur des réglages construits par `fromInput()`, comme les écrit
| l'onglet Avancé (L50-10, `RoomSettingsAdvancedTest`). Aucune valeur de jeu n'est écrite en littéral : tout vient de
| `RoomSettingsBounds`.
|
*/

/**
 * Réglages courants construits par le seul constructeur borné.
 *
 * @param  array<string, mixed>  $input
 */
function editorCurrent(array $input = []): RoomSettings
{
    return RoomSettings::fromInput($input);
}

/**
 * `simple()` puis `fromInput()`, comme l'action d'écriture.
 *
 * @param  array<string, mixed>  $posted
 * @param  array<string, int>  $themes
 * @return array{settings: RoomSettings, input: array<string, mixed>, changes: array<string, string>}
 */
function editorApply(RoomSettings $current, array $posted, array $themes = []): array
{
    $edited = RoomSettingsEditor::simple($current, $posted, $themes);

    return [
        'settings' => RoomSettingsEditor::toSettings($edited['input']),
        'input' => $edited['input'],
        'changes' => $edited['changes'],
    ];
}

/**
 * Les erreurs d'une `ValidationException` levée par `$attempt`.
 *
 * @return array<string, list<string>>
 */
function editorErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        /** @var array<string, list<string>> */
        return $exception->errors();
    }

    throw new LogicException('Une ValidationException était attendue.');
}

it('en Simple, redérive attemptsPerRound resté au défaut de l\'ancien D', function (): void {
    $current = editorCurrent();
    $before = $current->roundDuration();
    $after = $before + Bounds::ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT * 5;

    expect($current->attemptsPerRound)->toBe(Bounds::defaultAttemptsPerRound($before))
        ->and(Bounds::defaultAttemptsPerRound($after))->not->toBe($current->attemptsPerRound);

    $result = editorApply($current, [RoomSettings::INPUT_ROUND_DURATION => $after]);

    expect($result['settings']->attemptsPerRound)->toBe(Bounds::defaultAttemptsPerRound($after))
        ->and($result['settings']->roundDuration())->toBe($after)
        ->and($result['changes'])->toBe([]);

    // Changer N sans toucher D ne change pas le défaut dérivé de D.
    $framesOnly = editorApply($current, ['framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND]);

    expect($framesOnly['settings']->attemptsPerRound)->toBe(Bounds::defaultAttemptsPerRound($before));
});

it('en Simple, conserve un attemptsPerRound personnalisé quand D change', function (): void {
    $custom = Bounds::MAX_ATTEMPTS_PER_ROUND;
    $current = editorCurrent(['attemptsPerRound' => $custom]);

    expect($custom)->not->toBe(Bounds::defaultAttemptsPerRound($current->roundDuration()));

    $after = $current->roundDuration() + Bounds::ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT * 5;
    $result = editorApply($current, [RoomSettings::INPUT_ROUND_DURATION => $after]);

    expect($result['settings']->attemptsPerRound)->toBe($custom)
        ->and($result['settings']->roundDuration())->toBe($after)
        // Conservé, jamais signalé : seuls les paliers et le barème le sont.
        ->and($result['changes'])->toBe([]);
});

it('en Simple, fait suivre un barème par défaut quand N change, sans rapport', function (): void {
    $current = editorCurrent();
    $before = $current->framesPerRound;

    expect($current->tierPoints)->toBe(Bounds::defaultTierPoints($before));

    foreach (range(Bounds::MIN_FRAMES_PER_ROUND, Bounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        if ($framesPerRound === $before) {
            continue;
        }

        $result = editorApply($current, [
            'framesPerRound' => $framesPerRound,
            RoomSettings::INPUT_ROUND_DURATION => max(
                $current->roundDuration(),
                Bounds::minRoundDuration($framesPerRound),
            ),
        ]);

        expect($result['settings']->framesPerRound)->toBe($framesPerRound)
            ->and($result['settings']->tierPoints)->toBe(Bounds::defaultTierPoints($framesPerRound))
            ->and($result['settings']->tierDurations)->toBe(Bounds::defaultTierDurations(
                $framesPerRound,
                $result['settings']->roundDuration(),
            ))
            ->and($result['changes'])->toBe([]);
    }
});

it('en Simple, réinitialise un barème personnalisé quand N change et le rapporte reset', function (): void {
    $framesPerRound = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $custom = array_fill(0, $framesPerRound, Bounds::MAX_TIER_POINTS);
    $current = editorCurrent(['framesPerRound' => $framesPerRound, 'tierPoints' => $custom]);

    expect($current->tierPoints)->not->toBe(Bounds::defaultTierPoints($framesPerRound));

    $lower = Bounds::MIN_FRAMES_PER_ROUND;
    $result = editorApply($current, ['framesPerRound' => $lower]);

    expect($result['settings']->tierPoints)->toBe(Bounds::defaultTierPoints($lower))
        ->and($result['changes'])->toBe(['tierPoints' => RoomSettings::CHANGE_RESET]);

    // N inchangé : le barème personnalisé est conservé, rien n'est rapporté.
    $same = editorApply($current, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT]);

    expect($same['settings']->tierPoints)->toBe($custom)
        ->and($same['changes'])->toBe([]);

    // N posté égal à l'actuel, sous sa forme de formulaire : aucun changement de N.
    $posted = editorApply($current, ['framesPerRound' => (string) $framesPerRound]);

    expect($posted['settings']->tierPoints)->toBe($custom)
        ->and($posted['changes'])->toBe([]);
});

it('en Simple, réégalise des paliers inégaux et le rapporte equalized', function (): void {
    $framesPerRound = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $equal = Bounds::defaultTierDurations($framesPerRound, Bounds::DEFAULT_ROUND_DURATION);
    $unequal = $equal;
    $unequal[0] -= Bounds::MIN_TIER_DURATION;
    $unequal[$framesPerRound - 1] += Bounds::MIN_TIER_DURATION;

    $current = editorCurrent(['framesPerRound' => $framesPerRound, 'tierDurations' => $unequal]);

    expect($current->roundDuration())->toBe(Bounds::DEFAULT_ROUND_DURATION)
        ->and($current->tierDurations)->not->toBe($equal);

    // Une écriture sans rapport avec les paliers les réégalise quand même, sur D.
    $result = editorApply($current, ['revealDuration' => Bounds::MAX_REVEAL_DURATION]);

    expect($result['settings']->tierDurations)->toBe($equal)
        ->and($result['settings']->roundDuration())->toBe($current->roundDuration())
        ->and($result['changes'])->toBe(['tierDurations' => RoomSettings::CHANGE_EQUALIZED])
        ->and($result['input'])->not->toHaveKey('tierDurations');

    // Des paliers déjà égaux, le dernier absorbant le reste, ne sont pas rapportés.
    $uneven = Bounds::MIN_ROUND_DURATION * $framesPerRound + 1;
    $split = editorCurrent([RoomSettings::INPUT_ROUND_DURATION => $uneven]);

    expect(editorApply($split, ['revealDuration' => Bounds::MAX_REVEAL_DURATION])['changes'])->toBe([]);
});

it('ne modifie jamais D ni N de lui-même', function (): void {
    // D au plancher de N = 2 ; passer à N = 5 viole la borne croisée 1. Le
    // serveur ne remonte pas D : c'est le client qui le fait et l'annonce.
    $current = editorCurrent([
        'framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND,
        RoomSettings::INPUT_ROUND_DURATION => Bounds::minRoundDuration(Bounds::MIN_FRAMES_PER_ROUND),
    ]);

    $edited = RoomSettingsEditor::simple($current, ['framesPerRound' => Bounds::MAX_FRAMES_PER_ROUND], []);

    expect($edited['input'][RoomSettings::INPUT_ROUND_DURATION])->toBe($current->roundDuration())
        ->and($edited['input']['framesPerRound'])->toBe(Bounds::MAX_FRAMES_PER_ROUND);

    $errors = editorErrors(fn () => RoomSettingsEditor::toSettings($edited['input']));

    expect(array_keys($errors))->toBe([RoomSettings::INPUT_ROUND_DURATION]);

    // D posté seul : N reste l'actuel.
    $durationOnly = RoomSettingsEditor::simple($current, [RoomSettings::INPUT_ROUND_DURATION => Bounds::MAX_ROUND_DURATION], []);

    expect($durationOnly['input']['framesPerRound'])->toBe($current->framesPerRound)
        ->and(RoomSettingsEditor::toSettings($durationOnly['input'])->framesPerRound)->toBe($current->framesPerRound);

    // Descendre N ne touche pas D.
    $wide = editorCurrent(['framesPerRound' => Bounds::MAX_FRAMES_PER_ROUND, RoomSettings::INPUT_ROUND_DURATION => Bounds::MAX_ROUND_DURATION]);
    $lowered = editorApply($wide, ['framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND]);

    expect($lowered['settings']->roundDuration())->toBe(Bounds::MAX_ROUND_DURATION);

    // Une valeur illisible est transmise telle quelle, jamais coercée : seul le
    // champ fautif est refusé, sans erreur induite sur ses dérivés.
    $defaults = editorCurrent();

    foreach (['framesPerRound', RoomSettings::INPUT_ROUND_DURATION] as $field) {
        foreach (['abc', null, 2.5] as $value) {
            $garbage = RoomSettingsEditor::simple($defaults, [$field => $value], []);

            expect($garbage['input'][$field])->toBe($value)
                ->and(array_keys(editorErrors(fn () => RoomSettingsEditor::toSettings($garbage['input']))))
                ->toBe([$field]);
        }
    }

    // De même pour `advanced` : une valeur illisible n'est jamais ramenée à
    // false en silence, elle passe l'aiguillage et reste refusée par `boolean`.
    foreach (['oui', null, 2] as $value) {
        $garbage = RoomSettingsEditor::edit($defaults, ['advanced' => $value], []);

        expect($garbage['input']['advanced'])->toBe($value)
            ->and(array_keys(editorErrors(fn () => RoomSettingsEditor::toSettings($garbage['input']))))
            ->toBe(['advanced']);
    }
});

it('refuse toute clé hors SIMPLE_KEYS et advanced: true avec not_editable', function (): void {
    app()->setLocale('fr');
    $current = editorCurrent();

    $outside = [
        ...array_values(array_diff(RoomSettingsEditor::ADVANCED_KEYS, RoomSettingsEditor::SIMPLE_KEYS)),
        // Le stockage, le snake_case et les constantes serveur sont des clés hors liste.
        'themeIds', 'rounds_count', 'frames_per_round', 'tierGraceMs', 'preloadLeadMs', 'speedBonusMaxPercent',
    ];

    expect(array_values(array_diff(RoomSettingsEditor::ADVANCED_KEYS, RoomSettingsEditor::SIMPLE_KEYS)))
        ->toBe(['tierDurations', 'tierPoints', 'speedBonus', 'noRepeatMovies', 'attemptsPerSecond', 'attemptsPerRound', 'maxAnswerLength', 'disconnectGraceSeconds']);

    foreach ($outside as $field) {
        $errors = editorErrors(fn () => RoomSettingsEditor::edit($current, [$field => 1, 'roundsCount' => Bounds::MIN_ROUNDS_COUNT], []));
        $label = trans('validation.attributes.'.$field);
        $label = $label === 'validation.attributes.'.$field ? $field : $label;

        expect(array_keys($errors))->toBe([$field])
            ->and($errors[$field])->toBe([__('validation.room_settings.not_editable', ['attribute' => $label])]);
    }

    // Plusieurs clés fautives : une erreur sous chacune.
    expect(array_keys(editorErrors(fn () => RoomSettingsEditor::simple($current, ['tierPoints' => [], 'speedBonus' => false], []))))
        ->toBe(['tierPoints', 'speedBonus']);

    // `advanced: true`, sous toutes ses formes postées : refusé par simple()
    // appelé directement ; l'aiguillage, lui, le route vers l'onglet Avancé,
    // livré par L50-10 (RoomSettingsAdvancedTest).
    expect(RoomSettingsEditor::ADVANCED_TAB_AVAILABLE)->toBeTrue();

    foreach ([true, 1, '1', 'true'] as $value) {
        expect(editorErrors(fn () => RoomSettingsEditor::simple($current, ['advanced' => $value], [])))->toBe([
            'advanced' => [__('validation.room_settings.not_editable', ['attribute' => __('validation.attributes.advanced')])],
        ]);

        $routed = RoomSettingsEditor::edit($current, ['advanced' => $value], []);

        expect(RoomSettingsEditor::toSettings($routed['input'])->advanced)->toBeTrue();
    }

    // Symétrique : l'onglet Avancé appelé directement refuse `advanced: false`.
    expect(editorErrors(fn () => RoomSettingsEditor::advanced($current, ['advanced' => false], [])))->toBe([
        'advanced' => [__('validation.room_settings.not_editable', ['attribute' => __('validation.attributes.advanced')])],
    ]);

    // `advanced: false` est accepté ; chaque clé de SIMPLE_KEYS aussi.
    $accepted = editorApply($current, [
        'themeKeys' => [],
        'roundsCount' => Bounds::MIN_ROUNDS_COUNT,
        'framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND,
        RoomSettings::INPUT_ROUND_DURATION => Bounds::MAX_ROUND_DURATION,
        'revealDuration' => Bounds::MAX_REVEAL_DURATION,
        'inputDifficulty' => 'expert',
        'capacity' => Bounds::MIN_CAPACITY,
        'allowLateJoin' => true,
        'advanced' => false,
    ]);

    expect($accepted['settings']->advanced)->toBeFalse()
        ->and($accepted['settings']->allowLateJoin)->toBeTrue()
        ->and($accepted['settings']->capacity)->toBe(Bounds::MIN_CAPACITY);

    // L'onglet Avancé, livré par L50-10, accepte une charge vide : il bascule
    // l'onglet sans rien changer d'autre.
    expect(RoomSettingsEditor::toSettings(RoomSettingsEditor::advanced($current, [], [])['input'])->advanced)->toBeTrue();
});

it('traduit themeKeys en themeIds et refuse une clé inconnue ou dépubliée', function (): void {
    app()->setLocale('fr');
    [$first, $second] = Theme::factory()->published()->count(2)->create()->all();
    $withdrawn = Theme::factory()->unpublished()->create();
    $published = app(PoolQuery::class)->publishedThemeIdsByKey();

    expect($published)->toHaveKeys([$first->key, $second->key])->not->toHaveKey($withdrawn->key);

    $current = editorCurrent();
    $result = editorApply($current, ['themeKeys' => [$second->key, $first->key, $second->key]], $published);

    // Ordre posté, sans doublon ; `themeKeys` ne passe jamais dans l'entrée.
    expect($result['settings']->themeIds)->toBe([$second->id, $first->id])
        ->and($result['input'])->not->toHaveKey('themeKeys')
        ->and($result['input']['themeIds'])->toBe([$second->id, $first->id]);

    // Liste vide : la sélection est vidée (remède clear_themes).
    $themed = editorCurrent(['themeIds' => [$first->id]]);

    expect(editorApply($themed, ['themeKeys' => []], $published)['settings']->themeIds)->toBe([])
        // Absente : la sélection courante est conservée.
        ->and(editorApply($themed, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], $published)['settings']->themeIds)->toBe([$first->id]);

    $refusal = [__('validation.room_settings.theme_keys', ['attribute' => __('validation.attributes.themeKeys')])];

    foreach ([
        [$withdrawn->key],
        ['inconnu'],
        [$first->key, 'inconnu'],
        [$first->id],
        $first->key,
        ['cle' => $first->key],
        null,
    ] as $keys) {
        expect(editorErrors(fn () => RoomSettingsEditor::simple($current, ['themeKeys' => $keys], $published)))
            ->toBe(['themeKeys' => $refusal]);
    }

    // Les erreurs de fromInput() sur themeIds sont réindexées sous themeKeys.
    $errors = editorErrors(fn () => RoomSettingsEditor::toSettings(['themeIds' => ['x'], 'roundsCount' => 0]));

    expect(array_keys($errors))->toEqualCanonicalizing(['themeKeys', 'roundsCount'])
        ->and($errors)->not->toHaveKey('themeIds');
});
