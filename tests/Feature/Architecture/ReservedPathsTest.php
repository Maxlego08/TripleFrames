<?php

use Illuminate\Routing\Route;

/*
|--------------------------------------------------------------------------
| Préfixes réservés — spec 100 § 10.5
|--------------------------------------------------------------------------
|
| En production, nginx publie le chemin WebSocket de Reverb `/app/` par les
| directives additionnelles de l'abonnement ; l'API HTTP de Reverb, `/apps/`,
| appartient au même serveur et n'est jamais publiée. Une route applicative
| sous l'un de ces préfixes serait donc avalée par Reverb — ou, pour `/app`
| sans barre finale, redirigée vers lui par nginx — sans que la suite le
| voie : l'application ne connaît pas le bloc `location` qui la précède.
|
| Sous `/ops/`, la seule route admise est la sonde de supervision
| `ops.probe` (§ 15), déclarée dans `routes/ops.php`, chargée hors du groupe
| `web` : sans session, sans cookie, en noindex. Toute autre route glissée
| sous ce préfixe hériterait d'une surface pensée pour les seules sondes.
|
*/

/**
 * Violations des préfixes réservés dans une liste de routes, triées.
 *
 * Un préfixe se juge sur le PREMIER SEGMENT : `/app` et `/app/...` sont
 * réservés, `/appearance` et `/application` ne le sont pas.
 *
 * @param  iterable<Route>  $routes
 * @return list<string>
 */
function reservedPathViolations(iterable $routes): array
{
    $violations = [];

    foreach ($routes as $route) {
        $uri = trim($route->uri(), '/');
        $first = explode('/', $uri, 2)[0];
        $label = sprintf('/%s (%s)', $uri, $route->getName() ?? 'sans nom');

        if (in_array($first, ['app', 'apps'], true)) {
            $violations[] = "{$label} : préfixe /{$first}/ réservé à Reverb";
        }

        if ($first === 'ops' && $route->getName() !== 'ops.probe') {
            $violations[] = "{$label} : seule la route ops.probe est admise sous /ops/";
        }
    }

    sort($violations);

    return $violations;
}

it("ne déclare aucune route sous /app/ ni /apps/, et aucune autre qu'ops.probe sous /ops/", function () {
    $routes = app('router')->getRoutes()->getRoutes();

    // Le balayage voit les routes réelles, pas une liste vide.
    expect($routes)->not->toBeEmpty()
        ->and(reservedPathViolations($routes))->toBe([]);

    // Et le détecteur n'est pas muet : chaque forme réservée est vue, les
    // voisins lexicaux et la sonde elle-même ne le sont pas.
    $route = static function (string $uri, ?string $name = null): Route {
        $route = new Route(['GET'], $uri, static fn () => null);

        return $name === null ? $route : $route->name($name);
    };

    expect(reservedPathViolations([$route('app')]))->toHaveCount(1)
        ->and(reservedPathViolations([$route('app/{appKey}')]))->toHaveCount(1)
        ->and(reservedPathViolations([$route('/apps/{appId}/events')]))->toHaveCount(1)
        ->and(reservedPathViolations([$route('ops/status', 'ops.status')]))->toHaveCount(1)
        ->and(reservedPathViolations([$route('ops/probe/{probe}')]))->toHaveCount(1)
        ->and(reservedPathViolations([
            $route('ops/probe/{probe}', 'ops.probe'),
            $route('appearance'),
            $route('application/{id}'),
            $route('settings/appearance', 'appearance.edit'),
            $route('opsx'),
            $route('shop/app'),
            $route('/'),
        ]))->toBe([]);
});
