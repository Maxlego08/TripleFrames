<?php

use App\Models\Movie;
use App\Models\User;
use App\Support\Curation\CurationClaim;
use App\Support\Curation\CurationQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Réservation souple multi-curateurs — spec 20 § 4.1, lot L20-32
|--------------------------------------------------------------------------
|
| `curation:claim:{movieId}` en cache, prise à l'ouverture de l'éditeur et
| par « Film suivant », prolongée par le battement, expirée sans lui. Le cache
| de test est `array` : `travel()` le fait expirer comme Redis.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Queue::fake();

    Config::set('catalog.curation.claim_minutes', 15);

    $this->alice = User::factory()->curator()->create(['real_name' => 'Alice Martin']);
    $this->bob = User::factory()->curator()->create(['real_name' => 'Bruno Petit']);
});

test('la file saute un film réservé par un autre curateur', function (): void {
    $first = Movie::factory()->create(['vote_count' => 30_000]);
    $second = Movie::factory()->create(['vote_count' => 20_000]);

    // Alice ouvre l'éditeur du premier film : elle le réserve.
    $this->actingAs($this->alice)->get(route('admin.catalog.bank', $first))->assertOk();

    expect(CurationClaim::holder($first->id))->toBe($this->alice->id);

    // « Film suivant » de Bruno saute le film d'Alice et réserve le suivant.
    $this->actingAs($this->bob)
        ->get(route('admin.curation.next'))
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $second->id]));

    expect(CurationClaim::holder($second->id))->toBe($this->bob->id);

    // Alice, elle, retrouve son propre film en tête : sa réservation ne la gêne pas.
    $this->actingAs($this->alice)
        ->get(route('admin.curation.next'))
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $first->id]));

    // La file montre tout, réservation d'un autre affichée par son nom réel.
    $this->actingAs($this->bob)
        ->get(route('admin.curation.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('movies.data.0.id', $first->id)
            ->where('movies.data.0.claimed_by', 'Alice Martin')
            ->where('movies.data.1.id', $second->id)
            ->where('movies.data.1.claimed_by', null));

    // Tout est réservé par d'autres : la file est vide pour un troisième.
    $carol = User::factory()->curator()->create();

    $this->actingAs($carol)
        ->get(route('admin.curation.next'))
        ->assertRedirect(route('admin.curation.index'));
});

test('une réservation expire sans battement', function (): void {
    $movie = Movie::factory()->create();

    $this->actingAs($this->alice)->get(route('admin.catalog.bank', $movie))->assertOk();
    expect(CurationClaim::holder($movie->id))->toBe($this->alice->id);

    // Un battement prolonge la réservation de toute sa durée.
    $this->travel(10)->minutes();
    $this->actingAs($this->alice)
        ->postJson(route('admin.catalog.heartbeat', $movie))
        ->assertNoContent();

    $this->travel(10)->minutes();
    expect(CurationClaim::holder($movie->id))->toBe($this->alice->id);

    // Sans battement, elle tombe et le film redevient libre pour Bruno.
    $this->travel(6)->minutes();
    expect(CurationClaim::holder($movie->id))->toBeNull();

    $this->actingAs($this->bob)
        ->get(route('admin.curation.next'))
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $movie->id]));
});

test('une réservation tenue par un autre n\'est jamais volée', function (): void {
    $movie = Movie::factory()->create();

    expect(CurationClaim::claim($this->alice, $movie->id))->toBeTrue()
        ->and(CurationClaim::claim($this->bob, $movie->id))->toBeFalse();

    // Ni par l'éditeur, ni par le battement de Bruno.
    $this->actingAs($this->bob)->get(route('admin.catalog.bank', $movie))->assertOk();
    $this->actingAs($this->bob)->postJson(route('admin.catalog.heartbeat', $movie))->assertNoContent();

    expect(CurationClaim::holder($movie->id))->toBe($this->alice->id)
        ->and(CurationClaim::heldByAnother($movie->id, $this->bob))->toBeTrue()
        ->and(CurationClaim::heldByAnother($movie->id, $this->alice))->toBeFalse();
});

test('un rechargement partiel de l\'éditeur ne réserve rien', function (): void {
    $movie = Movie::factory()->create();

    // La version des assets, lue sur un film publié, que l'éditeur ne réserve pas.
    $version = (string) $this->actingAs($this->alice)
        ->get(route('admin.catalog.bank', Movie::factory()->published()->create()))
        ->viewData('page')['version'];

    $this->actingAs($this->alice)
        ->get(route('admin.catalog.bank', $movie), [
            Header::INERTIA => 'true',
            Header::VERSION => $version,
            Header::PARTIAL_COMPONENT => 'admin/catalog/bank',
            Header::PARTIAL_ONLY => 'frames',
        ])
        ->assertOk();

    expect(CurationClaim::holder($movie->id))->toBeNull();
});

test('un film hors de la file n\'est jamais réservé', function (): void {
    $published = Movie::factory()->published()->create();
    $demo = Movie::factory()->demo()->create();

    $this->actingAs($this->alice)->get(route('admin.catalog.bank', $published))->assertOk();
    $this->actingAs($this->alice)->get(route('admin.catalog.bank', $demo));

    expect(CurationClaim::holders([$published->id, $demo->id]))->toBe([]);
});

test('sans curateur, « Film suivant » garde l\'ordre de la file sans rien réserver', function (): void {
    $first = Movie::factory()->create(['vote_count' => 30_000]);
    CurationClaim::claim($this->alice, $first->id);

    expect((new CurationQueue)->next()?->id)->toBe($first->id);
});

test('la durée de réservation hors bornes est refusée', function (mixed $minutes): void {
    Config::set('catalog.curation.claim_minutes', $minutes);

    expect(static fn (): int => CurationClaim::claimMinutes())->toThrow(InvalidArgumentException::class);
})->with([0, -5, '15']);

test('une réservation ne tombe jamais entre deux battements', function (): void {
    // La configuration livrée : quinze minutes, bien au-dessus du battement.
    expect(CurationClaim::claimMinutes() * 60)->toBeGreaterThan(Config::integer('catalog.curation.heartbeat_seconds'));

    // Une durée sous la cadence du battement est refusée.
    Config::set('catalog.curation.heartbeat_seconds', 60);
    Config::set('catalog.curation.claim_minutes', 1);

    expect(static fn (): int => CurationClaim::claimMinutes())->toThrow(InvalidArgumentException::class);
});
