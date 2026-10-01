<?php

use App\Enums\Locale;
use App\Models\Frame;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Le manifeste du disque frames — spec 100 § 13.2 et § 13.3, lot L100-10
|--------------------------------------------------------------------------
|
| Sans option, `backup:manifest` est la preuve du tier chaud : chaque fichier
| présent, avec sa taille et son SHA-256. Sous `--cold`, c'est le périmètre du
| tier froid de 10 § 10 : les dérivés des frames publiées, plus les deux
| fichiers de toute capture, publiée ou non (D38 du 28/09).
|
| Aucune image réelle : les fichiers sont remplacés par quelques octets
| aléatoires, distincts d'un fichier à l'autre, pour que chaque condensat
| attendu désigne un seul fichier.
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
});

/**
 * Remplace les fichiers d'une frame par des octets aléatoires distincts et
 * aligne `published_hash` sur le nouveau dérivé.
 */
function backupManifestDistinctBytes(Frame $frame): Frame
{
    $disk = Storage::disk(FrameStoragePrefix::DISK);
    $game = 'jeu-'.bin2hex(random_bytes(24));

    $disk->put((string) $frame->game_path, $game);
    $disk->put((string) $frame->master_path, 'master-'.bin2hex(random_bytes(24)));

    $frame->forceFill(['published_hash' => hash('sha256', $game)])->saveQuietly();

    return $frame;
}

/**
 * Le manifeste, ligne par ligne, et son code de sortie.
 *
 * @param  array<string, bool>  $options
 * @return array{int, list<string>}
 */
function backupManifestRun(array $options = []): array
{
    $code = Artisan::call('backup:manifest', $options);
    $lines = array_values(array_filter(
        array_map(trim(...), explode("\n", Artisan::output())),
        static fn (string $line): bool => $line !== '',
    ));

    return [$code, $lines];
}

/**
 * Une ligne `admin.console.backup.<clé>`, dont l'existence est vérifiée.
 *
 * @param  array<string, int>  $replace
 */
function backupManifestMessage(string $key, array $replace): string
{
    $line = trans('admin.console.backup.'.$key, $replace, Locale::French->value);

    expect($line)->toBeString()->not->toBe('admin.console.backup.'.$key);

    return (string) $line;
}

function backupManifestHash(string $path): string
{
    return hash('sha256', (string) Storage::disk(FrameStoragePrefix::DISK)->get($path));
}

it('liste chaque fichier du disque frames avec son condensat', function (): void {
    $published = backupManifestDistinctBytes(Frame::factory()->published()->create());
    $draft = backupManifestDistinctBytes(Frame::factory()->withFiles()->create());

    // Un fichier qu'aucune ligne ne nomme est listé aussi : le manifeste dit
    // ce qui EXISTE sur le disque, pas ce que la base croit.
    Storage::disk(FrameStoragePrefix::DISK)->put('orphelin.bin', 'octets');

    [$code, $lines] = backupManifestRun();

    $disk = Storage::disk(FrameStoragePrefix::DISK);
    $expected = [];

    foreach ([
        (string) $published->game_path,
        (string) $published->master_path,
        (string) $draft->game_path,
        (string) $draft->master_path,
        'orphelin.bin',
    ] as $path) {
        $expected[] = backupManifestHash($path).' '.$disk->size($path).' '.$path;
    }

    sort($expected, SORT_STRING);

    $sortedByPath = $lines;
    usort($sortedByPath, static fn (string $a, string $b): int => strcmp(
        (string) substr($a, (int) strrpos($a, ' ') + 1),
        (string) substr($b, (int) strrpos($b, ' ') + 1),
    ));

    $actual = $lines;
    sort($actual, SORT_STRING);

    expect($code)->toBe(0)
        ->and($actual)->toBe($expected)
        // Trié par chemin, pour qu'un diff entre deux jours soit lisible.
        ->and($lines)->toBe($sortedByPath)
        ->and($published->published_hash)->toBe(backupManifestHash((string) $published->game_path));
});

it('restreint le tier froid aux dérivés publiés et aux fichiers des captures', function (): void {
    $publishedTmdb = backupManifestDistinctBytes(Frame::factory()->published()->create());
    $draftTmdb = backupManifestDistinctBytes(Frame::factory()->withFiles()->create());
    $publishedCapture = backupManifestDistinctBytes(Frame::factory()->capture()->published()->create());
    $draftCapture = backupManifestDistinctBytes(Frame::factory()->capture()->withFiles()->create());
    // Retirée mais fichiers encore présents (job de suppression pas encore
    // passé, ou en échec) : elle reste dans le périmètre, comme le dit 10 § 10 ;
    // takedown:reconcile resupprime les octets après toute restauration (A9).
    $withdrawnCapture = backupManifestDistinctBytes(Frame::factory()->capture()->withFiles()->withdrawn()->create());
    // Retirée et fichiers attestés supprimés : plus rien à sauvegarder.
    $erasedCapture = Frame::factory()->capture()->withFiles()->withdrawn()->create();
    Storage::disk(FrameStoragePrefix::DISK)->delete([(string) $erasedCapture->game_path, (string) $erasedCapture->master_path]);
    $erasedCapture->forceFill(['files_deleted_at' => now()])->saveQuietly();

    [$code, $lines] = backupManifestRun(['--cold' => true]);

    $expected = [];

    foreach ([
        // Dérivé publié d'origine TMDB : son master se reconstruit, lui non.
        (string) $publishedTmdb->game_path,
        // Captures : les deux fichiers, publiée ou non.
        (string) $publishedCapture->game_path,
        (string) $publishedCapture->master_path,
        (string) $draftCapture->game_path,
        (string) $draftCapture->master_path,
        (string) $withdrawnCapture->game_path,
        (string) $withdrawnCapture->master_path,
    ] as $path) {
        $expected[$path] = backupManifestHash($path).' '.$path;
    }

    ksort($expected, SORT_STRING);

    expect($code)->toBe(0)
        ->and($lines)->toBe(array_values($expected))
        ->and(implode("\n", $lines))
        ->not->toContain((string) $publishedTmdb->master_path)
        ->not->toContain((string) $draftTmdb->game_path)
        ->not->toContain((string) $draftTmdb->master_path)
        // Une capture dont les fichiers sont attestés supprimés n'est ni listée
        // ni comptée manquante : le code de sortie reste 0.
        ->not->toContain((string) $erasedCapture->game_path)
        ->not->toContain((string) $erasedCapture->master_path);

    // Un dérivé publié porte, dans le tier froid, le condensat que cite sa revue.
    expect($lines)->toContain($publishedTmdb->published_hash.' '.$publishedTmdb->game_path);
});

it('liste les fichiers présents du tier froid et échoue quand un fichier du périmètre manque', function (): void {
    $kept = backupManifestDistinctBytes(Frame::factory()->published()->create());
    $lost = backupManifestDistinctBytes(Frame::factory()->published()->create());

    Storage::disk(FrameStoragePrefix::DISK)->delete((string) $lost->game_path);

    $code = Artisan::call('backup:manifest', ['--cold' => true]);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain(backupManifestHash((string) $kept->game_path).' '.$kept->game_path)
        ->and($output)->not->toContain((string) $lost->game_path);

    // Le nombre manquant est bien écrit, sur la sortie d'erreur.
    $this->artisan('backup:manifest', ['--cold' => true])
        ->expectsOutputToContain(backupManifestMessage('manifest_missing', ['count' => 1]))
        ->assertExitCode(1);
});

it('écarte du tier froid un dérivé publié dont le condensat diffère de published_hash, et échoue', function (): void {
    $kept = backupManifestDistinctBytes(Frame::factory()->published()->create());
    $altered = backupManifestDistinctBytes(Frame::factory()->published()->create());
    $unhashed = backupManifestDistinctBytes(Frame::factory()->published()->create());

    Storage::disk(FrameStoragePrefix::DISK)->put((string) $altered->game_path, 'octets-alteres-'.bin2hex(random_bytes(8)));
    $unhashed->forceFill(['published_hash' => null])->saveQuietly();

    [$code, $lines] = backupManifestRun(['--cold' => true]);

    // Les octets lus ne sont pas ceux de la revue : ils ne partent sous aucun
    // nom, et l'échec est bruyant plutôt qu'un envoi muet.
    // Artisan::call() mêle sortie et erreur ; en production, la dernière
    // ligne part sur la sortie d'erreur, jamais dans le manifeste.
    expect($code)->toBe(1)
        ->and($lines)->toBe([
            $kept->published_hash.' '.$kept->game_path,
            backupManifestMessage('manifest_altered', ['count' => 2]),
        ]);

    $this->artisan('backup:manifest', ['--cold' => true])
        ->expectsOutputToContain(backupManifestMessage('manifest_altered', ['count' => 2]))
        ->assertExitCode(1);
});

it('échoue quand un fichier du disque frames est illisible, en listant les autres', function (): void {
    $readable = 'game/'.bin2hex(random_bytes(16)).'.webp';
    $unreadable = 'game/'.bin2hex(random_bytes(16)).'.webp';
    $bytes = 'octets-'.bin2hex(random_bytes(8));

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->andReturn([$unreadable, $readable]);
    $disk->shouldReceive('readStream')->with($readable)->andReturnUsing(static function () use ($bytes) {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    });
    $disk->shouldReceive('readStream')->with($unreadable)->andThrow(new RuntimeException('illisible'));
    $disk->shouldNotReceive('size');

    Storage::set(FrameStoragePrefix::DISK, $disk);

    [$code, $lines] = backupManifestRun();

    // Taille et condensat tirés du même flux : aucune lecture de métadonnées
    // séparée ne peut lever si le fichier change entre deux appels.
    expect($code)->toBe(1)
        ->and($lines)->toBe([
            hash('sha256', $bytes).' '.strlen($bytes).' '.$readable,
            backupManifestMessage('manifest_unreadable', ['count' => 1]),
        ]);

    $this->artisan('backup:manifest')
        ->expectsOutputToContain(backupManifestMessage('manifest_unreadable', ['count' => 1]))
        ->assertExitCode(1);
});
