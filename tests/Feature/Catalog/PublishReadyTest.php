<?php

use App\Enums\AdminActionType;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Models\AdminAction;
use App\Models\Movie;
use App\Models\User;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Curation\ReadyBatch;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Publier les films prêts — spec 20 § 8.1 bis, D59 du 06/10
|--------------------------------------------------------------------------
|
| Un geste explicite du curateur sur un lot qu'il a lu : les brouillons
| prêts (contenu `clear`, niveaux 1, 3 et 5 en jeu), l'avertissement
| d'ambiguïté de chacun — le lot entier compté comme publié —, et les prêts
| mis de côté faute de clé exacte. Tout ou rien : un lot changé entre
| l'aperçu et le clic ne publie aucun film.
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    Queue::fake();

    $this->curator = User::factory()->curator()->create();
});

/**
 * Un film à la banque couvrant les niveaux donnés, au drapeau donné, clés
 * projetées.
 *
 * @param  list<int>  $levels
 * @param  array<string, mixed>  $attributes
 */
function publishReadyFilm(array $levels = [1, 3, 5], ContentFlag $flag = ContentFlag::Clear, array $attributes = []): Movie
{
    $factory = $flag === ContentFlag::Clear
        ? Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear)
        : Movie::factory()->contentFlag($flag);

    $movie = $factory->create($attributes);

    FrameBank::movieWith($movie, $levels);
    (new AnswerKeyProjector)->project($movie);

    return $movie->refresh();
}

/**
 * L'envoi de l'écran : les films et l'empreinte qu'il montre à l'instant,
 * sauf surcharge.
 *
 * @param  list<int>|null  $ids
 */
function publishReadyPost(?array $ids = null, ?string $digest = null): TestResponse
{
    $batch = app(ReadyBatch::class)->preview();

    return test()
        ->actingAs(test()->curator)
        ->from(route('admin.catalog.ready'))
        ->post(route('admin.catalog.ready.publish'), [
            'movie_ids' => $ids ?? array_column($batch['movies'], 'id'),
            'ambiguity_digest' => $digest ?? $batch['digest'],
        ]);
}

test('l\'écran liste les seuls brouillons prêts et met de côté ceux dont aucun titre ne se tape', function (): void {
    $ready = publishReadyFilm(attributes: ['title_original' => 'Phare des Brumes', 'release_year' => 2001]);
    $unguessable = publishReadyFilm(attributes: ['title_original' => '¿ ! … ?', 'title_original_latin' => null]);

    // Ni contenu à vérifier, ni couverture incomplète, ni film déjà publié,
    // dépublié ou écarté : leur publication reste un geste à l'unité.
    publishReadyFilm(flag: ContentFlag::UnratedPending);
    publishReadyFilm([1, 3]);
    publishReadyFilm()->forceFill(['availability' => ContentAvailability::Published, 'first_published_at' => now()])->save();
    publishReadyFilm()->forceFill(['availability' => ContentAvailability::Unpublished, 'first_published_at' => now()])->save();
    publishReadyFilm()->forceFill(['availability' => ContentAvailability::Unpublished])->save();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.ready'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/ready')
            ->where('batch.movies', [[
                'id' => $ready->id,
                'title_original' => 'Phare des Brumes',
                'release_year' => 2001,
                'lines' => [],
            ]])
            ->where('batch.skipped.0.id', $unguessable->id)
            ->where('batch.skipped.0.blockers', ['not_guessable'])
            ->has('batch.skipped', 1)
            ->where('batch.digest', fn (string $digest): bool => strlen($digest) === 64));
});

test('le lot publie chaque film au nom du curateur, une ligne de journal par film', function (): void {
    $first = publishReadyFilm();
    $second = publishReadyFilm();

    publishReadyPost()
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.index'));

    foreach ([$first, $second] as $movie) {
        $movie->refresh();

        expect($movie->availability)->toBe(ContentAvailability::Published)
            ->and($movie->first_published_at)->not->toBeNull()
            ->and($movie->curated_by_id)->toBe($this->curator->id);
    }

    expect(AdminAction::query()->where('action', AdminActionType::MoviePublished->value)->pluck('subject_id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});

test('l\'avertissement du lot compte les autres films du lot comme publiés', function (): void {
    $departure = publishReadyFilm(attributes: ['title_original' => 'Vent d’Écume : Le Départ']);
    $return = publishReadyFilm(attributes: ['title_original' => 'Vent d’Écume : Le Retour']);

    $movies = collect(app(ReadyBatch::class)->preview()['movies'])->keyBy('id');

    // Aucun des deux n'est publié : seul le lot rend leur préfixe ambigu.
    expect($movies[$departure->id]['lines'])->toBe([[
        'form' => 'vent d ecume',
        'kinds' => [AnswerKeyKind::Prefix->value],
        'movies' => [[
            'id' => $return->id,
            'title_original' => $return->title_original,
            'release_year' => $return->release_year,
            'kind' => AnswerKeyKind::Prefix->value,
        ]],
    ]])
        ->and($movies[$return->id]['lines'][0]['movies'][0]['id'])->toBe($departure->id);
});

test('un lot changé depuis son affichage ne publie aucun film', function (): void {
    $first = publishReadyFilm();
    $second = publishReadyFilm();
    $batch = app(ReadyBatch::class)->preview();

    // Un film du lot a été écarté depuis l'affichage.
    $second->forceFill(['availability' => ContentAvailability::Unpublished])->save();

    publishReadyPost(array_column($batch['movies'], 'id'), $batch['digest'])
        ->assertRedirect(route('admin.catalog.ready'))
        ->assertSessionHasErrors(['ambiguity_digest' => __('admin.catalog.publish_ready.stale')]);

    // Un avertissement a changé : un film publié ailleurs partage désormais
    // le préfixe du lot.
    $third = publishReadyFilm(attributes: ['title_original' => 'Marée : Un']);
    $batch = app(ReadyBatch::class)->preview();
    publishReadyFilm(attributes: ['title_original' => 'Marée : Deux'])
        ->forceFill(['availability' => ContentAvailability::Published, 'first_published_at' => now()])->save();

    publishReadyPost(array_column($batch['movies'], 'id'), $batch['digest'])
        ->assertSessionHasErrors(['ambiguity_digest' => __('admin.catalog.publish_ready.stale')]);

    expect($first->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and($third->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and(AdminAction::query()->count())->toBe(0);
});
