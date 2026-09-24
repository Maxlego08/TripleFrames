<?php

use App\Enums\AnswerKeyKind;
use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| catalog:reproject — spec 10 § 3.2 (règle 3), contrat C18-bis
|--------------------------------------------------------------------------
|
| La commande reconstruit `movie_projection` et `answer_key` par les deux
| projecteurs du projet, PAR DIFFÉRENCE : une clé qui existe encore garde
| son identifiant, que `guess` conserve douze mois. Les états périmés sont
| fabriqués ici en écrivant la base DERRIÈRE le dos des projecteurs —
| exactement ce que laissent une restauration, un changement de règle de
| normalisation ou une incrémentation de `Locale::MASK_VERSION`.
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
});

function reprojectMessage(int $movies): string
{
    $line = trans('admin.console.reproject.done', ['movies' => $movies], Locale::French->value);

    expect($line)->toBeString()->not->toBe('admin.console.reproject.done');

    return (string) $line;
}

/**
 * Les clés d'un film, indexées par forme normalisée.
 *
 * @return array<string, AnswerKey>
 */
function reprojectKeys(Movie $movie): array
{
    return AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->get()
        ->keyBy('normalized')
        ->all();
}

/**
 * Photographie des tables que la commande ne doit JAMAIS écrire.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function reprojectSanctuary(): array
{
    $tables = [];

    foreach (['movie', 'frame', 'movie_title', 'alias', 'frame_review'] as $table) {
        $tables[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    return $tables;
}

/**
 * Photographie des projections : toutes les colonnes, horodatages compris
 * pour `answer_key` (une clé réécrite changerait son `updated_at`), sauf la
 * date de fraîcheur de `movie_projection`, qu'une reprojection pose toujours.
 *
 * @return array{answer_key: list<array<string, mixed>>, movie_projection: list<array<string, mixed>>}
 */
function reprojectProjections(): array
{
    return [
        'answer_key' => DB::table('answer_key')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        'movie_projection' => DB::table('movie_projection')->orderBy('movie_id')->get()
            ->map(static function (object $row): array {
                $columns = (array) $row;
                unset($columns['recomputed_at'], $columns['updated_at']);

                return $columns;
            })
            ->all(),
    ];
}

it('reprojette par différence et conserve les identifiants stables de answer_key', function (): void {
    $first = Movie::factory()->playable(
        titles: ['en' => 'Star Harbour: The Crossing', 'fr' => 'Le Port des étoiles'],
        aliases: ['fr' => ['La Traversée', 'Port stellaire']],
    )->create(['title_original' => 'Star Harbour: The Crossing']);

    $second = Movie::factory()->playable(
        titles: ['en' => 'Star Harbour: The Return', 'fr' => 'Le Retour au port'],
        aliases: ['fr' => ['Le Retour']],
    )->create(['title_original' => 'Star Harbour: The Return']);

    $third = Movie::factory()->playable(
        titles: ['en' => 'Quiet Meadow', 'fr' => 'La Prairie tranquille'],
    )->create(['title_original' => 'Quiet Meadow']);

    $prefix = AnswerKeyNormalizer::normalize('Star Harbour');
    $keysBefore = reprojectKeys($first);
    $secondIds = array_map(static fn (AnswerKey $key): int => $key->id, reprojectKeys($second));

    // Le préfixe partagé par deux films publiés est ambigu dès la publication.
    expect($keysBefore[$prefix]->key_kind)->toBe(AnswerKeyKind::Prefix)
        ->and($keysBefore[$prefix]->is_ambiguous)->toBeTrue();

    // --- État périmé, écrit derrière le dos des projecteurs ---------------

    // (a) Une clé qui doit exister a disparu ; une clé qui ne doit pas
    //     exister est là.
    $lost = AnswerKeyNormalizer::normalize('Port stellaire');
    AnswerKey::query()->whereKey($keysBefore[$lost]->id)->delete();

    $stale = new AnswerKey;
    $stale->movie_id = $first->id;
    $stale->key_kind = AnswerKeyKind::Alias;
    $stale->source_locale = 'fr';
    $stale->normalized = 'cle perimee';
    $stale->save();

    // (b) Une clé porte une mauvaise nature : elle doit être requalifiée en
    //     GARDANT son identifiant.
    $requalified = AnswerKeyNormalizer::normalize('La Traversée');
    AnswerKey::query()->whereKey($keysBefore[$requalified]->id)->update(['key_kind' => AnswerKeyKind::Title->value]);

    // (c) Un alias ajouté sans reprojection n'a encore aucune clé.
    Alias::factory()->create([
        'movie_id' => $first->id,
        'locale' => Locale::English->value,
        'alias' => 'The Harbour Crossing',
    ]);

    // (d) L'ambiguïté d'une valeur que rien ne touche est fausse partout : seul
    //     le recompte sur le catalogue entier la rétablit.
    AnswerKey::query()->where('normalized', $prefix)->update(['is_ambiguous' => false]);

    // (e) La projection du premier film ignore une variante publiée et porte
    //     un masque de titres d'une version périmée ; celle du troisième a
    //     disparu.
    Frame::factory()->published()->level(FrameLevel::Level1)->create(['movie_id' => $first->id]);

    MovieProjection::query()->whereKey($first->id)->update([
        'levels_count' => 4,
        'title_locale_mask' => 0,
        'title_mask_version' => 0,
    ]);

    MovieProjection::query()->whereKey($third->id)->delete();

    $sanctuary = reprojectSanctuary();

    // --- Reprojection ------------------------------------------------------

    $this->artisan('catalog:reproject')
        ->expectsOutputToContain(reprojectMessage(3))
        ->assertExitCode(0)
        ->run();

    $keysAfter = reprojectKeys($first);

    // Toute clé qui existait encore garde son identifiant, la requalifiée
    // comprise, qui retrouve sa nature.
    foreach ($keysBefore as $normalized => $key) {
        if ($normalized === $lost) {
            continue;
        }

        expect($keysAfter)->toHaveKey($normalized)
            ->and($keysAfter[$normalized]->id)->toBe($key->id, "Identifiant perdu pour « {$normalized} »");
    }

    expect($keysAfter[$requalified]->key_kind)->toBe(AnswerKeyKind::Alias);

    // La clé perdue renaît, la clé périmée disparaît, l'alias ajouté entre.
    expect($keysAfter)->toHaveKey($lost)
        ->and($keysAfter[$lost]->key_kind)->toBe(AnswerKeyKind::Alias)
        ->and(AnswerKey::query()->whereKey($keysBefore[$lost]->id)->exists())->toBeFalse()
        ->and($keysAfter)->not->toHaveKey('cle perimee')
        ->and(AnswerKey::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and($keysAfter)->toHaveKey(AnswerKeyNormalizer::normalize('The Harbour Crossing'));

    // Le préfixe partagé redevient ambigu sur les DEUX films.
    expect(AnswerKey::query()->where('normalized', $prefix)->pluck('is_ambiguous')->all())->toBe([true, true]);

    // Les projections sont recalculées sur l'état réel, la manquante recréée.
    $projection = MovieProjection::query()->findOrFail($first->id);
    $expectedMask = Locale::English->maskBit() | Locale::French->maskBit();

    expect($projection->levels_count)->toBe(1)
        ->and($projection->level_1_variants)->toBe(1)
        ->and($projection->variants_total)->toBe(1)
        ->and($projection->title_locale_mask)->toBe($expectedMask)
        ->and($projection->title_mask_version)->toBe(Locale::MASK_VERSION);

    $recreated = MovieProjection::query()->find($third->id);

    expect($recreated)->not->toBeNull()
        ->and($recreated?->title_mask_version)->toBe(Locale::MASK_VERSION)
        ->and($recreated?->title_locale_mask)->toBe($expectedMask)
        ->and(MovieProjection::query()->count())->toBe(3);

    // Le second film, à jour, n'a pas bougé : aucune de ses clés n'a changé
    // d'identité, aucune n'est apparue ni n'a disparu.
    $secondAfter = array_map(static fn (AnswerKey $key): int => $key->id, reprojectKeys($second));
    ksort($secondIds);
    ksort($secondAfter);

    expect($secondAfter)->toBe($secondIds)
        ->and($secondIds)->not->toBeEmpty();

    // Rien n'est écrit dans les tables de la règle 12.
    expect(reprojectSanctuary())->toBe($sanctuary);

    // `--movie` borne la reprojection aux films nommés ; une option dont
    // aucune valeur n'est un identifiant ne reprojette RIEN, jamais tout.
    MovieProjection::query()->whereKey($second->id)->update(['title_mask_version' => 0]);
    MovieProjection::query()->whereKey($third->id)->update(['title_mask_version' => 0]);

    $this->artisan('catalog:reproject', ['--movie' => [(string) $second->id]])
        ->expectsOutputToContain(reprojectMessage(1))
        ->assertExitCode(0)
        ->run();

    expect(MovieProjection::query()->findOrFail($second->id)->title_mask_version)->toBe(Locale::MASK_VERSION)
        ->and(MovieProjection::query()->findOrFail($third->id)->title_mask_version)->toBe(0);

    $this->artisan('catalog:reproject', ['--movie' => ['abc']])
        ->expectsOutputToContain(reprojectMessage(0))
        ->assertExitCode(0)
        ->run();

    expect(MovieProjection::query()->findOrFail($third->id)->title_mask_version)->toBe(0);
});

it('est idempotente', function (): void {
    Movie::factory()->playable(
        titles: ['en' => 'Lantern Keepers: First Light', 'fr' => 'Les Gardiens de la lanterne'],
        aliases: ['fr' => ['Première Lumière']],
    )->create(['title_original' => 'Lantern Keepers: First Light']);

    Movie::factory()->playable(
        titles: ['en' => 'Lantern Keepers: Last Light', 'fr' => 'Le Dernier Feu'],
    )->create(['title_original' => 'Lantern Keepers: Last Light']);

    $withFrame = Movie::factory()->playable()->create();
    Frame::factory()->published()->level(FrameLevel::Level3)->create(['movie_id' => $withFrame->id]);

    // Un catalogue d'abord désaligné, pour que le premier passage ait du
    // travail : une projection manquante, une clé manquante.
    MovieProjection::query()->whereKey($withFrame->id)->delete();
    AnswerKey::query()->where('movie_id', $withFrame->id)->where('key_kind', AnswerKeyKind::Alias->value)->delete();

    $this->artisan('catalog:reproject')
        ->expectsOutputToContain(reprojectMessage(3))
        ->assertExitCode(0)
        ->run();

    $afterFirst = reprojectProjections();
    $sanctuary = reprojectSanctuary();

    // Une heure plus tard, le second passage ne change RIEN : même lignes,
    // mêmes identifiants, même `updated_at` sur chaque clé — aucune n'a été
    // réécrite.
    $this->travel(1)->hours();

    $this->artisan('catalog:reproject')
        ->expectsOutputToContain(reprojectMessage(3))
        ->assertExitCode(0)
        ->run();

    expect(reprojectProjections())->toBe($afterFirst)
        ->and(reprojectSanctuary())->toBe($sanctuary)
        ->and(MovieProjection::query()->findOrFail($withFrame->id)->levels_count)->toBe(1);

    // Un troisième passage, pour faire bonne mesure : toujours identique.
    $this->artisan('catalog:reproject')->assertExitCode(0)->run();

    expect(reprojectProjections())->toBe($afterFirst);
});
