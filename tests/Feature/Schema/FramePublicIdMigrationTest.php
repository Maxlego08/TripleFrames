<?php

use App\Models\Frame;
use App\Support\Identity\PublicId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `frame.public_id` — D63 du 07/10, spec 10 § 4.1
|--------------------------------------------------------------------------
|
| La migration rétro-remplit chaque image existante d'une identité base32
| aléatoire, puis rend la colonne `NOT NULL` et UNIQUE ; une image créée
| ensuite reçoit la sienne du modèle, quelle que soit la voie d'ajout.
|
*/

/** La migration de la colonne, chargée depuis son fichier. */
function framePublicIdMigration(): Migration
{
    return require database_path('migrations/2026_10_07_100057_add_public_id_to_frame_table.php');
}

it('rétro-remplit chaque image existante d’un public_id unique, puis rend la colonne obligatoire', function (): void {
    $frames = Frame::factory()->count(3)->create();
    $migration = framePublicIdMigration();

    $migration->down();
    expect(Schema::hasColumn('frame', 'public_id'))->toBeFalse();

    $migration->up();

    $publicIds = DB::table('frame')->whereIn('id', $frames->modelKeys())->pluck('public_id')->all();

    expect($publicIds)->toHaveCount(3)
        ->and(array_unique($publicIds))->toHaveCount(3);

    foreach ($publicIds as $publicId) {
        expect($publicId)->toBeString()
            ->and(strlen($publicId))->toBe(PublicId::LENGTH)
            ->and(strspn($publicId, PublicId::ALPHABET))->toBe(PublicId::LENGTH);
    }

    // Colonne obligatoire et unique.
    expect(fn () => DB::table('frame')->where('id', $frames[0]->id)->update(['public_id' => null]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('frame')->where('id', $frames[1]->id)->update(['public_id' => $publicIds[2]]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('frappe le public_id d’une image créée sans lui et garde celui qu’impose une fabrique', function (): void {
    $frame = Frame::factory()->make();
    $frame->public_id = null;
    $frame->save();

    expect($frame->public_id)->toHaveLength(PublicId::LENGTH);

    $imposed = Frame::factory()->create(['public_id' => 'ABCDEFGHJKMN']);

    expect($imposed->public_id)->toBe('ABCDEFGHJKMN');
});
