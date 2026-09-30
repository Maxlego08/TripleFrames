<?php

use App\Actions\Room\LaunchGame;
use App\Enums\Locale;
use App\Enums\SettingPresetKey;
use App\Models\Player;
use App\Models\Round;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Identity\PlayerToken;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Presets livrés — contrat C0, spec 50 § 5, contrat C18 (jeu `room_settings.presets`)
|--------------------------------------------------------------------------
|
| Un preset livré par le site ne doit jamais produire un salon que l'hôte
| n'aurait pas pu régler lui-même : il passe par le chemin d'entrée de l'hôte
| (`fromInput()`), n'y poste que des clés de l'onglet Simple, et n'y lève
| aucune erreur. Un chiffrage fautif lève ici, et non dans un lobby.
|
| « Jamais inlançable » s'entend des bornes (§ 5.2, A-12) ; le vivier est un
| état d'exécution. Sur le catalogue de démonstration — tous ses films
| éligibles à chaque `N` des bornes —, chaque preset doit aussi passer la
| transaction de lancement elle-même (lot L50-7a).
|
*/

it('construit chaque preset livré par le chemin d\'entrée de l\'hôte sans erreur', function (SettingPresetKey $preset): void {
    $input = SettingPresetCatalog::inputFor($preset);

    // Seules des clés que l'onglet Simple, seul onglet du J1, sait poster.
    expect(array_values(array_diff(array_keys($input), RoomSettingsEditor::SIMPLE_KEYS)))->toBe([]);

    expect(RoomSettings::validate($input))->toBe([]);

    $settings = RoomSettings::fromInput($input);

    expect($settings->sourceVersion)->toBe(RoomSettings::VERSION);
    expect($settings->advanced)->toBeFalse();
    expect(SettingPresetCatalog::settingsFor($preset)->equals($settings))->toBeTrue();
})->with('room_settings.presets');

it('garde chaque preset livré lançable sur le catalogue de démonstration', function (): void {
    // Le catalogue de démonstration (10 § 13.3) : de vrais films, de vraies
    // variantes, des octets réels sur un disque `frames` simulé.
    Storage::fake(FrameStoragePrefix::DISK);
    $this->seed(DatabaseSeeder::class);

    foreach (SettingPresetKey::cases() as $preset) {
        $settings = SettingPresetCatalog::settingsFor($preset);
        [$room, $host] = LobbyWrites::hostedRoom(PlayerToken::mint(Locale::French), $settings);
        Player::factory()->for($room)->create();

        // Lançable par la transaction de lancement elle-même : garde de vivier
        // rejouée, tirage, matérialisation, programmation de la manche 1.
        $outcome = app(LaunchGame::class)->handle($room, $host);

        expect($outcome->isLaunched())->toBeTrue("Le preset [{$preset->value}] n'est pas lançable : ".($outcome->refusal?->value ?? '?'))
            ->and($outcome->game?->frames_per_round)->toBe($settings->framesPerRound)
            ->and($outcome->game?->rounds_count)->toBe($settings->roundsCount)
            ->and(Round::query()->whereBelongsTo($outcome->game)->whereNotNull('round_number')->count())
            ->toBe($settings->roundsCount);
    }
});
