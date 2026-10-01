<?php

use App\Avatars\UploadedAvatars;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Http\Middleware\EnsurePrivilegedTwoFactor;
use App\Http\Middleware\EnsureUserHasRole;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| La matrice des capacités, jouée route par route — spec 20 § 2.2 et § 2.9
|--------------------------------------------------------------------------
|
| La table de vérité vit dans `tests/Datasets/AdminRoutes.php`, et nulle part
| ailleurs. Ce fichier la joue pour les quatre visiteurs, vérifie qu'elle
| couvre exactement les routes `admin.*` enregistrées, que chaque route porte
| la garde `can:` que la matrice lui donne, et qu'aucune policy ne supprime ce
| que le projet garde pour toujours.
|
| La garde est vérifiée à chaque ÉCRITURE, par le middleware de la route :
| les booléens `abilities` des écrans ne font que masquer un bouton.
|
*/

beforeEach(function (): void {
    // L'autorisation, pas la présence d'un fichier dans le manifeste Vite.
    $this->withoutVite();

    // Aucune écriture du back-office ne part en file réelle, et aucune ne
    // touche TMDB pendant la requête. Sans clé, les écritures d'import
    // refuseraient avant toute autre considération, et la matrice mesurerait
    // le mauvais refus : la clé est posée, jamais employée.
    Bus::fake();
    Config::set('services.tmdb.api_key', 'clef-de-test');

    // Les lignes qui portent sur une image écrivent ses octets : sur un
    // disque faux, jamais dans la racine réelle de `frames`, où ils
    // resteraient après le `RefreshDatabase`, qui n'annule que la ligne.
    Storage::fake(FrameStoragePrefix::DISK);
    Storage::fake(UploadedAvatars::DISK);
});

/**
 * Les middlewares réellement exécutés par une route, alias résolus :
 * `Route::gatherMiddleware()` ignore `withoutMiddleware`, le routeur non.
 *
 * Le noyau HTTP est résolu d'abord : c'est son constructeur qui inscrit les
 * alias (`role`, `can`, `admin.2fa`) dans le routeur, et un test qui n'a
 * encore envoyé aucune requête lirait sinon les noms bruts.
 *
 * @return list<string>
 */
function authorizationMatrixResolved(RoutingRoute $route): array
{
    app(HttpKernel::class);

    return array_values(array_filter(
        app('router')->gatherRouteMiddleware($route),
        'is_string',
    ));
}

/**
 * Les gardes `can:` réellement exécutées par une route, rendues sous leur
 * forme déclarée — `Authorize::using()` s'écrit sans l'alias, et se lit ici
 * de la même façon.
 *
 * @return list<string>
 */
function authorizationMatrixGuards(RoutingRoute $route): array
{
    $guards = [];

    foreach (authorizationMatrixResolved($route) as $middleware) {
        if (str_starts_with($middleware, Authorize::class.':')) {
            $guards[] = 'can:'.substr($middleware, strlen(Authorize::class.':'));
        }
    }

    return $guards;
}

/**
 * Les routes `admin.*` enregistrées, par nom.
 *
 * @return array<string, RoutingRoute>
 */
function authorizationMatrixRegistered(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if (is_string($name) && str_starts_with($name, 'admin.')) {
            $routes[$name] = $route;
        }
    }

    ksort($routes);

    return $routes;
}

/**
 * Un compte par visiteur de la matrice ; `null` pour l'invité.
 */
function authorizationMatrixVisitor(string $visitor): ?User
{
    return match ($visitor) {
        'guest' => null,
        'player' => User::factory()->player()->create(),
        'curator' => User::factory()->curator()->create(),
        'admin' => User::factory()->admin()->create(),
    };
}

test('chaque route du back-office répond selon la matrice pour un invité, un joueur, un curateur et un administrateur', function (string $name, array $row, string $visitor): void {
    $user = authorizationMatrixVisitor($visitor);
    $parameters = ($row['parameters'])();
    $payload = ($row['payload'])();
    $expected = $row['responses'][$visitor];
    $label = sprintf('%s (ligne %d), %s', $name, $row['row'], $visitor);

    if ($user instanceof User) {
        $this->actingAs($user);
    }

    $response = $this->call($row['method'], route($name, $parameters), $payload);

    if ($expected === 'login') {
        $response->assertRedirect(route('login'));
    } else {
        expect($response->getStatusCode())->toBe($expected, $label);
    }

    // Un 302 de compte privilégié est le geste lui-même, jamais un retour
    // arrière déguisé : ni erreur de validation, ni redirection de la porte,
    // et la destination que la ligne annonce.
    if ($expected === 302 && $visitor !== 'guest') {
        $response->assertSessionHasNoErrors();

        expect($row['redirect'])->toBeInstanceOf(Closure::class, $label);
        $response->assertRedirect(($row['redirect'])($parameters));
    }

    // Sous le seuil, la réponse tombe AVANT toute résolution de modèle : la
    // même sur un identifiant qui n'existe pas, sans quoi le couple 403 / 404
    // énumérerait les tables.
    if (in_array($visitor, ['guest', 'player'], true) && $parameters !== []) {
        $absent = array_map(
            fn (int|string $value): int|string => is_int($value) ? $value + 1_000_000 : $value,
            $parameters,
        );

        $again = $this->call($row['method'], route($name, $absent), $payload);

        if ($expected === 'login') {
            $again->assertRedirect(route('login'));
        } else {
            expect($again->getStatusCode())->toBe($expected, $label.', identifiant absent');
        }
    }
})->with('admin.routes')->with([
    'invité' => 'guest',
    'joueur' => 'player',
    'curateur' => 'curator',
    'administrateur' => 'admin',
]);

test('le jeu de données couvre exactement les routes admin enregistrées', function (): void {
    $registered = authorizationMatrixRegistered();
    $described = adminRoutesMatrix();

    ksort($described);

    expect(array_keys($described))->toBe(array_keys($registered));

    // Et chaque ligne décrit la route telle qu'elle est enregistrée : sa
    // méthode, et un paramètre de fabrique pour chaque paramètre d'URL.
    foreach ($registered as $name => $route) {
        $row = $described[$name];

        expect($route->methods())->toContain($row['method'])
            ->and(array_keys(($row['parameters'])()))->toBe($route->parameterNames(), $name);
    }
});

test('toute route admin porte une garde can hors l\'écran d\'enrôlement et la page de premiers pas', function (): void {
    $gateOnly = ['admin.two_factor.required', 'admin.guide'];
    $described = adminRoutesMatrix();

    foreach (authorizationMatrixRegistered() as $name => $route) {
        $guards = authorizationMatrixGuards($route);

        // La porte d'abord : `role:curator` garde toute route du groupe, les
        // deux exceptions comprises — c'est elle qui leur suffit.
        expect(authorizationMatrixResolved($route))
            ->toContain(EnsureUserHasRole::class.':curator');

        // Puis la serrure : `admin.2fa` garde toute route du groupe, sauf
        // l'écran d'enrôlement, qui sinon se renverrait à lui-même (§ 2.4).
        // Une route sortie de la serrure par `withoutMiddleware`, ou déclarée
        // hors du groupe, fait échouer la suite.
        // (`toContain()` est variadique : tout second argument serait une
        // aiguille de plus, jamais un message — d'où `in_array`.)
        expect(in_array(EnsurePrivilegedTwoFactor::class, authorizationMatrixResolved($route), true))
            ->toBe($name !== 'admin.two_factor.required', $name);

        if (in_array($name, $gateOnly, true)) {
            expect($guards)->toBe([], $name);
        } else {
            expect($guards)->not->toBeEmpty($name);
        }

        // La garde exécutée est celle que la matrice écrit, ni plus ni moins :
        // `can:create,movie` à la place de `can:create,App\Models\Frame,movie`
        // résoudrait `MoviePolicy::create()`, qui refuse toujours (V-17).
        expect($guards)->toBe($described[$name]['guards'] ?? null, $name);
    }
});

test('aucune policy ne supprime un film, une image, une revue, un balayage ni une ligne de journal', function (): void {
    $subjects = [];

    foreach (ContentAvailability::cases() as $availability) {
        $movie = Movie::factory()->create(['availability' => $availability]);

        $subjects['film '.$availability->value] = $movie;
        $subjects['image '.$availability->value] = Frame::factory()->for($movie)->create(['availability' => $availability]);
    }

    $subjects['revue'] = FrameReview::factory()->create();
    $subjects['balayage en cours'] = ImportRun::factory()->discover()->running()->create();
    $subjects['balayage terminé'] = ImportRun::factory()->discover()->completed()->create();
    $subjects['ligne de journal'] = AdminAction::factory()->create();

    $accounts = [
        'joueur' => User::factory()->player()->create(),
        'curateur' => User::factory()->curator()->create(),
        'administrateur' => User::factory()->admin()->create(),
    ];

    foreach ($accounts as $account => $user) {
        foreach ($subjects as $subject => $model) {
            foreach (['delete', 'forceDelete'] as $ability) {
                expect(Gate::forUser($user)->allows($ability, $model))
                    ->toBeFalse("{$account} : {$ability} sur {$subject}");
            }
        }
    }

    // Et une revue ne se réécrit pas davantage (§ 2.2, ligne 38).
    expect(Gate::forUser($accounts['administrateur'])->allows('update', $subjects['revue']))->toBeFalse();
});

test('les méthodes J1 des policies suivent la table du § 2.9, seuil et état compris', function (): void {
    $player = User::factory()->player()->create();
    $privileged = [
        'curateur' => User::factory()->curator()->create(),
        'administrateur' => User::factory()->admin()->create(),
    ];

    // État par état, ce que la table du § 2.9 ouvre à un compte au-dessus du
    // seuil de curation. Un joueur est refusé partout.
    $movieAbilities = [
        'view' => ['draft', 'published', 'unpublished', 'suspended', 'withdrawn'],
        'curate' => ['draft', 'published', 'unpublished', 'suspended'],
        'publish' => ['draft', 'unpublished'],
        'unpublish' => ['draft', 'published'],
        'verifyContent' => ['draft', 'published', 'unpublished', 'suspended'],
    ];

    $frameAbilities = [
        'view' => ['draft', 'published', 'unpublished', 'suspended'],
        'update' => ['draft', 'published', 'unpublished', 'suspended', 'withdrawn'],
        'unpublish' => ['draft', 'published'],
    ];

    foreach (ContentAvailability::cases() as $availability) {
        $movie = Movie::factory()->create([
            'availability' => $availability,
            'content_flag' => ContentFlag::UnratedPending,
        ]);
        $frame = Frame::factory()->for($movie)->create(['availability' => $availability]);

        foreach ($privileged as $label => $user) {
            foreach ($movieAbilities as $ability => $states) {
                expect(Gate::forUser($user)->allows($ability, $movie))
                    ->toBe(in_array($availability->value, $states, true), "{$label} : Movie::{$ability} sur {$availability->value}");
            }

            foreach ($frameAbilities as $ability => $states) {
                expect(Gate::forUser($user)->allows($ability, $frame))
                    ->toBe(in_array($availability->value, $states, true), "{$label} : Frame::{$ability} sur {$availability->value}");
            }

            // Ajouter une image : le film de l'URL, ni suspendu ni retiré.
            expect(Gate::forUser($user)->allows('create', [Frame::class, $movie]))
                ->toBe(! in_array($availability, [ContentAvailability::Suspended, ContentAvailability::Withdrawn], true), "{$label} : Frame::create sur un film {$availability->value}");
        }

        foreach (array_keys($movieAbilities) as $ability) {
            expect(Gate::forUser($player)->allows($ability, $movie))->toBeFalse("joueur : Movie::{$ability}");
        }

        foreach (array_keys($frameAbilities) as $ability) {
            expect(Gate::forUser($player)->allows($ability, $frame))->toBeFalse("joueur : Frame::{$ability}");
        }

        expect(Gate::forUser($player)->allows('create', [Frame::class, $movie]))->toBeFalse();
    }

    // L'aperçu d'une image retirée répond 404, jamais 403 (§ 5.8) : c'est
    // `FramePolicy::view` qui le dit, et la garde `can:view,frame` le rend.
    $withdrawnFrame = Frame::factory()->for(Movie::factory()->create())->create([
        'availability' => ContentAvailability::Withdrawn,
    ]);

    foreach ($privileged as $label => $user) {
        expect(Gate::forUser($user)->inspect('view', $withdrawnFrame)->status())->toBe(404, $label);
    }

    expect(Gate::forUser($privileged['curateur'])->inspect('view', Frame::factory()->create())->status())->toBeNull();

    // « Contenu vérifié » : seul un verdict en attente se coche.
    foreach ([ContentFlag::Clear, ContentFlag::Blocked] as $flag) {
        $movie = Movie::factory()->create(['content_flag' => $flag]);

        expect(Gate::forUser($privileged['administrateur'])->allows('verifyContent', $movie))->toBeFalse($flag->value);
    }

    // La file et la passe de revue : curator+.
    expect(Gate::forUser($player)->allows('create', FrameReview::class))->toBeFalse()
        ->and(Gate::forUser($privileged['curateur'])->allows('create', FrameReview::class))->toBeTrue()
        ->and(Gate::forUser($privileged['administrateur'])->allows('create', FrameReview::class))->toBeTrue();

    // La voie capture : ouverte par défaut (D38 du 28/09), refus MOTIVÉ
    // quand un site la ferme, quel que soit le rôle ; ouverte, le seuil
    // d'ajout.
    $draft = Movie::factory()->create();
    $suspended = Movie::factory()->suspended()->create();

    Config::set('catalog.curation.capture_enabled', false);

    $closed = Gate::forUser($privileged['administrateur'])->inspect('createFromCapture', [Frame::class, $draft]);

    expect($closed)->toBeInstanceOf(AccessResponse::class)
        ->and($closed->denied())->toBeTrue()
        ->and($closed->message())->toBe('admin.frame.capture.disabled');

    Config::set('catalog.curation.capture_enabled', true);

    expect(Gate::forUser($privileged['curateur'])->allows('createFromCapture', [Frame::class, $draft]))->toBeTrue()
        ->and(Gate::forUser($privileged['curateur'])->allows('createFromCapture', [Frame::class, $suspended]))->toBeFalse()
        ->and(Gate::forUser($player)->allows('createFromCapture', [Frame::class, $draft]))->toBeFalse();

    // Les comptes (§ 2.8, lignes 34 et 40) : administrateur seul, partout.
    // Une pierre tombale ne reçoit plus de rôle ; seul un compte privilégié
    // porte un nom réel à corriger.
    $targets = [
        'joueur' => User::factory()->player()->create(),
        'curateur' => User::factory()->curator()->create(),
        'pierre tombale' => User::factory()->anonymized()->create(),
        // Anonymisé mais encore curateur : l'exclusion vient de
        // `anonymized_at`, jamais du seul rôle.
        'curateur anonymisé' => User::factory()->curator()->create(['anonymized_at' => now()]),
    ];

    foreach ([$player, $privileged['curateur']] as $refused) {
        expect(Gate::forUser($refused)->allows('viewAny', User::class))->toBeFalse();

        foreach ($targets as $target) {
            foreach (['view', 'updateRole', 'updateRealName'] as $ability) {
                expect(Gate::forUser($refused)->allows($ability, $target))->toBeFalse("{$ability} refusé sous le seuil admin");
            }
        }
    }

    $admin = $privileged['administrateur'];

    expect(Gate::forUser($admin)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $targets['pierre tombale']))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('updateRole', $targets['joueur']))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('updateRole', $targets['pierre tombale']))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('updateRealName', $targets['curateur']))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('updateRealName', $targets['joueur']))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('updateRealName', $targets['pierre tombale']))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('updateRole', $targets['curateur anonymisé']))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('updateRealName', $targets['curateur anonymisé']))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $targets['joueur']))->toBeFalse();
});
