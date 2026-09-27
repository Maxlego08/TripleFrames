<?php

use App\Enums\Locale;
use App\Models\Room;
use App\Support\I18n\LocaleCookie;
use App\Support\I18n\TranslationDomains;
use App\Support\Room\RoomCode;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\I18n\FrontSource;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Accueil et champ de code — spec 90 § 4.7 et § 10, lot L90-8
|--------------------------------------------------------------------------
|
| La page du starter est réécrite en accueil, dans `PublicLayout` : le jeu
| en une phrase, puis trois entrées sans compte — créer un salon, rejoindre
| par un champ de code, jouer en solo. Domaines `common` et `legal`, et rien
| d'autre.
|
| Aucun DOM au jalon 1, ni en Pest ni en Vitest (C18 § 2.4) : ce que la page
| RENDRA se prouve par sa réponse (composant, dictionnaire expédié) et par sa
| source sans ses commentaires (`FrontSource`). Le reste appartient à ses
| propriétaires : la coquille à `ShellTest`, le périmètre anti-couleur à
| `ThemeTokensPerimeterTest`, la parité du miroir `room-code.ts` à
| `RoomCodeTest` (50), la déclaration des domaines de toute route joueur à
| `TranslationDomainDeclarationTest`, l'indexation de l'accueil à
| `IndexingTest` (R-04). Le parcours réel du champ (saisie en minuscules ou
| avec tiret, refus sans requête, clavier) se vérifie à la main, dans la liste
| « terminé » du lot.
|
*/

/**
 * Les sept clés de l'accueil, figées par la spec 90 (§ 4.7, § 6.5), sans
 * placeholder.
 *
 * @return list<string>
 */
function homePageKeys(): array
{
    return [
        'common.home.heading',
        'common.home.tagline',
        'common.home.create_room',
        'common.home.join_room',
        'common.home.room_code_label',
        'common.home.room_code_invalid',
        'common.home.play_solo',
    ];
}

/**
 * Les clés du starter retirées (spec 90 § 6.6) : textes et liens de la page
 * d'accueil du kit, et les deux liens de pied de la barre latérale et de
 * l'en-tête du starter (`common.nav.{repository,documentation}`), retirés par
 * L90-3 et gardés ici comme témoins de non-retour.
 *
 * @return list<string>
 */
function homePageStarterKeys(): array
{
    return [
        'common.home.deploy',
        'common.home.description',
        'common.home.documentation',
        'common.home.intro',
        'common.home.title',
        'common.home.tutorials',
        'common.nav.repository',
        'common.nav.documentation',
    ];
}

/**
 * La source de la page, sans ses commentaires : un docblock qui cite
 * `<Form>` ou une URL n'est pas un rendu.
 */
function homePageSource(): string
{
    return FrontSource::withoutComments((string) file_get_contents(resource_path('js/pages/welcome.tsx')));
}

/**
 * Les noms qu'importe la page depuis un module, nom exporté → nom local
 * (`create as createRoom` donne `create` → `createRoom`).
 *
 * @return array<string, string>
 */
function homePageImports(string $source, string $module): array
{
    preg_match_all('/import\s*\{([^}]*)\}\s*from\s*\''.preg_quote($module, '/').'\'/', $source, $matches);

    $names = [];

    foreach ($matches[1] as $list) {
        foreach (array_filter(array_map('trim', explode(',', $list))) as $entry) {
            $parts = preg_split('/\s+as\s+/', $entry);

            if ($parts === false) {
                continue;
            }

            $names[$parts[0]] = $parts[1] ?? $parts[0];
        }
    }

    return $names;
}

/**
 * Visite de l'accueil par un visiteur dont le cookie `locale` (en clair)
 * nomme la langue choisie. `TranslationDomains` est un singleton que
 * l'application du test garde d'une requête à l'autre : il est oublié avant
 * chaque visite, comme le ferait une requête neuve.
 */
function homePageVisit(TestCase $test, Locale $locale): TestResponse
{
    app()->forgetInstance(TranslationDomains::class);

    return $test->withUnencryptedCookie(LocaleCookie::NAME, $locale->value)->get(route('home'));
}

it("rend l'accueil avec les seuls domaines common et legal", function () {
    $this->withoutVite();

    // Déclaration : la route ne demande que `legal`, `common` étant joint
    // d'office (spec 90 § 6.3).
    $home = Route::getRoutes()->getByName('home');

    expect($home)->not->toBeNull();

    $declared = array_values(array_filter(
        $home?->gatherMiddleware() ?? [],
        static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'translations:'),
    ));

    expect($declared)->toBe(['translations:legal']);

    $texts = [];

    foreach (Locale::cases() as $locale) {
        $response = homePageVisit($this, $locale)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('welcome')
                ->where('locale', $locale->value));

        $translations = $response->inertiaProps('translations');

        expect($translations)->toBeArray();

        // Le dictionnaire expédié : `common` et `legal` exactement.
        $domains = array_values(array_unique(array_map(
            static fn (string $key): string => (string) strstr($key, '.', true),
            array_keys($translations),
        )));
        sort($domains);

        expect($domains)->toBe(['common', 'legal'], "{$locale->value} : domaines expédiés");

        // Chaque texte de l'accueil est là, dans la langue du visiteur, sans
        // placeholder (§ 6.5).
        foreach (homePageKeys() as $key) {
            $text = $translations[$key] ?? null;

            expect($text)->toBeString("{$locale->value} : {$key} absente")
                ->and($text)->toBe(trans($key, [], $locale->value))
                ->and(trim((string) $text))->not->toBe('')
                ->and((string) $text)->not->toMatch('/:[A-Za-z_]/', "{$locale->value} : {$key} porte un placeholder");

            $texts[$locale->value][$key] = $text;
        }
    }

    // Deux langues, deux textes : l'accueil n'est pas resté en anglais.
    foreach (['common.home.heading', 'common.home.tagline', 'common.home.create_room', 'common.home.play_solo'] as $key) {
        expect($texts['fr'][$key])->not->toBe($texts['en'][$key], "{$key} identique en fr et en en");
    }

    // La page, elle, n'appelle que des clés de ces deux domaines.
    expect(array_values(array_diff(FrontSource::referencedDomains(homePageSource()), ['common', 'legal'])))
        ->toBe([]);
});

it('propose créer un salon, un champ de code pour rejoindre et jouer en solo', function () {
    config(['app.debug' => false]);
    $this->withoutVite();

    $source = homePageSource();

    // Les trois entrées ont leur texte ; la page n'appelle aucune autre clé
    // que celles de l'accueil, son titre et le message générique d'une visite
    // qui n'a pas reçu de page.
    $keys = FrontSource::literalKeys($source);

    expect(array_values(array_diff(homePageKeys(), $keys)))->toBe([], 'clé de l’accueil jamais appelée')
        ->and(array_values(array_diff($keys, [...homePageKeys(), 'common.nav.home', 'common.state.error'])))
        ->toBe([], 'clé inattendue sur l’accueil');

    // Créer un salon, jouer en solo : deux liens Wayfinder vers les routes
    // d'entrée de 50 et de 60, qui existent.
    expect(Route::has(['room.create', 'room.show', 'solo.create']))->toBeTrue();

    $room = homePageImports($source, '@/routes/room');
    $solo = homePageImports($source, '@/routes/solo');

    expect($room)->toHaveKeys(['create', 'show'])
        ->and($solo)->toHaveKey('create')
        ->and($source)->toContain("<Link href={{$room['create']}()}>")
        ->and($source)->toContain("<Link href={{$solo['create']}()}>");

    // Rejoindre : un champ, pas un lien. Libellé visible lié au champ, saisie
    // sans correction ni suggestion, capitales proposées au clavier virtuel.
    expect(preg_match('/<Label\s+htmlFor=\{(\w+)\}/', $source, $label))->toBe(1)
        ->and($source)->toMatch('/<Input\b[^>]*\bid=\{'.$label[1].'\}/s');

    preg_match('/<Input\b.*?\/>/s', $source, $input);

    expect($input[0] ?? '')->toContain('autoComplete="off"')
        ->and($input[0] ?? '')->toContain('autoCapitalize="characters"')
        ->and($input[0] ?? '')->toContain('spellCheck={false}')
        ->and($input[0] ?? '')->toContain('aria-describedby=')
        ->and($input[0] ?? '')->toContain('aria-invalid=');

    // Le message d'échec, sous le champ, porte l'identifiant que le champ
    // désigne.
    expect(preg_match('/aria-describedby=\{\s*failure === null \? undefined : (\w+)\s*\}/', $source, $described))->toBe(1)
        ->and($source)->toMatch('/<p\b[^>]*\bid=\{'.$described[1].'\}/s');

    // Le code passe par les miroirs client de `RoomCode`, et un code mal
    // formé est refusé AVANT toute requête : le contrôle précède la visite, et
    // sa branche d'échec se termine par un retour.
    $lib = homePageImports($source, '@/lib/game/room-code');

    expect($lib)->toHaveKeys(['normalizeRoomCode', 'isWellFormedRoomCode']);

    $check = strpos($source, "!{$lib['isWellFormedRoomCode']}(");
    $visit = strpos($source, 'router.visit(');

    expect($check)->toBeInt()
        ->and($visit)->toBeInt()
        ->and($check < $visit)->toBeTrue('le contrôle de forme doit précéder la visite')
        ->and(substr($source, (int) $check, (int) $visit - (int) $check))->toContain('return;');

    // Un code bien formé part en visite GET vers `room.show`, par Wayfinder et
    // avec le code normalisé : jamais `<Form>`, jamais une autre requête.
    // La réponse de la visite n'est jamais retenue : Inertia 3 passe aussi
    // par `onHttpException` les PAGES d'erreur du serveur (`error` 404 d'un
    // code inconnu, 429, salon expiré en 410), et les y retenir empêcherait
    // leur rendu. Seule la coupure réseau est rattrapée par la page.
    // La visite reste un GET, méthode par défaut de `router.visit` : aucune
    // option `method`, aucun autre verbe du routeur ni de l'action Wayfinder
    // (`room.show` ne sert que GET et HEAD, tout autre verbe finirait en 405).
    expect($source)->toMatch('/router\.visit\(\s*'.$room['show'].'\.url\(\{\s*room:\s*'.$lib['normalizeRoomCode'].'\(/')
        ->and($source)->not->toContain('onHttpException')
        ->and(substr_count($source, 'router.visit('))->toBe(1)
        ->and($source)->not->toMatch('/\bmethod\s*:/', 'la visite du code doit rester un GET')
        ->and($source)->not->toMatch('/\brouter\.(?:post|put|patch|delete)\(/', 'la visite du code doit rester un GET')
        ->and($source)->not->toMatch('/\b'.$room['show'].'\.(?:post|put|patch|delete|form)\b/', 'la visite du code doit rester un GET')
        ->and($source)->not->toContain('<Form')
        ->and($source)->not->toContain('useForm')
        ->and($source)->not->toContain('useHttp')
        ->and($source)->not->toContain('fetch(')
        ->and($source)->not->toContain('router.get(');

    // Aucune URL écrite, aucune longueur ni aucun alphabet de code : ils
    // restent à Wayfinder et à `RoomCode`.
    expect($source)->not->toMatch('/[\'"`]\//', 'une URL écrite en dur')
        ->and($source)->not->toContain('ROOM_CODE')
        ->and($source)->not->toContain(RoomCode::ALPHABET)
        ->and($source)->not->toMatch('/\b(?:maxLength|minLength|pattern)=/');

    // La cible de la visite : un code bien formé mais inconnu rend la page
    // `error` 404, en visite Inertia, jamais une page blanche.
    $unknown = 'ABCDEF';

    expect(RoomCode::isWellFormed($unknown))->toBeTrue()
        ->and(Room::query()->where('room_code', $unknown)->exists())->toBeFalse();

    app()->forgetInstance(TranslationDomains::class);

    $response = $this->withHeaders([
        Header::INERTIA => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ])->get(route('room.show', ['room' => $unknown]))
        ->assertNotFound()
        ->assertHeader(Header::INERTIA, 'true');

    expect($response->json('component'))->toBe('error')
        ->and($response->json('props.status'))->toBe(404);
});

it('ne contient plus aucun lien ni texte du starter', function () {
    $this->withoutVite();

    $source = homePageSource();

    // Aucun lien sortant, aucune mention du kit, aucune illustration en ligne,
    // aucun style en dur : la page du starter est réécrite, pas retouchée.
    expect($source)->not->toMatch('/https?:\/\//')
        ->and($source)->not->toMatch('/laravel|laracasts/i')
        ->and($source)->not->toContain('<svg')
        ->and($source)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
        ->and($source)->not->toContain('dark:');

    // Aucun lien de compte dans la page : ils relèvent de l'en-tête public,
    // qui ne les rend que si `accountsOpen` est vrai (spec 90 § 2.4).
    expect(array_intersect(array_keys(homePageImports($source, '@/routes')), ['login', 'register', 'dashboard']))
        ->toBe([])
        ->and($source)->not->toMatch('/\b(?:login|register|dashboard)\(/')
        ->and($source)->not->toContain('auth.user');

    // Les dictionnaires : les sept clés de l'accueil et rien d'autre sous
    // `common.home`, plus aucune clé ni aucun texte du starter.
    $expected = array_map(static fn (string $key): string => substr($key, strlen('common.home.')), homePageKeys());
    sort($expected);

    foreach (Locale::cases() as $locale) {
        $home = trans('common.home', [], $locale->value);

        expect($home)->toBeArray();

        $present = array_keys((array) $home);
        sort($present);

        expect($present)->toBe($expected, "{$locale->value} : clés de common.home");

        foreach (homePageStarterKeys() as $key) {
            expect(trans($key, [], $locale->value))->toBe($key, "{$locale->value} : {$key} n’est pas retirée");
        }

        // Et la page réellement servie n'en expédie aucune.
        $translations = homePageVisit($this, $locale)->assertOk()->inertiaProps('translations');

        expect($translations)->toBeArray()
            ->and(array_values(array_intersect(homePageStarterKeys(), array_keys($translations))))->toBe([]);

        foreach ($translations as $key => $text) {
            expect((string) $text)->not->toMatch('/laravel|laracasts/i', "{$locale->value} : {$key} cite le kit");
        }
    }
});
