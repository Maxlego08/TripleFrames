<?php

use App\Enums\LegalPage;
use App\Enums\Locale;
use App\Http\Middleware\VaryOnLanguage;
use App\Support\I18n\LocaleCookie;
use App\Support\I18n\TranslationDomains;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\I18n\FrontSource;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pages légales et « signaler un contenu » — spec 90 § 4, lot L90-4
|--------------------------------------------------------------------------
|
| Au jalon 1, les quatre pages publiques existent EN SQUELETTE : textes
| provisoires marqués comme tels, faits déjà vrais seulement, marqueurs
| `[À FOURNIR : …]` partout ailleurs. Elles ne sont bloquantes pour rien, mais
| le pied de page n'est jamais un lien mort, et le jalon 2 n'est qu'un
| remplissage.
|
| Habillage traduit, corps en français (décision 4) : le corps est un partiel
| Blade du dépôt, injecté dans un `<div lang="fr">` ; l'habillage et
| `<html lang>` suivent la locale du visiteur (n° 69, A-42).
|
| Aucun DOM au jalon 1, ni en Pest ni en Vitest (C18 § 2.4) : ce que la page
| RENDRA se prouve par ses props, par le dictionnaire expédié et par la source
| de `pages/legal/show.tsx`. L'indexation de ces routes (`noindex` permanente
| de « signaler un contenu », drapeau des trois pages légales) est prouvée
| par `IndexingTest`, et la déclaration du domaine `legal` par
| `TranslationDomainDeclarationTest` : pas ici (R-04).
|
*/

/**
 * Nom de la route de chaque page. `match` exhaustif : un cas ajouté à
 * `LegalPage` sans route fait échouer toute la suite de ce fichier.
 */
function legalPagesRouteName(LegalPage $page): string
{
    return match ($page) {
        LegalPage::Notice => 'legal.notice',
        LegalPage::Terms => 'legal.terms',
        LegalPage::Privacy => 'legal.privacy',
        LegalPage::Report => 'takedown.create',
    };
}

/**
 * Codes des locales activées.
 *
 * @return list<string>
 */
function legalPagesLocales(): array
{
    return array_map(static fn (Locale $locale): string => $locale->value, Locale::cases());
}

/**
 * Source de la page Inertia unique, sans ses commentaires : un docblock qui
 * cite `lang="fr"` n'est pas un rendu.
 */
function legalPagesSource(): string
{
    return FrontSource::withoutComments((string) file_get_contents(resource_path('js/pages/legal/show.tsx')));
}

/**
 * Visite d'une page par un visiteur dont le cookie `locale` (en clair, exclu
 * du chiffrement) nomme la langue choisie.
 *
 * `TranslationDomains` est un singleton : l'application du test survit d'une
 * requête à l'autre, et les domaines de la visite précédente fausseraient la
 * suivante. Il est oublié avant chaque visite, comme le ferait une requête
 * neuve.
 */
function legalPagesVisit(TestCase $test, LegalPage $page, string $locale): TestResponse
{
    app()->forgetInstance(TranslationDomains::class);

    return $test->withUnencryptedCookie(LocaleCookie::NAME, $locale)->get(route(legalPagesRouteName($page)));
}

/**
 * Traduction d'une clé du domaine `legal` telle qu'expédiée à la page.
 */
function legalPagesShipped(TestResponse $response, string $key): mixed
{
    $translations = $response->inertiaProps('translations');

    expect($translations)->toBeArray();

    return $translations[$key] ?? null;
}

it('rend le corps légal dans un fragment français quelle que soit la locale du visiteur', function () {
    $this->withoutVite();

    // Le corps n'est injecté qu'à un seul endroit, un conteneur qui porte
    // `lang="fr"` en littéral : c'est lui qui le fait prononcer en français
    // par un lecteur d'écran (05 § Attribut `lang`).
    $source = legalPagesSource();

    // Le corps passe d'abord par `prepareLegalDocument` (sommaire et ancres
    // des sections), qui n'en rend que la forme préparée.
    expect(preg_match('/prepareLegalDocument\(\s*body\s*\)/', $source))
        ->toBe(1, 'le corps doit passer par prepareLegalDocument')
        ->and(preg_match('/<div\s+lang="fr"[^>]*?dangerouslySetInnerHTML=\{\{\s*__html:\s*document\.html\s*,?\s*\}\}/s', $source))
        ->toBe(1, 'le corps doit être injecté dans un <div lang="fr">')
        ->and(substr_count($source, 'dangerouslySetInnerHTML'))->toBe(1)
        // Le sommaire reprend les intitulés `<h2>` du corps français : sa
        // liste porte `lang="fr"` elle aussi, et aucun intitulé n'est rendu
        // ailleurs.
        ->and(preg_match('/<ol\s+lang="fr"[^>]*>(?:(?!<\/ol>).)*?\{section\.label\}/s', $source))
        ->toBe(1, 'les intitulés du sommaire doivent être rendus dans un <ol lang="fr">')
        ->and(substr_count($source, 'section.label'))->toBe(1);

    foreach (LegalPage::cases() as $page) {
        $partial = view()->file($page->viewPath())->render();

        expect($partial)->toContain('<h2>');

        $titles = [];

        foreach (legalPagesLocales() as $locale) {
            $response = legalPagesVisit($this, $page, $locale)
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia
                    ->component('legal/show')
                    ->where('page', $page->value)
                    ->where('locale', $locale)
                    // Le même corps français, octet pour octet, pour tous.
                    ->where('body', $partial));

            $titles[$locale] = legalPagesShipped($response, $page->titleKey());
        }

        // L'habillage, lui, suit la langue du visiteur.
        expect($titles['fr'])->toBe(trans($page->titleKey(), [], 'fr'))
            ->and($titles['en'])->toBe(trans($page->titleKey(), [], 'en'))
            ->and($titles['fr'])->not->toBe($titles['en']);
    }
});

it("garde le lang de html d'une page légale sur la locale du visiteur", function () {
    $this->withoutVite();

    $htmlLang = static function (TestResponse $response): ?string {
        return preg_match('/<html\b[^>]*\blang="([^"]*)"/i', (string) $response->getContent(), $match) === 1
            ? $match[1]
            : null;
    };

    // Première visite, sans cookie : langue négociée depuis `Accept-Language`.
    // Jouée AVANT toute visite à cookie, que le client de test garde pour
    // les requêtes suivantes.
    foreach (LegalPage::cases() as $page) {
        foreach (['en-US,en;q=0.9' => 'en', 'fr-FR,fr;q=0.9' => 'fr'] as $header => $expected) {
            app()->forgetInstance(TranslationDomains::class);

            $response = $this->withHeader('Accept-Language', $header)
                ->get(route(legalPagesRouteName($page)))
                ->assertOk();

            expect($htmlLang($response))->toBe($expected, "{$page->value} : <html lang> négocié depuis {$header}");
        }
    }

    // Langue choisie (cookie) : jamais un `fr` forcé pour un visiteur en `en`.
    foreach (LegalPage::cases() as $page) {
        foreach (legalPagesLocales() as $locale) {
            expect($htmlLang(legalPagesVisit($this, $page, $locale)->assertOk()))
                ->toBe($locale, "{$page->value} : <html lang> pour un visiteur en {$locale}");
        }
    }
});

it('fait varier chaque page légale sur Accept-Language', function () {
    $this->withoutVite();

    foreach (LegalPage::cases() as $page) {
        $name = legalPagesRouteName($page);
        $route = Route::getRoutes()->getByName($name);

        // La décision appartient à la route (défaut `vary_language`).
        expect($route)->toBeInstanceOf(RoutingRoute::class)
            ->and($route?->defaults[VaryOnLanguage::ROUTE_FLAG] ?? null)->toBeTrue("{$name} ne porte pas le drapeau de VaryOnLanguage");

        foreach (legalPagesLocales() as $locale) {
            $vary = array_map(
                static fn (string $dimension): string => strtolower(trim($dimension)),
                legalPagesVisit($this, $page, $locale)->assertOk()->getVary(),
            );

            expect($vary)->toContain('accept-language');
        }
    }
});

it('rend le partiel de chaque cas de LegalPage', function () {
    $this->withoutVite();

    $directory = str_replace('\\', '/', resource_path('views/legal'));
    $partials = [];

    foreach (LegalPage::cases() as $page) {
        $path = str_replace('\\', '/', $page->viewPath());

        // Un CHEMIN, jamais un nom de vue pointé (§ 4.1) : `FileViewFinder`
        // lirait `legal.notice.fr` comme `legal/notice/fr.blade.php`.
        expect($path)->toBe("{$directory}/{$page->value}.fr.blade.php")
            ->and(is_file($path))->toBeTrue("partiel absent : {$path}")
            ->and(view()->exists("legal.{$page->value}.fr"))->toBeFalse();

        $rendered = view()->file($page->viewPath())->render();

        expect(trim($rendered))->not->toBe('')
            // Le titre est l'habillage : le partiel commence au niveau 2.
            ->and($rendered)->not->toContain('<h1');

        // La route de la page rend bien CE partiel.
        app()->forgetInstance(TranslationDomains::class);

        $this->get(route(legalPagesRouteName($page)))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('legal/show')
                ->where('page', $page->value)
                ->where('body', $rendered));

        // Le titre, clé construite par l'enum, existe dans les deux langues.
        foreach (legalPagesLocales() as $locale) {
            expect(trans($page->titleKey(), [], $locale))->not->toBe($page->titleKey());
        }

        $partials[] = $path;
    }

    // Réciproque : aucun partiel orphelin, que rien ne servirait.
    $onDisk = array_map(
        static fn (string $file): string => str_replace('\\', '/', $file),
        glob($directory.'/*.blade.php') ?: [],
    );

    sort($onDisk);
    sort($partials);

    expect($onDisk)->toBe($partials);
});

it('affiche le bandeau provisoire tant que la configuration le demande', function () {
    $this->withoutVite();

    // Au jalon 1, les quatre pages sont des squelettes : le dépôt les livre
    // toutes marquées provisoires. Le bandeau tombe page par page, par un
    // commit, quand le texte définitif remplace le squelette.
    $shipped = require config_path('legal.php');

    foreach (LegalPage::cases() as $page) {
        expect($shipped['pages'][$page->value]['provisional'] ?? null)->toBeTrue("{$page->value} n'est pas livrée provisoire");
    }

    // La page rend le bandeau sous la prop, et seulement sous elle.
    expect(legalPagesSource())->toMatch("/\\{provisional\\s*&&[^}]*?t\\('legal\\.provisional'\\)/s");

    foreach (LegalPage::cases() as $page) {
        $key = "legal.pages.{$page->value}.provisional";

        foreach (legalPagesLocales() as $locale) {
            config([$key => true]);

            $response = legalPagesVisit($this, $page, $locale)
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->where('provisional', true));

            expect(legalPagesShipped($response, 'legal.provisional'))->toBe(trans('legal.provisional', [], $locale));

            config([$key => false]);

            legalPagesVisit($this, $page, $locale)
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->where('provisional', false));
        }

        // Une entrée absente garde le texte marqué provisoire : c'est l'erreur
        // sans danger.
        config(["legal.pages.{$page->value}" => []]);

        legalPagesVisit($this, $page, 'fr')
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->where('provisional', true));
    }
});

it('remplace un contact absent par la mention traduite', function () {
    $this->withoutVite();

    // La page rend la mention quand l'adresse manque, la phrase sinon.
    $source = legalPagesSource();

    expect($source)->toMatch("/email\\s*===\\s*null\\s*\\?[^:]*?t\\('legal\\.contact\\.unavailable'\\)/s")
        ->and($source)->toContain("t('legal.contact.description'");

    foreach (LegalPage::cases() as $page) {
        foreach (legalPagesLocales() as $locale) {
            config(['legal.contact_email' => null]);

            $response = legalPagesVisit($this, $page, $locale)
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->where('contactEmail', null));

            expect(legalPagesShipped($response, 'legal.contact.unavailable'))
                ->toBe(trans('legal.contact.unavailable', [], $locale))
                ->not->toBe('legal.contact.unavailable');

            // Adresse publiée : transmise telle quelle, avec sa phrase.
            config(['legal.contact_email' => 'contact@example.org']);

            $response = legalPagesVisit($this, $page, $locale)
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->where('contactEmail', 'contact@example.org'));

            expect(legalPagesShipped($response, 'legal.contact.description'))->toContain(':email');
        }
    }
});

it('traite une adresse de contact vide comme absente', function () {
    $this->withoutVite();

    // `.env.example` livre la variable VIDE (CI à zéro secret, `composer
    // setup` copie le fichier), une seule fois.
    $lines = array_values(preg_grep('/^\s*LEGAL_CONTACT_EMAIL\s*=/', file(base_path('.env.example')) ?: []));

    expect($lines)->toHaveCount(1)
        ->and(trim($lines[0]))->toBe('LEGAL_CONTACT_EMAIL=');

    // Le piège : une variable écrite vide rend une CHAÎNE VIDE, pas `null`
    // (`Env::get` ne rend `null` que pour une variable absente ou écrite
    // `null`). La configuration la transmet donc telle quelle…
    $saved = [$_SERVER['LEGAL_CONTACT_EMAIL'] ?? null, $_ENV['LEGAL_CONTACT_EMAIL'] ?? null, getenv('LEGAL_CONTACT_EMAIL')];

    try {
        $_SERVER['LEGAL_CONTACT_EMAIL'] = '';
        $_ENV['LEGAL_CONTACT_EMAIL'] = '';
        putenv('LEGAL_CONTACT_EMAIL=');

        $fromEnv = (require config_path('legal.php'))['contact_email'];
    } finally {
        [$server, $env, $put] = $saved;

        if ($server === null) {
            unset($_SERVER['LEGAL_CONTACT_EMAIL']);
        } else {
            $_SERVER['LEGAL_CONTACT_EMAIL'] = $server;
        }

        if ($env === null) {
            unset($_ENV['LEGAL_CONTACT_EMAIL']);
        } else {
            $_ENV['LEGAL_CONTACT_EMAIL'] = $env;
        }

        putenv($put === false ? 'LEGAL_CONTACT_EMAIL' : "LEGAL_CONTACT_EMAIL={$put}");
    }

    expect($fromEnv)->toBe('');

    // … et c'est le contrôleur qui la traite comme absente, blancs compris.
    foreach (['', '   '] as $blank) {
        config(['legal.contact_email' => $blank]);

        foreach (LegalPage::cases() as $page) {
            legalPagesVisit($this, $page, 'fr')
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->where('contactEmail', null));
        }
    }
});

it('ne livre aucun texte légal qualifiant le service de non commercial', function () {
    // Principe 12, décision 2 : l'architecture reste neutre pour un plan
    // payant futur, la position repose sur un processus démontrable, et
    // aucune page publiée ne qualifie le service de « non commercial ».
    // Le motif couvre les graphies courantes, en français et en anglais.
    $pattern = '/non[\s\x{00A0}\x{202F}\x{2010}\x{2011}-]*commercia|(?:sans|à)\s+but\s+(?:non\s+)?lucratif|not[\s-]*for[\s-]*profit|non[\s-]*profit/iu';

    foreach ([
        'usage non commercial', 'Non-commercial', 'NONCOMMERCIAL', "non\u{2011}commerciale",
        "non\u{00A0}commercial", 'à but non lucratif', 'sans but lucratif', 'not-for-profit', 'a non-profit game',
    ] as $sample) {
        expect(preg_match($pattern, $sample))->toBe(1, "le motif ne reconnaît pas « {$sample} »");
    }

    // Ce qui est LIVRÉ : le rendu de chaque partiel (les commentaires Blade
    // ne le sont pas) et chaque valeur du domaine `legal`, dans chaque langue.
    $texts = [];

    foreach (LegalPage::cases() as $page) {
        $html = view()->file($page->viewPath())->render();

        $texts["partiel {$page->value}"] = $html;
        $texts["partiel {$page->value} (texte)"] = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    foreach (legalPagesLocales() as $locale) {
        $lines = require lang_path("{$locale}/legal.php");

        expect($lines)->toBeArray();

        foreach (Arr::dot($lines) as $key => $line) {
            $texts["{$locale}/legal.php › {$key}"] = (string) $line;
        }
    }

    $violations = array_keys(array_filter(
        $texts,
        static fn (string $text): bool => preg_match($pattern, $text) === 1,
    ));

    expect($violations)->toBe([])
        // Quatre partiels, deux formes chacun, et les deux dictionnaires.
        ->and(count($texts))->toBeGreaterThan(8 + 2 * 10);
});

it("rend signaler un contenu sans formulaire ni route d'écriture au jalon 1", function () {
    $this->withoutVite();

    // Page statique : aucune écriture tant que le SMTP et la file de retrait
    // du back-office n'existent pas (jalon 2).
    $response = $this->get(route('takedown.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('legal/show')
            ->where('page', LegalPage::Report->value));

    $body = $response->inertiaProps('body');

    expect($body)->toBeString()
        ->not->toMatch('/<\s*(?:form|input|textarea|select|button)\b/i');

    // Ni la page unique, ni aucun de ses cas n'ouvre un formulaire.
    expect(legalPagesSource())->not->toMatch('/<form\b|<Form\b|\buseForm\b|\buseHttp\b|router\s*\.\s*(?:post|put|patch|delete)\b/');

    // Aucune route d'écriture : ni `takedown.store`, ni aucune méthode autre
    // que GET ou HEAD sur `/report-content`, ni aucune autre route
    // `takedown.*` que la page elle-même.
    expect(Route::has('takedown.store'))->toBeFalse();

    $takedown = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (trim($route->uri(), '/') === 'report-content' || str_starts_with($name, 'takedown.')) {
            $takedown[] = $name;

            expect(array_diff($route->methods(), ['GET', 'HEAD']))->toBe([], "/{$route->uri()} accepte une écriture");
        }
    }

    expect($takedown)->toBe(['takedown.create']);

    $this->post('/report-content')->assertMethodNotAllowed();
});
