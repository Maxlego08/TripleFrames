<?php

use App\Actions\Room\WriteRoomSettings;
use App\Enums\InputDifficulty;
use App\Enums\RoomRefusal;
use App\Models\Room;
use App\Models\Theme;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Settings\RoomSettingsEditor;
use App\Support\Room\RoomSettingsPresenter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Scoring\ScoringTypes;

/*
|--------------------------------------------------------------------------
| Charges des réglages et comparaison par valeur — ajouts du lot L50-2
|--------------------------------------------------------------------------
|
| Aucun intitulé de la spec ne prouve à lui seul ces trois garanties, dont
| dépendent les lots suivants : le miroir client `types/room-settings.ts`
| (importé par le fil de la spec 60), la forme de `RoomSettingsView` et la
| comparaison par VALEUR des colonnes de réglages (`RoomSettingsCast`,
| spec 50 § 2.5), sans laquelle une simple lecture rendrait un salon « sale »
| sous MySQL, où une colonne `json` est relue normalisée.
|
*/

/**
 * Les littéraux d'une union de chaînes de `types/room-settings.ts`.
 *
 * @return list<string>
 */
function payloadTsUnion(string $declaration): array
{
    $body = substr($declaration, (int) strpos($declaration, '=') + 1);

    // Rien d'autre que des littéraux : un `string` élargirait l'union en silence.
    expect(trim((string) preg_replace("/'[^']*'|\\||;/", '', $body)))->toBe('');

    preg_match_all("/'([^']*)'/", $body, $literals);

    return $literals[1];
}

/**
 * @param  class-string  $class
 * @return list<string>
 */
function payloadConstants(string $class, string $prefix): array
{
    $values = [];

    foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
        if (str_starts_with($constant->getName(), $prefix) && is_string($constant->getValue())) {
            $values[] = $constant->getValue();
        }
    }

    return $values;
}

it('types/room-settings.ts reflète les codes, les clés et les charges du serveur', function (): void {
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path('js/types/room-settings.ts')));
    $declarations = ScoringTypes::declarations($source);

    // La liste du contrat C0 § 2, et rien d'autre ; le vivier est importé, jamais redéclaré.
    expect(array_keys($declarations))->toBe([
        'InputDifficulty', 'RoomSettingsFieldKey', 'RoomSettingsView', 'RoomSettingsWarningCode',
        'RoomSettingsChangeCode', 'Bound', 'RoomSettingsBoundsForN', 'RoomSettingsBoundsPayload',
        'PlatformLimitsPayload', 'RoomSettingsState', 'RoomRefusalCode',
    ])->and($source)->toContain("import type { PoolReport } from '@/types/pool';");

    $cases = static fn (array $cases): array => array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);

    $unions = [
        'InputDifficulty' => $cases(InputDifficulty::cases()),
        'RoomSettingsWarningCode' => payloadConstants(RoomSettings::class, 'WARNING_'),
        'RoomSettingsChangeCode' => payloadConstants(RoomSettings::class, 'CHANGE_'),
        'RoomRefusalCode' => $cases(RoomRefusal::cases()),
        // Les clés postables des deux onglets, `roundDuration` compris.
        'RoomSettingsFieldKey' => array_values(array_unique([...RoomSettingsEditor::SIMPLE_KEYS, ...RoomSettingsEditor::ADVANCED_KEYS])),
    ];

    foreach ($unions as $type => $expected) {
        $literals = payloadTsUnion($declarations[$type]);

        sort($literals);
        sort($expected);

        expect($literals)->toBe($expected, $type);
    }

    // Les champs des charges sont exactement les clés que le serveur rend.
    $view = RoomSettingsPresenter::view(RoomSettings::defaults());
    $bounds = Bounds::toClient();
    $limits = PlatformLimits::current()->toArray();

    expect(ScoringTypes::objectFields($declarations['RoomSettingsView']))->toBe([array_keys($view)])
        ->and(ScoringTypes::objectFields($declarations['RoomSettingsState']))->toBe([['settings', 'warnings', 'advancedActive', 'pool']])
        ->and(ScoringTypes::objectFields($declarations['PlatformLimitsPayload']))->toBe([array_keys($limits)])
        ->and(ScoringTypes::objectFields($declarations['RoomSettingsBoundsPayload']))->toBe([array_keys($bounds)])
        ->and(ScoringTypes::objectFields($declarations['Bound']))->toBe([['min', 'max']])
        ->and(preg_match('/Record<([^,]*),\s*Bound\s*>/', $declarations['RoomSettingsBoundsForN'], $forN))->toBe(1)
        ->and(payloadTsUnion('='.$forN[1]))->toBe(array_keys(Bounds::toArray()));

    // Aucun type n'énumère les valeurs de N : elles ne vivent que dans `bounds`.
    expect($source)->not->toMatch('/[\'"]\d[\'"]\s*\|/');
});

it('la vue des réglages remplace themeIds par themeKeys à la même place, dans l\'ordre de la sélection', function (): void {
    [$first, $second] = Theme::factory()->published()->count(2)->create()->all();
    $withdrawn = Theme::factory()->unpublished()->create();
    $settings = RoomSettings::fromInput(['themeIds' => [$second->id, $withdrawn->id, $first->id]]);

    $view = RoomSettingsPresenter::view($settings);
    $expected = $settings->toPayload();
    unset($expected['themeIds']);

    expect(array_key_first($view))->toBe(RoomSettingsEditor::THEME_KEYS)
        ->and($view['themeKeys'])->toBe([$second->key, $first->key])
        ->and(array_slice($view, 1, null, true))->toBe($expected);

    // Le chemin du J1 — aucune sélection — ne lit aucun thème.
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });

    expect(RoomSettingsPresenter::view(RoomSettings::defaults())['themeKeys'])->toBe([])
        ->and($queries)->toBe(0);

    // Le rapport de changements passe sous les clés client.
    expect(RoomSettingsPresenter::changes([
        'themeIds' => RoomSettings::CHANGE_PRUNED,
        'capacity' => RoomSettings::CHANGE_CLAMPED,
    ]))->toBe([
        'themeKeys' => RoomSettings::CHANGE_PRUNED,
        'capacity' => RoomSettings::CHANGE_CLAMPED,
    ]);
});

it('relire les réglages ne rend pas le salon sale, même sous un JSON réordonné', function (): void {
    $room = Room::factory()->create();

    // Forme que MySQL 8 rend d'une colonne `json` : clés triées, séparateurs
    // espacés. Sous SQLite, la colonne est un texte : on l'écrit telle quelle.
    $payload = $room->settings->toPayload();
    ksort($payload);
    $normalized = str_replace([':', ','], [': ', ', '], (string) json_encode($payload, JSON_THROW_ON_ERROR));
    DB::table('room')->where('id', $room->id)->update(['settings' => $normalized]);

    $fresh = Room::query()->findOrFail($room->id);

    expect($fresh->getRawOriginal('settings'))->toBe($normalized)
        ->and($fresh->settings->equals($room->settings))->toBeTrue()
        ->and($fresh->isDirty())->toBeFalse()
        ->and($fresh->getDirty())->toBe([]);

    // L'écrivain unique accepte donc cette instance, lue puis réécrite.
    DB::transaction(fn () => app(WriteRoomSettings::class)->handle(
        $fresh,
        RoomSettings::fromInput(['roundsCount' => Bounds::MIN_ROUNDS_COUNT]),
        Date::now()->toImmutable(),
    ));

    expect(Room::query()->findOrFail($room->id)->settings->roundsCount)->toBe(Bounds::MIN_ROUNDS_COUNT);

    // Une valeur différente, elle, est sale ; la même valeur reposée ne l'est pas.
    $other = Room::query()->findOrFail($room->id);
    $other->settings = RoomSettings::fromInput(['roundsCount' => Bounds::MIN_ROUNDS_COUNT]);

    expect($other->isDirty('settings'))->toBeFalse();

    $other->settings = RoomSettings::defaults();

    expect($other->isDirty('settings'))->toBeTrue();

    // La même valeur sous une autre version ne décrit pas les mêmes réglages :
    // relue normalisée, puis reposée sous une version antérieure, elle est sale.
    $current = Room::query()->findOrFail($room->id)->settings->toPayload();
    ksort($current);
    DB::table('room')->where('id', $room->id)->update([
        'settings' => str_replace([':', ','], [': ', ', '], (string) json_encode($current, JSON_THROW_ON_ERROR)),
    ]);

    $older = Room::query()->findOrFail($room->id);
    $older->settings = RoomSettings::fromStorage($older->settings->toPayload(), RoomSettings::VERSION - 1);

    expect($older->isDirty('settings'))->toBeTrue()
        ->and($older->isDirty('settings_version'))->toBeTrue();
});
