<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Models\Game;
use App\Models\Room;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Colonnes figées de `game` sous MySQL — spec 50 § 12.7, contrat C6 § 2
| (lot L50-7b), V-21
|--------------------------------------------------------------------------
|
| MySQL 8 relit une colonne `json` sous forme NORMALISÉE (clés triées,
| séparateurs `": "` et `", "`) ; dès que l'instantané est lu, `save()` le
| réécrit par le cast, en JSON compact dans l'ordre de `FIELDS`. Une garde
| qui comparerait les chaînes lèverait donc sur toute sauvegarde légitime de
| la partie précédée d'une lecture de l'instantané — le gel, la pause, le
| compteur de manches. La comparaison est faite par VALEUR décodée
| (`RoomSettingsCast`, `ComparesCastableAttributes`). L'identité octet pour
| octet que prouve `RoomSettingsCastTest` ne vaut que sous SQLite.
|
| Groupe `mysql` au niveau du fichier : exclu de `composer test`, joué par le
| job CI `mysql-redis`, et il ÉCHOUE hors de MySQL au lieu de se sauter.
|
*/

pest()->group('mysql');

beforeEach(fn () => requireMysql());

it('relire settings_snapshot puis enregistrer game ne déclenche pas la garde des colonnes figées', function (): void {
    // Des réglages éloignés des défauts : plusieurs clés, des listes, une
    // énumération — la forme normalisée diffère de la forme compacte.
    $settings = RoomSettings::fromInput([
        'roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT,
        'framesPerRound' => RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        'inputDifficulty' => InputDifficulty::Expert->value,
    ]);
    $created = Game::factory()->forRoom(Room::factory()->create())->withSettings($settings)->create();

    // Relue en base : la charge est celle que MySQL rend, jamais la chaîne
    // écrite — le cas est bien exercé.
    $game = Game::query()->findOrFail($created->id);
    $raw = (string) $game->getRawOriginal('settings_snapshot');

    expect($raw)->not->toBe($settings->toJson())
        ->and(json_decode($raw, true))->toEqual(json_decode($settings->toJson(), true));

    // Lire l'instantané, puis écrire une colonne vivante : aucune colonne
    // figée n'est vue changée, la sauvegarde passe, la valeur reste.
    expect($game->settings_snapshot->equals($settings))->toBeTrue()
        ->and($game->changedFrozenColumns())->toBe([]);

    $game->forceFill(['total_paused_ms' => 1_250])->save();

    expect($game->isDirty())->toBeFalse();

    // Le gel relit la partie sous verrou et l'écrit : l'instantané déjà lu
    // sur l'instance de l'appelant ne le gêne pas davantage.
    expect(app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, Date::now()->toImmutable()))->toBeTrue();

    $stored = Game::query()->findOrFail($game->id);

    expect($stored->settings_snapshot->equals($settings))->toBeTrue()
        ->and($stored->settings_version)->toBe(RoomSettings::VERSION)
        ->and($stored->total_paused_ms)->toBe(1_250)
        ->and($stored->ended_at)->not->toBeNull();

    // La garde reste active par la valeur : un instantané différent lève, et
    // la ligne ne change pas.
    $before = (array) DB::table('game')->where('id', $game->id)->first();

    expect(fn () => $stored->forceFill([
        'settings_snapshot' => RoomSettings::fromInput(['roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT + 1]),
    ])->save())->toThrow(LogicException::class, 'settings_snapshot');

    expect((array) DB::table('game')->where('id', $game->id)->first())->toBe($before);
});
