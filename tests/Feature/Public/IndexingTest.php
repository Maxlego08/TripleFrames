<?php

use App\Http\Middleware\RobotsDirectives;
use App\Models\Room;
use App\Models\User;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Indexation intégrale et Referrer-Policy — spec 90 § 5, lot L90-2
|--------------------------------------------------------------------------
|
| Au jalon 1, le site est intégralement `noindex` (D30 du 23/09) : il est
| déployé sur le domaine définitif dès la première partie, donc atteignable
| par un robot. La levée sélective est une variable d'environnement de
| PRODUCTION (`SITE_INDEXABLE`, lue par `config('app.indexable')`), jamais une
| valeur du dépôt.
|
| Partage de preuve (R-04) : ce fichier prouve le comportement des en-têtes
| dans les deux états de la variable, le tableau route par route et le
| drapeau `RobotsDirectives::ROUTE_FLAG` ; la lecture de `SITE_INDEXABLE` et
| le contenu de `robots.txt` sont prouvés par `Deploy/SiteIndexingTest` (100,
| L100-4), jamais ici. Les tests de l'état levé posent la clé par
| `config(['app.indexable' => true])`.
|
| Les routes de salon, de jeu, du solo et de « signaler un contenu »
| arrivent avec leurs lots (50, 60, L90-4). Les requêtes ci-dessous visent
| leurs URL réelles dès aujourd'hui : elles répondent 404 tant que la route
| manque, et exerceront la vraie route dès qu'elle existera, sans que ce
| fichier change. La preuve ne dépend jamais du statut rendu.
|
*/

/**
 * Pose l'état de la variable d'indexation pour les requêtes suivantes.
 */
function indexingSet(mixed $value): void
{
    config(['app.indexable' => $value]);
}

/**
 * Affirme qu'une réponse porte `X-Robots-Tag: noindex, nofollow`, une fois.
 */
function indexingAssertNoindex(TestResponse $response, string $label): void
{
    expect($response->headers->all('x-robots-tag'))->toBe(
        [RobotsDirectives::NOINDEX],
        "{$label} (statut {$response->getStatusCode()}) doit porter X-Robots-Tag: noindex, nofollow",
    );
}

/**
 * Affirme qu'une réponse porte `Referrer-Policy: strict-origin-when-cross-origin`, une fois.
 */
function indexingAssertReferrerPolicy(TestResponse $response, string $label): void
{
    expect($response->headers->all('referrer-policy'))->toBe(
        [RobotsDirectives::REFERRER_POLICY],
        "{$label} (statut {$response->getStatusCode()}) doit porter Referrer-Policy: strict-origin-when-cross-origin",
    );
}

/**
 * Remplace le mode maintenance par un double toujours actif, sans jamais
 * écrire `storage/framework/down` : le pilote `file` du harnais mettrait en
 * maintenance le serveur de développement qui tourne sur le même dépôt.
 */
function indexingFakeMaintenance(): void
{
    app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return true;
        }

        /** @return array<string, mixed> */
        public function data(): array
        {
            return ['status' => 503];
        }
    });
}

/**
 * Routes enregistrées qui portent le drapeau d'indexation, quelle qu'en soit
 * la valeur, indexées par leur nom (ou leur URI quand elles n'en ont pas).
 *
 * @return array<string, RoutingRoute>
 */
function indexingFlaggedRoutes(): array
{
    $flagged = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (array_key_exists(RobotsDirectives::ROUTE_FLAG, $route->defaults)) {
            $flagged[$route->getName() ?? '/'.ltrim($route->uri(), '/')] = $route;
        }
    }

    ksort($flagged);

    return $flagged;
}

/**
 * Index juste après l'accolade fermante qui équilibre celle de `$index`,
 * chaînes et gabarits JavaScript sautés.
 */
function indexingSkipBraces(string $source, int $index): int
{
    $depth = 0;
    $length = strlen($source);

    for (; $index < $length; $index++) {
        $char = $source[$index];

        if ($char === '"' || $char === "'") {
            $index = indexingSkipString($source, $index);

            continue;
        }

        if ($char === '`') {
            $index = indexingSkipTemplate($source, $index);

            continue;
        }

        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;

            if ($depth === 0) {
                return $index + 1;
            }
        }
    }

    return $length;
}

/**
 * Index du guillemet qui ferme la chaîne ouverte à `$index`.
 */
function indexingSkipString(string $source, int $index): int
{
    $quote = $source[$index];
    $length = strlen($source);

    for ($index++; $index < $length; $index++) {
        if ($source[$index] === '\\') {
            $index++;

            continue;
        }

        if ($source[$index] === $quote) {
            return $index;
        }
    }

    return $length;
}

/**
 * Index de l'accent grave qui ferme le gabarit ouvert à `$index`, ses
 * interpolations `${…}` comprises.
 */
function indexingSkipTemplate(string $source, int $index): int
{
    $length = strlen($source);

    for ($index++; $index < $length; $index++) {
        $char = $source[$index];

        if ($char === '\\') {
            $index++;

            continue;
        }

        if ($char === '`') {
            return $index;
        }

        if ($char === '$' && ($source[$index + 1] ?? '') === '{') {
            $index = indexingSkipBraces($source, $index + 1) - 1;
        }
    }

    return $length;
}

/**
 * Vrai si l'expression est un `t()` à clé littérale, sans aucun paramètre :
 * la seule forme admise pour un titre de page de salon (spec 90 § 5.5).
 */
function indexingIsBareTranslation(string $expression): bool
{
    return preg_match('/^t\(\s*([\'"])[A-Za-z0-9_.-]+\1\s*\)$/', trim($expression)) === 1;
}

/**
 * Titres de page d'une source TSX qui ne sont pas un `t('clé')` sans
 * paramètre : attribut `title` de `<Head>`, décomposition `{...x}` sur
 * `<Head>` (elle pourrait y glisser un titre), élément `<title>`.
 *
 * @return list<string>
 */
function indexingTitleViolations(string $source, string $label): array
{
    $violations = [];
    $length = strlen($source);
    $offset = 0;

    $describe = static function (int $at, string $snippet) use ($source, $label): string {
        $line = substr_count($source, "\n", 0, $at) + 1;

        return sprintf('%s:%d  %s', $label, $line, preg_replace('/\s+/', ' ', trim($snippet)));
    };

    while (preg_match('/<Head\b/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $index = $match[0][1] + strlen('<Head');

        // Attributs de la balise ouvrante, jusqu'à `>` ou `/>` hors expression.
        while ($index < $length) {
            while ($index < $length && ctype_space($source[$index])) {
                $index++;
            }

            if ($index >= $length || $source[$index] === '>' || $source[$index] === '/') {
                break;
            }

            if ($source[$index] === '{') {
                $end = indexingSkipBraces($source, $index);
                $violations[] = $describe($index, substr($source, $index, $end - $index));
                $index = $end;

                continue;
            }

            if (preg_match('/\G[A-Za-z_][\w:.-]*/', $source, $name, 0, $index) !== 1) {
                $index++;

                continue;
            }

            $attributeStart = $index;
            $index += strlen($name[0]);

            while ($index < $length && ctype_space($source[$index])) {
                $index++;
            }

            if (($source[$index] ?? '') !== '=') {
                continue;
            }

            $index++;

            while ($index < $length && ctype_space($source[$index])) {
                $index++;
            }

            if (($source[$index] ?? '') === '{') {
                $end = indexingSkipBraces($source, $index);
                $value = substr($source, $index + 1, max(0, $end - $index - 2));
                $bare = indexingIsBareTranslation($value);
            } else {
                $end = indexingSkipString($source, $index) + 1;
                $bare = false;
            }

            if ($name[0] === 'title' && ! $bare) {
                $violations[] = $describe($attributeStart, substr($source, $attributeStart, $end - $attributeStart));
            }

            $index = $end;
        }

        $offset = max($index, $match[0][1] + 1);
    }

    preg_match_all('/<title\b[^>]*>(.*?)<\/title>/s', $source, $titles, PREG_OFFSET_CAPTURE);

    foreach ($titles[1] as $position => [$content]) {
        $inner = trim($content);

        if (! (str_starts_with($inner, '{') && str_ends_with($inner, '}') && indexingIsBareTranslation(substr($inner, 1, -1)))) {
            $violations[] = $describe($titles[0][$position][1], $titles[0][$position][0]);
        }
    }

    return $violations;
}

/**
 * Sources des pages de salon : `pages/game` et `pages/room`, récursivement.
 *
 * @return list<string> chemins relatifs, séparateur `/`
 */
function indexingRoomPageFiles(): array
{
    $files = [];

    foreach (['game', 'room'] as $directory) {
        $root = resource_path('js/pages/'.$directory);

        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                $files[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));
            }
        }
    }

    sort($files);

    return $files;
}

it("émet X-Robots-Tag noindex, nofollow sur toute réponse tant que l'indexation n'est pas levée", function () {
    $this->withoutVite();

    // `false` est l'état du jalon 1 ; les trois autres valeurs prouvent la
    // comparaison STRICTE : une clé absente, ou d'un autre type qu'un vrai
    // booléen, ne lève jamais rien.
    foreach ([false, null, 'true', 1] as $state) {
        indexingSet($state);
        $label = 'app.indexable = '.var_export($state, true).' :';

        // L'accueil porte le drapeau, et reste noindex tant que la variable l'est.
        indexingAssertNoindex($this->get(route('home'))->assertOk(), "{$label} GET /");
        indexingAssertNoindex($this->call('HEAD', route('home'))->assertOk(), "{$label} HEAD /");

        indexingAssertNoindex($this->get(route('login'))->assertOk(), "{$label} GET /login");
        indexingAssertNoindex($this->get(route('dashboard'))->assertRedirect(), "{$label} GET /dashboard (invité)");
        indexingAssertNoindex(
            $this->from(route('home'))->post(route('locale.update'), ['locale' => 'fr'])->assertRedirect(),
            "{$label} POST /locale",
        );
        indexingAssertNoindex($this->get('/up')->assertOk(), "{$label} GET /up");
        indexingAssertNoindex($this->getJson('/up'), "{$label} GET /up en JSON");
    }
});

it('émet noindex sur une page d\'erreur et sur une URL inconnue', function () {
    $this->withoutVite();

    // Une erreur sur une route INDEXABLE reste noindex : seul un 200 lève.
    Route::get('/__indexing/indexable-failure', fn () => abort(500))
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);
    Route::get('/__indexing/indexable-missing', fn () => abort(404))
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);

    $player = User::factory()->player()->create();

    foreach ([false, true] as $state) {
        indexingSet($state);
        $label = 'app.indexable = '.var_export($state, true).' :';

        // URL inconnue : aucune route, aucun middleware de groupe — seul un
        // middleware global la voit.
        indexingAssertNoindex($this->get('/une-url-qui-n-existe-pas')->assertNotFound(), "{$label} URL inconnue");
        indexingAssertNoindex($this->getJson('/une-url-qui-n-existe-pas')->assertNotFound(), "{$label} URL inconnue en JSON");

        // Méthode refusée sur l'accueil, pourtant marqué indexable.
        indexingAssertNoindex($this->post(route('home'))->assertMethodNotAllowed(), "{$label} POST / (405)");

        // Le back-office refusé à un joueur : `role:curator` lève avant tout.
        indexingAssertNoindex($this->actingAs($player)->get(route('admin.dashboard'))->assertForbidden(), "{$label} /admin (joueur, 403)");

        indexingAssertNoindex($this->get('/__indexing/indexable-failure')->assertServerError(), "{$label} route indexable en 500");
        indexingAssertNoindex($this->get('/__indexing/indexable-missing')->assertNotFound(), "{$label} route indexable en 404");
    }

    // Mode maintenance : la 503 naît dans un middleware GLOBAL du framework,
    // avant la pile de route — le middleware d'en-têtes la voit parce qu'il
    // est en tête de la pile globale.
    indexingSet(true);
    indexingFakeMaintenance();

    indexingAssertNoindex($this->get(route('home'))->assertServiceUnavailable(), 'maintenance : GET / (503)');
});

it('ne lève le noindex que sur une route marquée indexable quand l\'indexation est levée', function () {
    $this->withoutVite();

    // Une réponse 200 sur une route SANS drapeau.
    Route::get('/__indexing/unflagged', fn () => response('ok'));
    // Drapeau posé, mais méthode d'écriture.
    Route::post('/__indexing/flagged-post', fn () => response('ok'))
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);
    // Drapeau posé, mais statut autre que 200.
    Route::get('/__indexing/flagged-redirect', fn () => redirect('/'))
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);
    Route::get('/__indexing/flagged-empty', fn () => response()->noContent())
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);
    // Drapeau d'un autre type qu'un vrai booléen : il ne compte pas.
    Route::get('/__indexing/flagged-string', fn () => response('ok'))
        ->defaults(RobotsDirectives::ROUTE_FLAG, 'true');
    Route::get('/__indexing/flagged-int', fn () => response('ok'))
        ->defaults(RobotsDirectives::ROUTE_FLAG, 1);

    indexingSet(true);

    // Les quatre conditions réunies : aucun X-Robots-Tag, en GET comme en HEAD.
    $this->get(route('home'))->assertOk()->assertHeaderMissing('X-Robots-Tag');
    $this->call('HEAD', route('home'))->assertOk()->assertHeaderMissing('X-Robots-Tag');

    // Une seule condition manque : noindex.
    indexingAssertNoindex($this->get(route('login'))->assertOk(), 'GET /login, sans drapeau');
    indexingAssertNoindex($this->get('/up')->assertOk(), 'GET /up, sans drapeau');
    indexingAssertNoindex($this->get('/__indexing/unflagged')->assertOk(), '200 sans drapeau');
    indexingAssertNoindex($this->post('/__indexing/flagged-post')->assertOk(), 'POST sur une route marquée');
    indexingAssertNoindex($this->get('/__indexing/flagged-redirect')->assertRedirect(), '302 sur une route marquée');
    indexingAssertNoindex($this->get('/__indexing/flagged-empty')->assertNoContent(), '204 sur une route marquée');
    indexingAssertNoindex($this->get('/__indexing/flagged-string')->assertOk(), "drapeau 'true' (chaîne)");
    indexingAssertNoindex($this->get('/__indexing/flagged-int')->assertOk(), 'drapeau 1 (entier)');

    // La variable retombée, l'accueil redevient noindex, sans redéploiement.
    indexingSet(false);

    indexingAssertNoindex($this->get(route('home'))->assertOk(), 'GET / après retour à false');
});

it("ne marque indexables que l'accueil et les trois pages légales", function () {
    // Nom du drapeau figé par la feuille de contrats (annexe « indexation » :
    // `->defaults('indexable', true)`).
    expect(RobotsDirectives::ROUTE_FLAG)->toBe('indexable');

    $allowed = ['home', 'legal.notice', 'legal.terms', 'legal.privacy'];
    $flagged = indexingFlaggedRoutes();

    // Aucun autre porteur, pas même avec une valeur fausse : un drapeau posé
    // à `false` sur un lien de salon serait un piège pour la prochaine
    // relecture. `takedown.create` ne le porte jamais (noindex permanent).
    expect(array_keys($flagged))->each->toBeIn($allowed);

    foreach ($flagged as $name => $route) {
        expect($route->defaults[RobotsDirectives::ROUTE_FLAG])->toBeTrue("{$name} porte le drapeau avec une autre valeur que true")
            ->and($route->methods())->toContain('GET');
    }

    // L'accueil le porte dès le jalon 1, sans effet tant que la variable est
    // fausse ; chaque page légale le porte dès que sa route existe (L90-4).
    expect($flagged)->toHaveKey('home');

    foreach ($allowed as $name) {
        if (Route::has($name)) {
            expect(array_key_exists($name, $flagged))
                ->toBeTrue("{$name} existe mais ne porte pas RobotsDirectives::ROUTE_FLAG");
        }
    }

    expect(array_key_exists('takedown.create', $flagged))
        ->toBeFalse('signaler un contenu est noindex en permanence');
});

it('garde noindex le lien d\'un salon, les pages de jeu, signaler un contenu et le back-office quand l\'indexation est levée', function () {
    $this->withoutVite();

    indexingSet(true);

    // Aucune route de ces familles ne porte le drapeau, quel que soit le lot
    // qui l'a déclarée : salon, jeu, solo, retrait, back-office, images.
    $families = ['room.', 'solo.', 'game.', 'takedown.', 'admin.', 'frame.', 'clock.', 'ops.'];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName() ?? '';
        $uri = trim($route->uri(), '/');
        $first = explode('/', $uri, 2)[0];

        if (Str::startsWith($name, $families)
            || str_contains($uri, '{room}')
            || in_array($first, ['r', 'solo', 'admin', 'report-content', 'f', 'ops'], true)) {
            expect(array_key_exists(RobotsDirectives::ROUTE_FLAG, $route->defaults))
                ->toBeFalse("/{$uri} ({$name}) ne doit jamais porter RobotsDirectives::ROUTE_FLAG");
        }
    }

    // Les URL réelles, variable levée : noindex quel que soit le statut.
    $room = Room::factory()->create();
    $code = $room->room_code;

    foreach ([
        "/r/{$code}" => 'lien du salon (lobby, game/lobby)',
        "/r/{$code}/join" => "page d'entrée du salon",
        '/r/new' => 'création de salon',
        '/solo/new' => 'entrée du solo',
        '/solo' => 'partie solo (game/solo)',
        '/report-content' => 'signaler un contenu',
    ] as $url => $label) {
        indexingAssertNoindex($this->get($url), "{$label} {$url}");
        indexingAssertReferrerPolicy($this->get($url), "{$label} {$url}");
    }

    // Back-office : noindex inconditionnel, variable levée comprise.
    $curator = User::factory()->curator()->withTwoFactor()->create();

    foreach (['admin.dashboard', 'admin.catalog.index', 'admin.import.index'] as $name) {
        indexingAssertNoindex($this->actingAs($curator)->get(route($name)), "back-office {$name}");
    }
});

it("n'écrase jamais l'X-Robots-Tag posé par une réponse d'image", function () {
    // Réponse d'image telle que la pose `FrameImageResponse` (C8, C9, E10-59).
    Route::get('/__indexing/image/{serveToken}', fn (string $serveToken) => response('bytes', 200, [
        'Content-Type' => 'image/webp',
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Content-Type-Options' => 'nosniff',
    ]));
    // Sa 404 à corps vide garde les mêmes en-têtes.
    Route::get('/__indexing/image-missing', fn () => response('', 404, [
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
    ]));
    // Valeur distincte de celle du middleware, pour voir un écrasement ; et
    // sur une route MARQUÉE, pour voir un retrait une fois la variable levée.
    Route::get('/__indexing/own-directive', fn () => response('ok', 200, ['X-Robots-Tag' => 'noindex']))
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);

    foreach ([false, true] as $state) {
        indexingSet($state);
        $label = 'app.indexable = '.var_export($state, true).' :';

        $image = $this->get('/__indexing/image/'.bin2hex(random_bytes(16)))->assertOk();

        expect($image->headers->all('x-robots-tag'))->toBe(['noindex, nofollow'], "{$label} image : un seul X-Robots-Tag");

        $missing = $this->get('/__indexing/image-missing')->assertNotFound();

        expect($missing->headers->all('x-robots-tag'))->toBe(['noindex, nofollow'], "{$label} 404 d'image : un seul X-Robots-Tag");

        $own = $this->get('/__indexing/own-directive')->assertOk();

        expect($own->headers->all('x-robots-tag'))->toBe(['noindex'], "{$label} la directive de la réponse fait foi");
    }
});

it('pose Referrer-Policy strict-origin-when-cross-origin sur toute réponse', function () {
    $this->withoutVite();

    expect(RobotsDirectives::REFERRER_POLICY)->toBe('strict-origin-when-cross-origin');

    Route::get('/__indexing/image/{serveToken}', fn (string $serveToken) => response('bytes', 200, [
        'Content-Type' => 'image/webp',
        'X-Robots-Tag' => 'noindex, nofollow',
    ]));
    // Une réponse qui déclare déjà sa politique la garde.
    Route::get('/__indexing/own-policy', fn () => response('ok', 200, ['Referrer-Policy' => 'no-referrer']));

    foreach ([false, true] as $state) {
        indexingSet($state);
        $label = 'app.indexable = '.var_export($state, true).' :';

        // Indexable ou non, la politique ne dépend jamais de la variable.
        indexingAssertReferrerPolicy($this->get(route('home'))->assertOk(), "{$label} GET /");
        indexingAssertReferrerPolicy($this->call('HEAD', route('home'))->assertOk(), "{$label} HEAD /");
        indexingAssertReferrerPolicy($this->get(route('login'))->assertOk(), "{$label} GET /login");
        indexingAssertReferrerPolicy(
            $this->from(route('home'))->post(route('locale.update'), ['locale' => 'en'])->assertRedirect(),
            "{$label} POST /locale",
        );
        indexingAssertReferrerPolicy($this->get('/une-url-qui-n-existe-pas')->assertNotFound(), "{$label} URL inconnue");
        indexingAssertReferrerPolicy($this->get('/up')->assertOk(), "{$label} GET /up");
        indexingAssertReferrerPolicy($this->get('/__indexing/image/'.bin2hex(random_bytes(16)))->assertOk(), "{$label} image");

        expect($this->get('/__indexing/own-policy')->assertOk()->headers->all('referrer-policy'))
            ->toBe(['no-referrer'], "{$label} la politique de la réponse fait foi");
    }

    indexingFakeMaintenance();

    indexingAssertReferrerPolicy($this->get(route('home'))->assertServiceUnavailable(), 'maintenance : GET / (503)');
});

it("n'écrit jamais un room_code ni un paramètre dans le titre d'une page de salon", function () {
    // Le détecteur d'abord : il doit laisser passer la seule forme admise —
    // un `t()` à clé littérale, sans paramètre (spec 90 § 5.5) — et attraper
    // toutes les autres. Un balayage qui ne voit rien ne prouve rien.
    $admitted = [
        "<Head title={t('game.lobby.title')} />",
        '<Head title={t("room.join.title")}></Head>',
        "<Head\n    title={t('game.solo.title')}\n/>",
        "<Head><title>{t('game.lobby.title')}</title></Head>",
        '<Heading title={room.room_code} />',
        '<Head />',
    ];

    foreach ($admitted as $sample) {
        expect(indexingTitleViolations($sample, 'exemple'))->toBe([], "faux positif sur : {$sample}");
    }

    $refused = [
        "<Head title={t('room.lobby.title', { code: room.room_code })} />",
        '<Head title={room.room_code} />',
        '<Head title={`${t(\'room.lobby.title\')} ${code}`} />',
        "<Head title={t('room.lobby.title') + ' ' + code} />",
        '<Head title={title} />',
        '<Head title="Salon" />',
        '<Head {...head} />',
        '<Head><title>{room.room_code}</title></Head>',
        "<Head title={cond ? t('room.lobby.title') : code} />",
    ];

    foreach ($refused as $sample) {
        expect(indexingTitleViolations($sample, 'exemple'))->toHaveCount(1, "non détecté : {$sample}");
    }

    // Puis les pages réelles de salon : `pages/game` et `pages/room`.
    $violations = [];

    foreach (indexingRoomPageFiles() as $file) {
        array_push($violations, ...indexingTitleViolations((string) file_get_contents(base_path($file)), $file));
    }

    expect($violations)->toBe([], "Titre de page de salon hors de la forme t('clé') sans paramètre :\n".implode("\n", $violations));
});
