<?php

use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Matrice des capacités du back-office — spec 20 § 2.2 et § 2.9, L20-3
|--------------------------------------------------------------------------
|
| Le jeu de données UNIQUE de la famille de tests d'autorisation (décision 9) :
| une ligne par route `admin.*` enregistrée, ni plus ni moins, avec sa
| méthode, ses gardes `can:`, ses paramètres de fabrique et la réponse
| attendue pour chacun des quatre visiteurs. `AuthorizationMatrixTest` le joue
| et vérifie qu'il couvre EXACTEMENT `Route::getRoutes()` filtré sur
| `admin.*` : une route ajoutée sans sa ligne fait échouer la suite, une
| ligne sans route aussi.
|
| Les routes naissent au fil des lots (§ 2.2) ; chaque lot qui en déclare une
| ajoute ici sa ligne, avec le numéro de la ligne de la matrice qu'elle
| réalise. Deux règles communes, posées par `adminRoutesRow()` et jamais
| réécrites ligne à ligne : un invité est renvoyé vers la connexion, un
| joueur reçoit 403 avant toute résolution de modèle. La réponse d'un
| `curator` et d'un `admin` est celle d'un compte passé la porte, second
| facteur confirmé (défaut des fabriques).
|
| Les paramètres et la charge utile sont des fermetures : elles sont appelées
| dans le test, base prête, une fois par visiteur. La fermeture `redirect`
| d'une écriture est appelée APRÈS la requête et rend l'URL attendue du 302
| d'un compte privilégié : un 302 de retour arrière, message d'erreur à
| l'appui, ne passe donc jamais pour un geste autorisé.
|
*/

/**
 * Une ligne de la matrice, règles communes comprises.
 *
 * @param  int  $row  numéro de la ligne du § 2.2 que la route réalise
 * @param  'GET'|'POST'|'PUT'|'PATCH'|'DELETE'  $method
 * @param  list<string>  $guards  les gardes `can:` exactes de la route, vides pour la seule porte
 * @param  (Closure(): array<string, int|string>)|null  $parameters
 * @param  (Closure(): array<string, mixed>)|null  $payload
 * @param  (Closure(array<string, int|string>): string)|null  $redirect
 * @return array{row: int, method: string, guards: list<string>, parameters: Closure(): array<string, int|string>, payload: Closure(): array<string, mixed>, redirect: (Closure(array<string, int|string>): string)|null, responses: array{guest: 'login', player: int, curator: int, admin: int}}
 */
function adminRoutesRow(
    int $row,
    string $method,
    array $guards,
    int $curator,
    int $admin,
    ?Closure $parameters = null,
    ?Closure $payload = null,
    ?Closure $redirect = null,
): array {
    return [
        'row' => $row,
        'method' => $method,
        'guards' => $guards,
        'parameters' => $parameters ?? fn (): array => [],
        'payload' => $payload ?? fn (): array => [],
        'redirect' => $redirect,
        'responses' => [
            'guest' => 'login',
            'player' => 403,
            'curator' => $curator,
            'admin' => $admin,
        ],
    ];
}

/**
 * Chaque route `admin.*` enregistrée, par nom.
 *
 * @return array<string, array{row: int, method: string, guards: list<string>, parameters: Closure(): array<string, int|string>, payload: Closure(): array<string, mixed>, redirect: (Closure(array<string, int|string>): string)|null, responses: array{guest: 'login', player: int, curator: int, admin: int}}>
 */
function adminRoutesMatrix(): array
{
    $latestRun = fn (array $parameters): string => route('admin.import.show', [
        'importRun' => ImportRun::query()->latest('id')->value('id'),
    ]);

    return [
        // Ligne 9 — la porte seule : aucune garde `can:` (§ 2.3).
        'admin.two_factor.required' => adminRoutesRow(
            row: 9,
            method: 'GET',
            guards: [],
            curator: 200,
            admin: 200,
        ),

        // Ligne 1 — deux gardes, parce que l'écran lit deux modèles.
        'admin.dashboard' => adminRoutesRow(
            row: 1,
            method: 'GET',
            guards: ['can:viewAny,'.Movie::class, 'can:viewAny,'.ImportRun::class],
            curator: 200,
            admin: 200,
        ),

        // Ligne 2 — le catalogue, et la fiche d'un film en tout état.
        'admin.catalog.index' => adminRoutesRow(
            row: 2,
            method: 'GET',
            guards: ['can:viewAny,'.Movie::class],
            curator: 200,
            admin: 200,
        ),

        'admin.catalog.show' => adminRoutesRow(
            row: 2,
            method: 'GET',
            guards: ['can:view,movie'],
            curator: 200,
            admin: 200,
            parameters: fn (): array => ['movie' => Movie::factory()->withdrawn()->create()->getKey()],
        ),

        // Ligne 7 — l'écran d'import et le détail d'un balayage.
        'admin.import.index' => adminRoutesRow(
            row: 7,
            method: 'GET',
            guards: ['can:viewAny,'.ImportRun::class],
            curator: 200,
            admin: 200,
        ),

        'admin.import.show' => adminRoutesRow(
            row: 7,
            method: 'GET',
            guards: ['can:view,importRun'],
            curator: 200,
            admin: 200,
            // Le balayage d'un AUTRE compte : un journal de provenance se lit
            // quel qu'en soit l'auteur.
            parameters: fn (): array => [
                'importRun' => ImportRun::factory()->actedBy(User::factory()->curator()->create())->create()->getKey(),
            ],
        ),

        // Ligne 10 — balayage `discover`, collage, reprise.
        'admin.import.discover' => adminRoutesRow(
            row: 10,
            method: 'POST',
            guards: ['can:create,'.ImportRun::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => ['min_votes' => 500, 'languages' => ['fr'], 'min_year' => 1970, 'pages' => 1],
            redirect: $latestRun,
        ),

        'admin.import.ids' => adminRoutesRow(
            row: 10,
            method: 'POST',
            guards: ['can:create,'.ImportRun::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => ['ids' => '550'],
            redirect: $latestRun,
        ),

        'admin.import.resume' => adminRoutesRow(
            row: 10,
            method: 'POST',
            guards: ['can:update,importRun'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => ['importRun' => ImportRun::factory()->discover()->running()->create()->getKey()],
            redirect: fn (array $parameters): string => route('admin.import.show', $parameters),
        ),
    ];
}

dataset('admin.routes', function (): iterable {
    foreach (adminRoutesMatrix() as $name => $row) {
        yield $name => [$name, $row];
    }
});
