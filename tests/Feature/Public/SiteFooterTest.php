<?php

use App\Models\User;
use App\Support\I18n\TranslationDomains;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| Pied de page joueur — spec 90 § 3.1, § 3.2 et § 6.3, lot L90-3
|--------------------------------------------------------------------------
|
| Le pied de page est présent sur TOUS les écrans joueurs, écran de jeu
| compris : les pages légales y sont toujours atteignables et l'attribution
| TMDB toujours visible (principe 12). Il est rendu côté client ; ce qui se
| prouve côté serveur, c'est que chaque page joueur REÇOIT les clés qu'il
| appelle — sinon il afficherait des clés brutes (règle 4). D'où la règle
| « `legal` déclaré sur toute route joueur » (n° 68, D3 du 23/09).
|
| Le cas « envoie au back-office les clés du pied admin et aucune clé legal »
| est écrit dans ce fichier par L20-2, qui livre `admin.footer.*` (dépendance
| inversée) : 90 ne le duplique pas (R-04).
|
*/

/**
 * Les deux fichiers qui composent le pied de page joueur.
 *
 * @return list<string>
 */
function siteFooterFiles(): array
{
    return [
        resource_path('js/components/public/site-footer.tsx'),
        resource_path('js/components/public/tmdb-attribution.tsx'),
    ];
}

/**
 * Clés que le pied de page doit recevoir : celles qu'il appelle réellement
 * (lues sur la source), plus celles que fige la spec (§ 3.1, § 3.2, § 6.5) —
 * les libellés des quatre liens compris, pour que les pages les reçoivent dès
 * aujourd'hui et que le lot qui branche les liens n'ait rien à redéclarer.
 *
 * @return list<string>
 */
function siteFooterExpectedKeys(): array
{
    $called = [];

    foreach (siteFooterFiles() as $file) {
        array_push($called, ...FrontSource::literalKeys((string) file_get_contents($file)));
    }

    return array_values(array_unique([
        'legal.footer.label',
        'legal.footer.sheet_description',
        'legal.footer.notice',
        'legal.footer.terms',
        'legal.footer.privacy',
        'legal.footer.report',
        'legal.new_tab',
        'legal.tmdb.attribution',
        'legal.tmdb.logo_alt',
        'common.action.close',
        ...$called,
    ]));
}

/**
 * Clés attendues absentes du dictionnaire expédié par une réponse.
 *
 * @return list<string>
 */
function siteFooterMissingKeys(TestResponse $response): array
{
    $translations = $response->inertiaProps('translations');

    expect($translations)->toBeArray();

    return array_values(array_diff(siteFooterExpectedKeys(), array_keys($translations)));
}

it('envoie les clés du pied de page à toute page joueur', function () {
    // Le pied appelle bien ses clés : un balayage qui ne verrait rien
    // prouverait l'envoi d'une liste vide.
    $called = [];

    foreach (siteFooterFiles() as $file) {
        array_push($called, ...FrontSource::literalKeys((string) file_get_contents($file)));
    }

    expect($called)->toContain('legal.footer.label', 'legal.tmdb.attribution', 'legal.tmdb.logo_alt');

    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();

    // Une page par lieu de déclaration de la spec 90 § 6.3 qui existe au
    // jalon 1 : accueil (`routes/web.php`), écrans de Fortify
    // (`config/fortify.php`, invité et connecté), tableau de bord
    // (`routes/web.php`), et les deux groupes de `routes/settings.php`. Les
    // pages `legal/*`, `error`, `game/*` et `room/*` arrivent avec leurs lots,
    // et `TranslationDomainDeclarationTest` refuse toute route joueur qui
    // oublierait `legal`.
    $pages = [
        'accueil' => fn () => $this->get(route('home')),
        'connexion' => fn () => $this->get(route('login')),
        'inscription' => fn () => $this->get(route('register')),
        'mot de passe oublié' => fn () => $this->get(route('password.request')),
        'nouveau mot de passe' => fn () => $this->get(route('password.reset', ['token' => 'jeton'])),
        'vérification d\'adresse' => fn () => $this->actingAs($unverified)->get(route('verification.notice')),
        'confirmation du mot de passe' => fn () => $this->actingAs($verified)->get(route('password.confirm')),
        'tableau de bord' => fn () => $this->actingAs($verified)->get(route('dashboard')),
        'profil' => fn () => $this->actingAs($verified)->get(route('profile.edit')),
        'apparence' => fn () => $this->actingAs($verified)->get(route('appearance.edit')),
        'sécurité' => fn () => $this->actingAs($verified)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit')),
    ];

    $missing = [];

    // Les visites en invité passent d'abord : `actingAs` vaut pour toutes les
    // requêtes suivantes du test, et les écrans `guest` de Fortify
    // renverraient un compte connecté vers le tableau de bord.
    //
    // `TranslationDomains` est un singleton : l'application du test survit
    // d'une requête à l'autre, et les domaines déclarés par la page
    // précédente fausseraient la suivante. Il est oublié avant chaque visite,
    // comme le ferait une requête neuve.
    foreach ($pages as $label => $visit) {
        app()->forgetInstance(TranslationDomains::class);

        $response = $visit()->assertOk();

        foreach (siteFooterMissingKeys($response) as $key) {
            $missing[] = "{$label} : {$key}";
        }
    }

    expect($missing)->toBe([]);
});

it('lie chaque page légale dès que sa route existe', function () {
    // Les liens du pied sont des helpers Wayfinder (principe 11) : ils ne
    // peuvent naître qu'avec leurs routes, créées par L90-4. Ce test tient la
    // promesse de `LEGAL_LINKS` : dès qu'une route légale existe, le pied
    // appelle son libellé — le lien ne peut pas être oublié.
    $labels = [
        'legal.notice' => 'legal.footer.notice',
        'legal.terms' => 'legal.footer.terms',
        'legal.privacy' => 'legal.footer.privacy',
        'takedown.create' => 'legal.footer.report',
    ];

    $called = FrontSource::literalKeys((string) file_get_contents(resource_path('js/components/public/site-footer.tsx')));
    $unlinked = [];

    foreach ($labels as $route => $label) {
        if (Route::has($route) && ! in_array($label, $called, true)) {
            $unlinked[] = "{$route} ({$label})";
        }
    }

    expect($unlinked)->toBe([]);
});
