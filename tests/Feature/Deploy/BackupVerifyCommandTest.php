<?php

use App\Enums\Locale;
use App\Models\Frame;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| La vérification d'une restauration — spec 100 § 13.5, étape (e), lot L100-10
|--------------------------------------------------------------------------
|
| `backup:verify` réussit si et seulement si chaque frame publiée a son
| fichier de jeu présent, au condensat que cite sa revue (`published_hash`).
| Les frames non publiées n'entrent pas dans le compte.
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
});

/**
 * @param  array<string, int>  $replace
 */
function backupVerifyMessage(string $key, array $replace): string
{
    $line = trans('admin.console.backup.'.$key, $replace, Locale::French->value);

    expect($line)->toBeString()->not->toBe('admin.console.backup.'.$key);

    return (string) $line;
}

it('réussit quand chaque frame publiée a son fichier de jeu au bon condensat', function (): void {
    Frame::factory()->count(3)->published()->create();

    // Une frame non publiée sans fichier ne compte pas.
    $draft = Frame::factory()->withFiles()->create();
    Storage::disk(FrameStoragePrefix::DISK)->delete((string) $draft->game_path);

    $this->artisan('backup:verify')
        ->expectsOutputToContain(backupVerifyMessage('verify_ok', ['count' => 3]))
        ->assertExitCode(0);
});

it('échoue en nommant le nombre de fichiers manquants ou altérés', function (): void {
    Frame::factory()->published()->create();
    $missing = Frame::factory()->published()->create();
    $altered = Frame::factory()->published()->create();
    $unhashed = Frame::factory()->published()->create();

    $disk = Storage::disk(FrameStoragePrefix::DISK);
    $disk->delete((string) $missing->game_path);
    $disk->put((string) $altered->game_path, 'octets-alteres-'.bin2hex(random_bytes(8)));
    $unhashed->forceFill(['published_hash' => null])->saveQuietly();

    $this->artisan('backup:verify')
        ->expectsOutputToContain(backupVerifyMessage('verify_failed', [
            'count' => 3,
            'checked' => 4,
            'missing' => 1,
            'altered' => 2,
        ]))
        ->assertExitCode(1);
});

it('compte comme manquant un dérivé publié dont le chemin sort du préfixe game/', function (): void {
    $frame = Frame::factory()->published()->create();
    $disk = Storage::disk(FrameStoragePrefix::DISK);

    $disk->put('ailleurs/'.basename((string) $frame->game_path), (string) $disk->get((string) $frame->game_path));
    $frame->forceFill(['game_path' => 'ailleurs/'.basename((string) $frame->game_path)])->saveQuietly();

    $this->artisan('backup:verify')
        ->expectsOutputToContain(backupVerifyMessage('verify_failed', [
            'count' => 1,
            'checked' => 1,
            'missing' => 1,
            'altered' => 0,
        ]))
        ->assertExitCode(1);
});
