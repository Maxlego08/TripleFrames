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
| par le code ET le pseudo en un seul envoi (D55 du 02/10), jouer en solo.
| Domaines `common` et `legal`, et rien d'autre.
|
| Aucun DOM au jalon 1, ni en Pest ni en Vitest (C18 § 2.4) : ce que la page
| RENDRA se prouve par sa réponse (composant, dictionnaire expédié) et par sa
| source sans ses commentaires (`FrontSource`). Le reste appartient à ses
| propriétaires : la coquille à `ShellTest`, le périmètre anti-couleur à
| `ThemeTokensPerimeterTest`, la parité du miroir `room-code.ts` à
| `RoomCodeTest` (50), la déclaration des domaines de toute route joueur à
| `TranslationDomainDeclarationTest`, l'indexation de l'accueil à
| `IndexingTest` (R-04), la prise de siège elle-même à `SeatTakingTest`. Le
| parcours réel des champs (saisie en minuscules ou avec tiret, refus sans
| requête, pseudo refusé, clavier) se vérifie à la main, dans la liste
| « terminé » du lot.
|
*/

/**
 * Les clés de l'accueil, figées par la spec 90 (§ 4.7, § 6.5), sans
 * placeholder de traduction ; le pseudo s'ajoute par D55 du 02/10.
 *
 * @return list<string>
 */
function homePageKeys(): array
{
    return [
        'common.home.heading',
        'common.home.tagline',
        'common.home.create_prompt',
        'common.home.create_prompt_lead',
        'common.home.create_room',
        'common.home.join_heading',
        'common.home.join_room',
        'common.home.room_code_label',
        'common.home.room_code_help',
        'common.home.room_code_placeholder',
        // Le bouton œil du code, masqué par défaut (diffusion d'écran,
        // amendé le 06/10).
        'common.home.room_code_show',
        'common.home.room_code_hide',
        'common.home.room_code_invalid',
        'common.home.nickname_label',
        'common.home.nickname_placeholder',
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

it('propose créer un salon, rejoindre par le code et le pseudo, et jouer en solo', function () {
    config(['app.debug' => false]);
    $this->withoutVite();

    $source = homePageSource();

    // Les trois entrées ont leur texte ; la page n'appelle aucune autre clé
    // que celles de l'accueil, son titre, le message générique d'un envoi
    // sans réponse et la mention des CGU (domaine `legal`, déjà expédié).
    $keys = FrontSource::literalKeys($source);

    expect(array_values(array_diff(homePageKeys(), $keys)))->toBe([], 'clé de l’accueil jamais appelée')
        ->and(array_values(array_diff($keys, [
            ...homePageKeys(),
            'common.nav.home',
            'common.state.error',
            'legal.terms_notice',
            'legal.new_tab',
        ])))
        ->toBe([], 'clé inattendue sur l’accueil');

    // Créer un salon, jouer en solo : deux liens Wayfinder vers les routes
    // d'entrée de 50 et de 60, qui existent ; rejoindre vise `room.join`.
    expect(Route::has(['room.create', 'room.join', 'solo.create', 'legal.terms']))->toBeTrue();

    $room = homePageImports($source, '@/routes/room');
    $solo = homePageImports($source, '@/routes/solo');

    expect($room)->toHaveKey('create')
        ->and($solo)->toHaveKey('create')
        ->and($source)->toContain("<Link href={{$room['create']}()}>")
        ->and($source)->toContain("<Link href={{$solo['create']}()}>");

    // Rejoindre : deux champs, le code puis le pseudo, chacun avec son
    // libellé visible lié ; aucun sélecteur d'avatar (D55 du 02/10).
    expect(preg_match_all('/<Label\s+htmlFor=\{(\w+)\}/', $source, $labels))->toBe(2);

    foreach ($labels[1] as $field) {
        expect($source)->toMatch('/<Input\b[^>]*\bid=\{'.$field.'\}/s');
    }

    expect($source)->not->toContain('AvatarPicker')
        ->and($source)->not->toMatch('/\bavatar\b/');

    preg_match_all('/<Input\b.*?\/>/s', $source, $inputs);

    expect($inputs[0])->toHaveCount(2);

    [$codeInput, $nicknameInput] = $inputs[0];

    // Le code : saisie sans correction ni suggestion, capitales proposées au
    // clavier virtuel.
    expect($codeInput)->toContain('autoComplete="off"')
        ->and($codeInput)->toContain('autoCapitalize="characters"')
        ->and($codeInput)->toContain('spellCheck={false}')
        ->and($codeInput)->toContain('aria-describedby=')
        ->and($codeInput)->toContain('aria-invalid=');

    // Le pseudo : le champ que `JoinRoomRequest` valide, sans correction, son
    // erreur liée.
    expect($nicknameInput)->toContain('name={NICKNAME_FIELD}')
        ->and($source)->toContain("const NICKNAME_FIELD = 'nickname';")
        ->and($nicknameInput)->toContain('autoComplete="nickname"')
        ->and($nicknameInput)->toContain('spellCheck={false}')
        ->and($nicknameInput)->toContain('aria-describedby=')
        ->and($nicknameInput)->toContain('aria-invalid=');

    // L'aide et le message d'échec du code sont tous deux reliés au champ.
    expect($codeInput)->toContain('codeHelpId')
        ->and($codeInput)->toContain('failureId')
        ->and($source)->toMatch('/<p\b[^>]*\bid=\{codeHelpId\}/s')
        ->and($source)->toMatch('/<p\b[^>]*\bid=\{failureId\}/s');

    // Les refus du serveur : le pseudo sous son champ, le salon (complet,
    // jeton expulsé) en alerte.
    expect($source)->toContain('errors.nickname')
        ->and($source)->toContain('errors.room')
        ->and($source)->toContain('role="alert"');

    // La mention des CGU : lien Wayfinder, nouvel onglet.
    expect(homePageImports($source, '@/routes/legal'))->toHaveKey('terms')
        ->and($source)->toMatch('/<a\s+href=\{terms\(\)\.url\}\s+target="_blank"\s+rel="noopener"/');

    // Le code passe par les miroirs client de `RoomCode`, et un code mal
    // formé est refusé AVANT toute requête : le contrôle précède l'envoi, et
    // sa branche d'échec se termine par un retour.
    $lib = homePageImports($source, '@/lib/game/room-code');

    expect($lib)->toHaveKeys(['normalizeRoomCode', 'isWellFormedRoomCode']);

    $check = strpos($source, "!{$lib['isWellFormedRoomCode']}(");
    $post = strpos($source, 'router.post(');

    expect($check)->toBeInt()
        ->and($post)->toBeInt()
        ->and($check < $post)->toBeTrue('le contrôle de forme doit précéder l’envoi')
        ->and(substr($source, (int) $check, (int) $post - (int) $check))->toContain('return;');

    // Un code bien formé part en un seul POST vers `room.join`, par l'action
    // Wayfinder de `RoomEntryController` et avec le code normalisé, le pseudo
    // seul dans le corps. Les PAGES d'erreur du serveur (`error` 404 d'un
    // code inconnu, 429, salon expiré) ne sont jamais retenues : aucun
    // `onHttpException`. Seule la coupure réseau est rattrapée par la page.
    expect($source)->toContain("import RoomEntryController from '@/actions/App/Http/Controllers/Room/RoomEntryController';")
        ->and($source)->toMatch('/router\.post\(\s*RoomEntryController\.store\.url\(\{\s*room:\s*'.$lib['normalizeRoomCode'].'\(/')
        ->and($source)->toMatch('/\{\s*\[NICKNAME_FIELD\]:\s*nickname\s*\}/')
        ->and($source)->not->toContain('onHttpException')
        ->and(substr_count($source, 'router.post('))->toBe(1)
        ->and($source)->not->toMatch('/\brouter\.(?:visit|get|put|patch|delete)\(/')
        ->and($source)->not->toContain('<Form')
        ->and($source)->not->toContain('useForm')
        ->and($source)->not->toContain('useHttp')
        ->and($source)->not->toContain('fetch(');

    // Aucune URL écrite, aucune longueur ni aucun alphabet de code, aucune
    // borne de pseudo : ils restent à Wayfinder, à `RoomCode` et au serveur.
    expect($source)->not->toMatch('/[\'"`]\//', 'une URL écrite en dur')
        ->and($source)->not->toContain('ROOM_CODE')
        ->and($source)->not->toContain(RoomCode::ALPHABET)
        ->and($source)->not->toMatch('/\b(?:maxLength|minLength|pattern)=/');

    // La cible de l'envoi : un code bien formé mais inconnu rend la page
    // `error` 404, en visite Inertia, jamais une page blanche.
    $unknown = 'ABCDEF';

    expect(RoomCode::isWellFormed($unknown))->toBeTrue()
        ->and(Room::query()->where('room_code', $unknown)->exists())->toBeFalse();

    app()->forgetInstance(TranslationDomains::class);

    $response = $this->withHeaders([
        Header::INERTIA => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ])->post(route('room.join', ['room' => $unknown]), ['nickname' => 'Alice'])
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

    // Les dictionnaires : les huit clés de l'accueil et rien d'autre sous
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
