<?php

use App\Enums\Locale;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| Coquilles et thème — spec 90 § 2, contrat C16 § 2.1-2.4
|--------------------------------------------------------------------------
|
| Créé par L90-1, complété par L90-3b (bandeau de maintenance), L90-7
| (forçage sombre de `game/*`) et L90-9.
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

it("monte le bandeau de maintenance sous l'en-tête public, au rôle note, sur sa seule clé sans paramètre", function () {
    // Ajouté par L90-3b, hors des intitulés de la spec : le rendu se vérifie à
    // la main (aucun DOM au jalon 1, C18 § 2.4), la prop par
    // `MaintenanceBannerTest` (100). Ce test garde dans la source les
    // invariants du § 3.3 (rien hors drainage, rôle note porté par `Alert`,
    // seule clé sans paramètre, place dans la coquille), que la vérification
    // manuelle ne rejoue pas à chaque commit.
    $banner = FrontSource::withoutComments((string) file_get_contents(
        resource_path('js/components/public/maintenance-banner.tsx'),
    ));

    // Il ne lit que le booléen partagé, et ne rend que sa clé.
    expect(substr_count($banner, 'usePage()'))->toBe(1)
        ->and($banner)->toMatch('/const \{\s*maintenance\s*\} = usePage\(\)\.props;/')
        ->and(FrontSource::literalKeys($banner))->toBe(['common.maintenance.banner']);

    // Hors drainage, il ne rend rien : le retour `null` précède tout rendu. Un
    // bandeau permanent dirait à tout visiteur qu'aucune partie ne se lance.
    expect($banner)->toMatch('/if \(!maintenance\) \{\s*return null;\s*\}/');
    expect(strpos($banner, 'return null;'))->toBeInt()
        ->toBeLessThan(strpos($banner, '<Alert'));

    // Il ne parle pas : l'unique `Alert` porte lui-même le rôle `note` (seul un
    // rôle passé à `Alert` supplante le `role="alert"` de `ui/alert.tsx`), et
    // aucun autre rôle ni aucune région vivante n'est posé. `\b` écarte
    // `<AlertDescription`.
    expect($banner)->toMatch('/<Alert\b[^>]*\brole="note"/');
    expect(preg_match_all('/<Alert\b/', $banner))->toBe(1)
        ->and(substr_count($banner, 'role='))->toBe(1)
        ->and($banner)->not->toContain('aria-live');

    // Ni heure, ni phase, ni nombre de parties : le texte n'a ni paramètre ni
    // chiffre, dans aucune langue activée (C18-bis § 3).
    foreach (Locale::cases() as $locale) {
        $text = __('common.maintenance.banner', [], $locale->value);

        expect($text)->toBeString()
            ->not->toBe('common.maintenance.banner')
            ->not->toMatch('/:[a-z_]+/')
            ->not->toMatch('/\d/');
    }

    // Monté une fois par la coquille publique, entre l'en-tête et le contenu
    // (§ 2.4).
    $layout = FrontSource::withoutComments((string) file_get_contents(
        resource_path('js/layouts/public/public-layout.tsx'),
    ));

    $header = strpos($layout, '<PublicHeader />');
    $mounted = strpos($layout, '<MaintenanceBanner />');
    $main = strpos($layout, '<main');

    expect(substr_count($layout, '<MaintenanceBanner />'))->toBe(1)
        ->and($header)->toBeInt()
        ->and($mounted)->toBeInt()
        ->and($main)->toBeInt()
        ->and($header < $mounted && $mounted < $main)->toBeTrue();
});
