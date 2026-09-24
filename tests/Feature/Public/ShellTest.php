<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Coquilles et thème — spec 90 § 2, contrat C16 § 2.1-2.4
|--------------------------------------------------------------------------
|
| Créé par L90-1, complété par L90-7 (forçage sombre de `game/*`) et L90-9.
|
| Un seul forçage d'apparence existe dans le produit : les pages `game/*`, en
| sombre. Le back-office a perdu le sien (D8 du 23/09) : forcer le clair
| n'avait d'autre motif qu'une préférence, alors que la revue d'une image
| exige de la voir telle qu'elle sera servie en jeu — ce que seul un cadre
| sombre LOCAL donne. Il suit donc l'apparence du visiteur, lue dans le
| cookie `appearance` par `HandleAppearance`, comme tout le reste du site.
|
*/

/**
 * La balise `<html>` d'une réponse rendue par `app.blade.php`.
 */
function shellHtmlTag(TestResponse $response): string
{
    $matched = preg_match('/<html\b[^>]*>/i', (string) $response->getContent(), $match);

    expect($matched)->toBe(1, "la réponse n'a pas de balise <html>");

    return $match[0];
}

/**
 * Classes CSS de la balise `<html>`.
 *
 * @return list<string>
 */
function shellHtmlClasses(string $tag): array
{
    if (preg_match('/\sclass="([^"]*)"/', $tag, $match) !== 1) {
        return [];
    }

    return array_values(array_filter(preg_split('/\s+/', $match[1]) ?: []));
}

it("laisse le back-office suivre l'apparence du visiteur", function () {
    // Les pages React ne sont pas en cause ici : seule compte la balise
    // `<html>` que Blade rend autour d'elles.
    $this->withoutVite();

    $curator = User::factory()->curator()->create();

    // Le retrait est entier : ni classe, ni alias, ni middleware sur le groupe.
    expect(class_exists('App\\Http\\Middleware\\ForceAdminAppearance'))->toBeFalse()
        ->and(array_keys(app('router')->getMiddleware()))->not->toContain('admin.appearance')
        ->and(Route::getRoutes()->getByName('admin.dashboard')?->gatherMiddleware())
        ->not->toContain('admin.appearance');

    // `appearance` est exclu du chiffrement des cookies (`bootstrap/app.php`) :
    // le front l'écrit en clair, le test aussi.
    $dark = $this->actingAs($curator)
        ->withUnencryptedCookie('appearance', 'dark')
        ->get(route('admin.dashboard'))
        ->assertOk();

    $darkTag = shellHtmlTag($dark);

    expect($darkTag)->not->toContain('data-appearance-forced')
        ->and(shellHtmlClasses($darkTag))->toContain('dark');

    $light = $this->actingAs($curator)
        ->withUnencryptedCookie('appearance', 'light')
        ->get(route('admin.dashboard'))
        ->assertOk();

    $lightTag = shellHtmlTag($light);

    expect($lightTag)->not->toContain('data-appearance-forced')
        ->and(shellHtmlClasses($lightTag))->not->toContain('dark');

    // Le script en ligne ne force rien non plus : il ne lit que la préférence
    // (`light` ici), et n'ajoute `dark` qu'en préférence « système ».
    expect((string) $light->getContent())->toContain("const appearance = 'light';");
});
