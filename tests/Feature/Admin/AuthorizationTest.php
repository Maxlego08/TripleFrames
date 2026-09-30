<?php

use App\Enums\ImportRunKind;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\SavedConfig;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| La famille d'autorisation exigée par la décision 9
|--------------------------------------------------------------------------
|
| Pour CHAQUE route du back-office, les quatre mêmes questions : un invité
| est-il redirigé vers la connexion, un joueur refusé, un curateur admis, un
| administrateur admis parce qu'il est AU-DESSUS du seuil ?
|
| Et la garde qui compte le plus, celle qui n'a aucune route : un
| administrateur est REFUSÉ sur la `saved_config` d'un tiers. Sans elle, rien
| dans le dépôt n'échouerait le jour où quelqu'un ajouterait un `Gate::before`
| « pour simplifier » — et la propriété d'une configuration de salon, déclarée
| strictement privée, deviendrait une politesse.
|
*/

/**
 * Les cinq écrans, par nom de route Wayfinder.
 *
 * @return list<array{string, array<string, mixed>}>
 */
function adminReadRoutes(): array
{
    return [
        ['admin.dashboard', []],
        ['admin.catalog.index', []],
        ['admin.import.index', []],
    ];
}

beforeEach(function (): void {
    // Les pages React de ce lot sont écrites par un autre agent : sans ceci, ces
    // tests mesureraient la présence d'un fichier dans le manifeste Vite au lieu
    // de mesurer l'autorisation.
    $this->withoutVite();

    // Sans clé, les trois routes d'écriture refusent avant toute autre
    // considération : la matrice d'autorisation mesurerait alors le mauvais
    // refus. La clé est posée en configuration et n'est jamais employée — aucun
    // appel TMDB n'a lieu dans une requête web, c'est tout le sujet du job.
    Config::set('services.tmdb.api_key', 'clef-de-test');
});

test('un invité est redirigé vers la connexion sur chaque écran', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(array_map(fn (array $row): string => $row[0], adminReadRoutes()));

test('un joueur est refusé sur chaque écran', function (string $route): void {
    $this->actingAs(User::factory()->player()->create())
        ->get(route($route))
        ->assertForbidden();
})->with(array_map(fn (array $row): string => $row[0], adminReadRoutes()));

test('un curateur est admis sur chaque écran', function (string $route): void {
    $this->actingAs(User::factory()->curator()->create())
        ->get(route($route))
        ->assertOk();
})->with(array_map(fn (array $row): string => $row[0], adminReadRoutes()));

test('un administrateur est admis sur chaque écran, parce qu’il est au-dessus du seuil', function (string $route): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route($route))
        ->assertOk();
})->with(array_map(fn (array $row): string => $row[0], adminReadRoutes()));

test('un curateur dont l’e-mail n’est pas vérifié est renvoyé vers la vérification', function (): void {
    $curator = User::factory()->curator()->unverified()->create();

    $this->actingAs($curator)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('la fiche film répond 403 à un joueur, y compris sur un identifiant inconnu', function (): void {
    $movie = Movie::factory()->create();
    $player = User::factory()->player()->create();

    // Le seuil de rôle tombe AVANT la substitution de liaison : sans cela, le
    // couple 404 / 403 énumérerait la table `movie`.
    $this->actingAs($player)->get(route('admin.catalog.show', $movie))->assertForbidden();
    $this->actingAs($player)->get(route('admin.catalog.show', 9_999_999))->assertForbidden();
});

test('la fiche film est lisible par un curateur quel que soit l’état du film', function (): void {
    $curator = User::factory()->curator()->create();

    foreach ([Movie::factory()->withdrawn(), Movie::factory()->suspended(), Movie::factory()] as $factory) {
        $this->actingAs($curator)
            ->get(route('admin.catalog.show', $factory->create()))
            ->assertOk();
    }
});

test('le détail d’un balayage suit le même seuil', function (): void {
    $run = ImportRun::factory()->create();

    $this->get(route('admin.import.show', $run))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->player()->create())->get(route('admin.import.show', $run))->assertForbidden();
    $this->actingAs(User::factory()->curator()->create())->get(route('admin.import.show', $run))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.import.show', $run))->assertOk();
});

test('un curateur lit le balayage d’un autre curateur', function (): void {
    $run = ImportRun::factory()->actedBy(User::factory()->curator()->create())->create();

    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.import.show', $run))
        ->assertOk();
});

test('les deux voies d’import sont ouvertes au curateur et fermées au joueur', function (): void {
    Bus::fake();

    $payloads = [
        'admin.import.discover' => ['min_votes' => 500, 'languages' => ['fr'], 'min_year' => 1970, 'pages' => 1],
        'admin.import.ids' => ['ids' => '550'],
    ];

    // Les invités d'abord, et tous ensemble : `actingAs()` vaut pour toutes les
    // requêtes suivantes du test, et une vérification d'invité posée après une
    // connexion mesurerait en réalité le curateur précédent.
    foreach ($payloads as $route => $payload) {
        $this->post(route($route), $payload)->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->player()->create());

    foreach ($payloads as $route => $payload) {
        $this->post(route($route), $payload)->assertForbidden();
    }

    $this->actingAs(User::factory()->curator()->create());

    foreach ($payloads as $route => $payload) {
        $this->post(route($route), $payload)->assertRedirectContains('/admin/import/run/');
    }
});

test('la reprise exige le seuil curateur ET un balayage en cours', function (): void {
    Bus::fake();

    $running = ImportRun::factory()->discover()->running()->create();
    $finished = ImportRun::factory()->discover()->completed()->create();

    $this->post(route('admin.import.resume', $running))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->player()->create())
        ->post(route('admin.import.resume', $running))
        ->assertForbidden();

    // Second terme de la policy, et il n'est pas cosmétique : reprendre un
    // balayage terminé relancerait un curseur déjà consommé.
    $this->actingAs(User::factory()->curator()->create())
        ->post(route('admin.import.resume', $finished))
        ->assertForbidden();

    $this->actingAs(User::factory()->curator()->create())
        ->post(route('admin.import.resume', $running))
        ->assertRedirect(route('admin.import.show', ['importRun' => $running->id]));
});

test('les trois seuils de policy, lus directement', function (): void {
    $player = User::factory()->player()->create();
    $curator = User::factory()->curator()->create();
    $admin = User::factory()->admin()->create();
    $movie = Movie::factory()->withdrawn()->create();
    $running = ImportRun::factory()->discover()->running()->create();
    $completed = ImportRun::factory()->discover()->completed()->create();

    expect(Gate::forUser($player)->allows('viewAny', Movie::class))->toBeFalse()
        ->and(Gate::forUser($curator)->allows('viewAny', Movie::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', Movie::class))->toBeTrue()
        // Un film retiré reste LISIBLE : la fiche doit se suffire devant une
        // mise en demeure, motif et horodatage compris (§ 3.1).
        ->and(Gate::forUser($curator)->allows('view', $movie))->toBeTrue()
        // La création d'un film est un acte d'import, jamais un geste d'écran.
        ->and(Gate::forUser($admin)->allows('create', Movie::class))->toBeFalse()
        ->and(Gate::forUser($player)->allows('viewAny', ImportRun::class))->toBeFalse()
        ->and(Gate::forUser($curator)->allows('viewAny', ImportRun::class))->toBeTrue()
        ->and(Gate::forUser($curator)->allows('create', ImportRun::class))->toBeTrue()
        ->and(Gate::forUser($curator)->allows('update', $running))->toBeTrue()
        ->and(Gate::forUser($curator)->allows('update', $completed))->toBeFalse()
        // Un journal de provenance ne se supprime pas depuis un écran.
        ->and(Gate::forUser($admin)->allows('delete', $running))->toBeFalse();
});

test('un administrateur est REFUSÉ sur la saved_config d’un tiers', function (): void {
    $owner = User::factory()->create();
    $config = SavedConfig::factory()->create(['user_id' => $owner->id]);

    $admin = User::factory()->admin()->create();

    // Le garde-fou anti-`Gate::before` : l'administrateur passe les seuils du
    // back-office parce qu'il est au-dessus, jamais parce qu'il est
    // administrateur. La propriété, elle, ne connaît aucun rôle.
    expect(Gate::forUser($admin)->allows('view', $config))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $config))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $config))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $config))->toBeTrue();
});

test('aucun écran d’administration ne crée jamais un balayage', function (): void {
    $this->actingAs(User::factory()->curator()->create());

    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('admin.catalog.index'))->assertOk();
    $this->get(route('admin.import.index'))->assertOk();

    expect(ImportRun::query()->count())->toBe(0)
        ->and(ImportRunKind::cases())->toHaveCount(3);
});
