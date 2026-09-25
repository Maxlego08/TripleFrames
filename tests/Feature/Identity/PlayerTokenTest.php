<?php

use App\Avatars\AvatarPresetCatalog;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Models\Player;
use App\Models\Room;
use App\Models\RoundPlayer;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\I18n\LocaleCookie;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Identity\PlayerTokenManager;
use Carbon\CarbonImmutable;
use Database\Factories\PlayerFactory;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/*
|--------------------------------------------------------------------------
| Le `player_token` — spec 40 § 3 et § 4, contrat C4 (L40-1, L40-2)
|--------------------------------------------------------------------------
|
| Cookie HttpOnly chiffré, frappé paresseusement, glissant sur 30 jours ; en
| base, le SHA-256 du `tid` et rien d'autre.
|
| **Tant que `50` n'a pas livré `room.join`**, les tests qui exigent un geste
| de siège passent par une route déclarée dans ce fichier (lettre de L40-1),
| qui suit l'ordre du § 2.1, étape 4 : (1) `current()`, qui ne frappe rien ;
| (2) `seatIn()` — un siège existant est repris sans revalidation, et
| `ensure()` fait glisser le cookie ; (3) `wasKickedFrom()` refuse avant tout
| comptage ; (4) capacité et unicité du pseudo sous le verrou du salon ;
| (5) `ensure()` seulement alors, écriture du siège, puis re-signature avec
| l'avatar choisi. Le pliage du pseudo y emprunte le normaliseur provisoire de
| la fabrique (`NicknameNormalizer` est de L40-3) : la règle de pseudo n'est
| pas l'objet de ce fichier.
|
*/

beforeEach(function () {
    playerTokenRoutes();

    // Les requêtes JSON du client de test n'emportent les cookies qu'à cette
    // condition — un navigateur, lui, les envoie toujours.
    $this->withCredentials();

    // En production, un processus PHP-FPM sert une requête puis meurt : la
    // file du `CookieJar` — singleton que le framework ne vide jamais — ne
    // survit pas à sa requête. Ici, toutes les requêtes d'un test partagent
    // l'application : sans ce vidage, le `Set-Cookie` d'un geste de siège
    // repartirait sur la requête suivante et ferait mentir chaque assertion
    // d'absence.
    Event::listen(RequestHandled::class, static fn () => Cookie::flushQueuedCookies());
});

/** Préfixe des routes de test de ce fichier. */
function playerTokenPrefix(): string
{
    return '/_test/player-token';
}

function playerTokenRoutes(): void
{
    // Geste de siège — § 2.1, étape 4.
    Route::middleware('web')->post(playerTokenPrefix().'/rooms/{room}/seat', function (Request $request, Room $room, PlayerTokenManager $tokens): JsonResponse {
        // (1) Lecture seule.
        $tokens->current($request);

        // (2) Reprise : aucune revalidation, aucune écriture du siège ; le
        // cookie glisse et sa langue se réaligne.
        $seat = $tokens->seatIn($request, $room);

        if ($seat instanceof Player) {
            $tokens->ensure($request);

            return response()->json(['outcome' => 'resumed', 'seat' => $seat->public_id]);
        }

        if ($room->archived_at !== null) {
            return response()->json(['refused' => 'archived'], 410);
        }

        $validated = $request->validate([
            'nickname' => ['required', 'string', 'between:2,20'],
            'avatar' => ['required', 'string', Rule::in(AvatarPresetCatalog::keys())],
        ]);

        // (3) Expulsé : refus avant tout comptage.
        if ($tokens->wasKickedFrom($request, $room)) {
            return response()->json(['refused' => 'room.join.kicked'], 403);
        }

        return DB::transaction(function () use ($request, $room, $tokens, $validated): JsonResponse {
            // (4) Capacité et unicité du pseudo sous le verrou du salon.
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            if (Player::query()->whereBelongsTo($locked)->holdingSeat()->count() >= $locked->capacity) {
                return response()->json(['refused' => 'room.join.full'], 409);
            }

            $nickname = (string) $validated['nickname'];
            $normalized = PlayerFactory::normalizeNickname($nickname);

            if (Player::query()->whereBelongsTo($locked)->where('nickname_normalized', $normalized)->exists()) {
                return response()->json(['refused' => 'validation.nickname.taken'], 422);
            }

            // (5) Seulement alors : la frappe, l'écriture, la re-signature.
            $token = $tokens->ensure($request);

            $seat = new Player;
            $seat->forceFill([
                'public_id' => playerTokenPublicId(),
                'room_id' => $locked->id,
                'nickname' => $nickname,
                'nickname_normalized' => $normalized,
                'player_token_hash' => $token->hash(),
                'active_seat_token' => (string) Str::ulid(),
                'locale' => App::getLocale(),
                'avatar_kind' => AvatarKind::Preset,
                'avatar_preset' => (string) $validated['avatar'],
                'joined_at' => now(),
                'last_seen_at' => now(),
            ])->save();

            $tokens->resign($request, $token->withAvatar((string) $validated['avatar']));

            return response()->json(['outcome' => 'seated', 'seat' => $seat->public_id], 201);
        });
    });

    // Ce que voit un consommateur par `current()` : présence et revendications.
    Route::middleware('web')->get(playerTokenPrefix().'/probe', function (Request $request, PlayerTokenManager $tokens): JsonResponse {
        $token = $tokens->current($request);

        return response()->json([
            'present' => $token instanceof PlayerToken,
            'hash' => $token?->hash(),
            'locale' => $token?->locale?->value,
            'avatar' => $token?->avatar,
        ]);
    });

    // Plusieurs `ensure()` et `current()` dans une seule requête.
    Route::middleware('web')->post(playerTokenPrefix().'/ensure-many', function (Request $request, PlayerTokenManager $tokens): JsonResponse {
        $first = $tokens->ensure($request);
        $tokens->current($request);
        $second = $tokens->ensure($request);
        $third = $tokens->ensure($request);
        $current = $tokens->current($request);

        return response()->json([
            'same' => $current instanceof PlayerToken
                && $first->sameIdentityAs($second)
                && $second->sameIdentityAs($third)
                && $third->sameIdentityAs($current),
            'hash' => $first->hash(),
        ]);
    });

    // Le même enchaînement, reçu comme un vrai geste de siège : par une
    // `FormRequest`, que le framework bâtit en COPIE de la requête de base
    // (`FormRequest::createFrom`, attributs copiés par valeur). Le middleware
    // de tête lit d'abord le jeton sur la requête de base, comme le fera
    // `SetLocale` (niveau 3, L40-2) : son mémo, nul pour un nouveau visiteur,
    // part dans la copie. La garde `player` de `60` lira, elle, `request()`.
    Route::aliasMiddleware('player-token.read-first', static function (Request $request, Closure $next): mixed {
        app(PlayerTokenManager::class)->current($request);

        return $next($request);
    });

    Route::middleware(['web', 'player-token.read-first'])->post(playerTokenPrefix().'/ensure-form', function (FormRequest $form, PlayerTokenManager $tokens): JsonResponse {
        $first = $tokens->ensure($form);
        $base = $tokens->current(request());
        $second = $tokens->ensure(request());
        $copy = $tokens->current($form);

        return response()->json([
            'copy' => $form !== request(),
            'same' => $base instanceof PlayerToken
                && $copy instanceof PlayerToken
                && $first->sameIdentityAs($base)
                && $base->sameIdentityAs($second)
                && $second->sameIdentityAs($copy),
            'hash' => $first->hash(),
        ]);
    });

    // Re-signature d'avatar hors prise de siège, comme le fera un changement
    // d'avatar de `50` (I4.5). La re-signature de langue, elle, passe par la
    // vraie route `locale.update` depuis L40-2.
    Route::middleware('web')->post(playerTokenPrefix().'/resign', function (Request $request, PlayerTokenManager $tokens): JsonResponse {
        $token = $tokens->current($request) ?? abort(409);

        $resigned = $tokens->resign($request, $token->withAvatar($request->string('avatar')->toString()));

        return response()->json(['hash' => $resigned->hash()]);
    });

    // Présélection de l'écran d'entrée (§ 2.1, étape 2).
    Route::middleware('web')->get(playerTokenPrefix().'/rooms/{room}/suggest', function (Request $request, Room $room, PlayerTokenManager $tokens): JsonResponse {
        /** @var list<string> $taken */
        $taken = Player::query()
            ->whereBelongsTo($room)
            ->holdingSeat()
            ->whereNotNull('avatar_preset')
            ->pluck('avatar_preset')
            ->values()
            ->all();

        return response()->json([
            'suggested' => AvatarPresetCatalog::suggest($tokens->current($request)?->avatar, $taken),
        ]);
    });
}

function playerTokenSeatUri(Room $room): string
{
    return playerTokenPrefix().'/rooms/'.$room->room_code.'/seat';
}

function playerTokenSuggestUri(Room $room): string
{
    return playerTokenPrefix().'/rooms/'.$room->room_code.'/suggest';
}

function playerTokenPublicId(): string
{
    $alphabet = PlayerFactory::PUBLIC_ID_ALPHABET;
    $id = '';

    for ($index = 0; $index < PlayerFactory::PUBLIC_ID_LENGTH; $index++) {
        $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $id;
}

/** Les `Set-Cookie` `player_token` d'une réponse. */
function playerTokenSetCookies(TestResponse $response): array
{
    return array_values(array_filter(
        $response->headers->getCookies(),
        static fn (SymfonyCookie $cookie): bool => $cookie->getName() === PlayerTokenCookie::NAME,
    ));
}

/** La valeur transportée du `Set-Cookie` — chiffrée, telle que le navigateur la renverra. */
function playerTokenRaw(TestResponse $response): string
{
    $cookie = $response->getCookie(PlayerTokenCookie::NAME, decrypt: false);

    expect($cookie)->not->toBeNull('La réponse ne pose aucun cookie player_token.');

    return (string) $cookie?->getValue();
}

/**
 * Les revendications du `Set-Cookie`, déchiffrées.
 *
 * @return array<string, mixed>
 */
function playerTokenClaims(TestResponse $response): array
{
    $cookie = $response->getCookie(PlayerTokenCookie::NAME);

    expect($cookie)->not->toBeNull('La réponse ne pose aucun cookie player_token.');

    $claims = json_decode((string) $cookie?->getValue(), true, 4, JSON_THROW_ON_ERROR);

    expect($claims)->toBeArray();

    return $claims;
}

/** Valeur chiffrée par l'application, préfixe lié au nom du cookie compris, comme `EncryptCookies`. */
function playerTokenEncrypt(string $plain, string $cookieName = PlayerTokenCookie::NAME): string
{
    /** @var Encrypter $encrypter */
    $encrypter = app('encrypter');

    return $encrypter->encrypt(CookieValuePrefix::create($cookieName, $encrypter->getKey()).$plain, false);
}

it('ne frappe aucun player_token sur une requête qui ne prend aucun siège', function () {
    $room = Room::factory()->create();

    $responses = [
        'accueil' => $this->get(route('home')),
        'lecture du jeton' => $this->getJson(playerTokenPrefix().'/probe'),
        'présélection' => $this->getJson(playerTokenSuggestUri($room)),
        'changement de langue' => $this->from('/')->post(route('locale.update'), ['locale' => Locale::French->value]),
        'écran de connexion' => $this->get(route('login')),
    ];

    foreach ($responses as $gesture => $response) {
        expect($response->status())->toBeLessThan(400, "[{$gesture}] a échoué.");
        expect(playerTokenSetCookies($response))->toBe([], "[{$gesture}] a posé un player_token.");
    }

    expect($responses['lecture du jeton']->json('present'))->toBeFalse()
        ->and(Player::query()->count())->toBe(0);

    // Un jeton existant est LU, jamais reposé : `current()` n'émet aucun
    // Set-Cookie, ce qui permettra à `/f/{serveToken}` de le lire sans rien écrire.
    $token = PlayerToken::mint(Locale::English, 'preset-07');
    $this->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR));

    $read = $this->getJson(playerTokenPrefix().'/probe')
        ->assertOk()
        ->assertJson(['present' => true, 'hash' => $token->hash()]);
    $suggested = $this->getJson(playerTokenSuggestUri($room))
        ->assertOk()
        ->assertJson(['suggested' => 'preset-07']);

    expect(playerTokenSetCookies($read))->toBe([])
        ->and(playerTokenSetCookies($suggested))->toBe([])
        ->and(playerTokenSetCookies($this->get(route('home'))))->toBe([]);
});

it("ne frappe qu'un player_token par requête, quel que soit le nombre d'appels à ensure", function () {
    $response = $this->postJson(playerTokenPrefix().'/ensure-many')->assertOk();

    expect(playerTokenSetCookies($response))->toHaveCount(1)
        ->and($response->json('same'))->toBeTrue()
        ->and(hash('sha256', (string) playerTokenClaims($response)['tid']))->toBe($response->json('hash'));

    // Par une `FormRequest` : la copie et la requête de base partagent le même
    // mémo, sinon un `ensure()` sur l'une frapperait un second tid, dont le
    // `Set-Cookie` orphelinerait le siège écrit sous le premier.
    $form = $this->postJson(playerTokenPrefix().'/ensure-form')->assertOk();

    expect($form->json('copy'))->toBeTrue()
        ->and(playerTokenSetCookies($form))->toHaveCount(1)
        ->and($form->json('same'))->toBeTrue()
        ->and(hash('sha256', (string) playerTokenClaims($form)['tid']))->toBe($form->json('hash'));

    // Un geste de siège appelle `ensure()` puis `resign()` : un seul Set-Cookie,
    // celui de la re-signature, et un seul siège.
    $seat = $this->postJson(playerTokenSeatUri(Room::factory()->create()), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])
        ->assertCreated();

    expect(playerTokenSetCookies($seat))->toHaveCount(1)
        ->and(playerTokenClaims($seat)['avatar'])->toBe('preset-07')
        ->and(Player::query()->count())->toBe(1);
});

it('ne stocke que le SHA-256 du tid dans player_token_hash', function () {
    $room = Room::factory()->create();

    $first = $this->postJson(playerTokenSeatUri($room), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])->assertCreated();
    $raw = playerTokenRaw($first);
    $tid = (string) playerTokenClaims($first)['tid'];
    $seat = Player::query()->sole();

    expect($seat->player_token_hash)->toBe(hash('sha256', $tid))
        ->and($seat->player_token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($seat->player_token_hash)->not->toBe($tid)
        ->and($seat->player_token_hash)->not->toBe(hash('sha256', $raw));

    // Aucune colonne du siège ne porte le tid ni la valeur du cookie.
    foreach ($seat->getAttributes() as $column => $value) {
        expect(is_scalar($value) ? (string) $value : '')
            ->not->toContain($tid, "[player.{$column}] porte le tid.")
            ->not->toContain($raw, "[player.{$column}] porte la valeur du cookie.");
    }

    // La re-signature change la valeur chiffrée (vecteur d'initialisation neuf),
    // jamais le hash : le siège se retrouve.
    $second = $this->withUnencryptedCookie(PlayerTokenCookie::NAME, $raw)
        ->postJson(playerTokenSeatUri($room))
        ->assertOk()
        ->assertJson(['outcome' => 'resumed', 'seat' => $seat->public_id]);

    expect(playerTokenRaw($second))->not->toBe($raw)
        ->and(Player::query()->sole()->player_token_hash)->toBe(hash('sha256', $tid));
});

it('écrit le cookie player_token chiffré, HttpOnly, SameSite=Lax, sur le chemin / pour 30 jours', function () {
    $this->freezeSecond();

    // Un domaine par défaut déclaré au `CookieJar` (celui de `SESSION_DOMAIN`)
    // ne s'étend jamais au jeton : hôte seul.
    Cookie::setDefaultPathAndDomain('/', '.sessions.example.test', false, 'lax');

    $response = $this->postJson(playerTokenSeatUri(Room::factory()->create()), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])
        ->assertCreated();

    $cookie = $response->getCookie(PlayerTokenCookie::NAME, decrypt: false);

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe(SymfonyCookie::SAMESITE_LAX)
        ->and($cookie?->getPath())->toBe('/')
        ->and($cookie?->getDomain())->toBeNull()
        ->and($cookie?->isSecure())->toBeFalse()
        ->and($cookie?->isRaw())->toBeFalse()
        ->and($cookie?->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp())
        ->and(PlayerTokenCookie::LIFETIME)->toBe(30 * 24 * 60);

    // Chiffré : la valeur transportée ne livre ni le tid ni la charge en clair,
    // et seul le chiffreur de l'application, préfixe lié au nom compris, la relit.
    $raw = (string) $cookie?->getValue();
    $claims = playerTokenClaims($response);

    expect($raw)->not->toContain((string) $claims['tid'])
        ->and(base64_decode($raw, true) ?: '')->not->toContain((string) $claims['tid'])
        ->and(json_decode($raw, true))->toBeNull()
        ->and(array_keys($claims))->toBe(['v', 'tid', 'locale', 'avatar'])
        ->and($claims['v'])->toBe(PlayerToken::VERSION);

    /** @var Encrypter $encrypter */
    $encrypter = app('encrypter');
    $decrypted = $encrypter->decrypt($raw, false);

    expect(CookieValuePrefix::validate(PlayerTokenCookie::NAME, $decrypted, $encrypter->getAllKeys()))
        ->toBe(json_encode($claims, JSON_THROW_ON_ERROR));

    // `Secure` en production, comme le cookie `locale`.
    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        expect(PlayerTokenCookie::make(PlayerToken::mint(Locale::English))->isSecure())->toBeTrue();
    } finally {
        app()['env'] = $previous;
    }
});

it("fait glisser l'expiration du cookie et réaligne la revendication de langue à chaque prise ou reprise de siège", function () {
    $this->freezeSecond();
    $roomA = Room::factory()->create();
    $roomB = Room::factory()->create();

    // Prise de siège en anglais.
    $first = $this->withUnencryptedCookie(LocaleCookie::NAME, Locale::English->value)
        ->postJson(playerTokenSeatUri($roomA), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])
        ->assertCreated();
    $claims = playerTokenClaims($first);

    expect($claims['locale'])->toBe('en')
        ->and($first->getCookie(PlayerTokenCookie::NAME, decrypt: false)?->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp());

    // Dix jours plus tard, reprise du même siège en français : 30 jours pleins
    // depuis la reprise, langue réalignée, même tid.
    $this->travel(10)->days();

    $second = $this->withUnencryptedCookie(PlayerTokenCookie::NAME, playerTokenRaw($first))
        ->withUnencryptedCookie(LocaleCookie::NAME, Locale::French->value)
        ->postJson(playerTokenSeatUri($roomA))
        ->assertOk()
        ->assertJson(['outcome' => 'resumed']);
    $resumed = playerTokenClaims($second);

    expect($resumed['locale'])->toBe('fr')
        ->and($resumed['tid'])->toBe($claims['tid'])
        ->and($resumed['avatar'])->toBe('preset-07')
        ->and($second->getCookie(PlayerTokenCookie::NAME, decrypt: false)?->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp());

    // Cinq jours plus tard, nouvelle prise de siège dans un autre salon, de
    // nouveau en anglais.
    $this->travel(5)->days();

    $third = $this->withUnencryptedCookie(PlayerTokenCookie::NAME, playerTokenRaw($second))
        ->withUnencryptedCookie(LocaleCookie::NAME, Locale::English->value)
        ->postJson(playerTokenSeatUri($roomB), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])
        ->assertCreated();
    $seated = playerTokenClaims($third);

    expect($seated['locale'])->toBe('en')
        ->and($seated['tid'])->toBe($claims['tid'])
        ->and($third->getCookie(PlayerTokenCookie::NAME, decrypt: false)?->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp())
        ->and(Player::query()->whereBelongsTo($roomB)->sole()->locale)->toBe(Locale::English);
});

/**
 * Valeurs de cookie que le serveur doit tenir pour absentes, telles qu'un
 * navigateur les renverrait (valeur transportée, non rechiffrée par le client
 * de test). Chaque fabrique reçoit la charge valide d'un jeton existant.
 *
 * @return array<string, array{Closure(string): string}>
 */
function playerTokenInvalidCookies(): array
{
    return [
        'altéré (MAC faux)' => [static function (string $valid): string {
            $payload = json_decode((string) base64_decode(playerTokenEncrypt($valid), true), true);
            $payload['value'] = strrev((string) $payload['value']);

            return base64_encode((string) json_encode($payload));
        }],
        'illisible (JSON tronqué)' => [static fn (string $valid): string => playerTokenEncrypt(substr($valid, 0, 20))],
        'illisible (non chiffré)' => [static fn (string $valid): string => $valid],
        'étranger (autre cookie)' => [static fn (string $valid): string => playerTokenEncrypt($valid, LocaleCookie::NAME)],
        'étranger (autre clé)' => [static function (string $valid): string {
            $foreign = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');

            return $foreign->encrypt(CookieValuePrefix::create(PlayerTokenCookie::NAME, $foreign->getKey()).$valid, false);
        }],
        "d'une version future" => [static function (string $valid): string {
            $claims = json_decode($valid, true);
            $claims['v'] = PlayerToken::VERSION + 1;

            return playerTokenEncrypt((string) json_encode($claims));
        }],
        'tid hors motif' => [static function (string $valid): string {
            $claims = json_decode($valid, true);
            $claims['tid'] = strtoupper((string) $claims['tid']);

            return playerTokenEncrypt((string) json_encode($claims));
        }],
        'revendication de mauvais type' => [static function (string $valid): string {
            $claims = json_decode($valid, true);
            $claims['locale'] = 12;

            return playerTokenEncrypt((string) json_encode($claims));
        }],
    ];
}

it("traite comme absent un cookie altéré, illisible, étranger ou d'une version future", function (Closure $forge) {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message;
    });

    // Un siège existe sous le jeton valide : si le cookie forgé était accepté,
    // le geste suivant le reprendrait au lieu d'en frapper un neuf.
    $room = Room::factory()->create();
    $valid = PlayerToken::mint(Locale::English, 'preset-07');
    $held = Player::factory()->for($room)->create(['player_token_hash' => $valid->hash()]);
    $raw = $forge(json_encode($valid->toClaims(), JSON_THROW_ON_ERROR));

    $this->withUnencryptedCookie(PlayerTokenCookie::NAME, $raw)
        ->getJson(playerTokenPrefix().'/probe')
        ->assertOk()
        ->assertJson(['present' => false, 'hash' => null]);

    // Le geste de siège suivant frappe un jeton neuf, sans erreur.
    $seat = $this->withUnencryptedCookie(PlayerTokenCookie::NAME, $raw)
        ->postJson(playerTokenSeatUri($room), ['nickname' => 'Zoé', 'avatar' => 'preset-03'])
        ->assertCreated()
        ->assertJson(['outcome' => 'seated']);

    $minted = (string) playerTokenClaims($seat)['tid'];

    expect(hash('sha256', $minted))->not->toBe($valid->hash())
        ->and(Player::query()->whereBelongsTo($room)->count())->toBe(2)
        ->and($held->refresh()->player_token_hash)->toBe($valid->hash());

    // Silencieusement : aucune ligne de journal.
    expect($logged)->toBe([]);
})->with(playerTokenInvalidCookies());

it("conserve le tid quand sa revendication de langue ou d'avatar n'est plus connue", function () {
    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French, 'preset-24');
    $seat = Player::factory()->for($room)->create(['player_token_hash' => $token->hash()]);

    // Une langue retirée : le jeton reste valide, sa revendication devient nulle.
    $claims = $token->toClaims();
    $claims['locale'] = 'de';

    $this->withCookie(PlayerTokenCookie::NAME, json_encode($claims, JSON_THROW_ON_ERROR))
        ->getJson(playerTokenPrefix().'/probe')
        ->assertOk()
        ->assertJson(['present' => true, 'hash' => $token->hash(), 'locale' => null, 'avatar' => 'preset-24']);

    // Un pack d'avatars réduit : `preset-24` sort du catalogue, le jeton reste.
    platformLimitsConfigure(['avatar_presets' => PlatformLimits::roomSeats()]);

    expect(AvatarPresetCatalog::has('preset-24'))->toBeFalse();

    $this->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
        ->getJson(playerTokenPrefix().'/probe')
        ->assertOk()
        ->assertJson(['present' => true, 'hash' => $token->hash(), 'locale' => 'fr', 'avatar' => null]);

    $decoded = PlayerToken::fromClaims(['v' => PlayerToken::VERSION, 'tid' => $token->toClaims()['tid'], 'locale' => 'de', 'avatar' => 'preset-99']);

    expect($decoded)->not->toBeNull()
        ->and($decoded?->sameIdentityAs($token))->toBeTrue()
        ->and($decoded?->locale)->toBeNull()
        ->and($decoded?->avatar)->toBeNull();

    // Aucun siège n'est détruit : le geste reprend le même siège, sous le même tid.
    $resumed = $this->withCookie(PlayerTokenCookie::NAME, json_encode($claims, JSON_THROW_ON_ERROR))
        ->postJson(playerTokenSeatUri($room))
        ->assertOk()
        ->assertJson(['outcome' => 'resumed', 'seat' => $seat->public_id]);

    expect(playerTokenClaims($resumed)['tid'])->toBe($token->toClaims()['tid'])
        ->and(Player::query()->count())->toBe(1);
});

it('refuse de re-signer un jeton sous un autre tid', function () {
    $tokens = app(PlayerTokenManager::class);
    $request = Request::create('/', 'POST');

    // Sans jeton courant : seul `ensure()` frappe.
    expect(fn () => $tokens->resign($request, PlayerToken::mint(Locale::English)))->toThrow(LogicException::class);

    $current = $tokens->ensure($request);
    $foreign = PlayerToken::mint(Locale::English, 'preset-01');

    expect(fn () => $tokens->resign($request, $foreign))->toThrow(LogicException::class)
        ->and(fn () => $tokens->resign($request, $foreign->withLocale(Locale::French)))->toThrow(LogicException::class);

    // Le refus ne touche ni le mémo de la requête ni le cookie en file.
    $queued = Cookie::queued(PlayerTokenCookie::NAME);

    expect($tokens->current($request)?->sameIdentityAs($current))->toBeTrue()
        ->and($queued)->toBeInstanceOf(SymfonyCookie::class)
        ->and(json_decode((string) $queued?->getValue(), true)['tid'])->toBe($current->toClaims()['tid']);

    // Sous le même tid, la re-signature passe, et `withLocale` / `withAvatar`
    // conservent l'identité.
    $resigned = $tokens->resign($request, $current->withAvatar('preset-05')->withLocale(Locale::French));

    expect($resigned->sameIdentityAs($current))->toBeTrue()
        ->and($resigned->hash())->toBe($current->hash())
        ->and($tokens->current($request)?->avatar)->toBe('preset-05');
});

it("laisse le player_token intact à la connexion, à la déconnexion et à l'inscription", function () {
    $room = Room::factory()->create();
    $seated = $this->postJson(playerTokenSeatUri($room), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])->assertCreated();
    $raw = playerTokenRaw($seated);
    $hash = Player::query()->sole()->player_token_hash;

    $this->withUnencryptedCookie(PlayerTokenCookie::NAME, $raw);

    $probe = fn () => $this->getJson(playerTokenPrefix().'/probe')
        ->assertOk()
        ->assertJson(['present' => true, 'hash' => $hash]);

    $user = User::factory()->create();

    $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
    expect(playerTokenSetCookies($login))->toBe([]);
    $probe();

    $logout = $this->post(route('logout'));
    $this->assertGuest();
    expect(playerTokenSetCookies($logout))->toBe([]);
    $probe();

    $register = $this->post(route('register.store'), [
        'name' => 'Nouveau Compte',
        'email' => 'nouveau@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
    $this->assertAuthenticated();
    expect(playerTokenSetCookies($register))->toBe([]);
    $probe();

    // Le siège se retrouve toujours par le même jeton.
    $this->postJson(playerTokenSeatUri($room))
        ->assertOk()
        ->assertJson(['outcome' => 'resumed']);

    expect(Player::query()->count())->toBe(1);
});

it('ne frappe aucun player_token quand la prise de siège est refusée', function () {
    // Pseudo refusé à la validation.
    $open = Room::factory()->create();

    $invalid = $this->postJson(playerTokenSeatUri($open), ['nickname' => 'Z', 'avatar' => 'preset-07'])
        ->assertUnprocessable();

    // Salon archivé.
    $archived = $this->postJson(playerTokenSeatUri(Room::factory()->archived()->create()), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])
        ->assertStatus(410);

    // Salon plein.
    $full = Room::factory()->create();
    Player::factory()->count($full->capacity)->for($full)->create();

    $crowded = $this->postJson(playerTokenSeatUri($full), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])
        ->assertStatus(409)
        ->assertJson(['refused' => 'room.join.full']);

    // Pseudo pris, repli compris.
    Player::factory()->for($open)->withNickname('Zoé')->create();

    $taken = $this->postJson(playerTokenSeatUri($open), ['nickname' => 'zoe', 'avatar' => 'preset-07'])
        ->assertUnprocessable()
        ->assertJson(['refused' => 'validation.nickname.taken']);

    foreach (compact('invalid', 'archived', 'crowded', 'taken') as $gesture => $response) {
        expect(playerTokenSetCookies($response))->toBe([], "[{$gesture}] a frappé un player_token.");
    }

    // Expulsé, dans un salon plein : le refus est l'expulsion, avant tout
    // comptage, et il ne repose même pas le cookie du jeton existant.
    $token = PlayerToken::mint(Locale::English);
    Player::factory()->for($full)->kicked()->create(['player_token_hash' => $token->hash()]);
    $before = Player::query()->count();

    $kicked = $this->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
        ->postJson(playerTokenSeatUri($full), ['nickname' => 'Autre', 'avatar' => 'preset-02'])
        ->assertForbidden()
        ->assertJson(['refused' => 'room.join.kicked']);

    expect(playerTokenSetCookies($kicked))->toBe([])
        ->and(Player::query()->count())->toBe($before);
});

it('repart de 30 jours pleins à chaque re-signature, changement de langue compris', function () {
    $this->freezeSecond();

    $seated = $this->postJson(playerTokenSeatUri(Room::factory()->create()), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])
        ->assertCreated();
    $tid = playerTokenClaims($seated)['tid'];

    // Douze jours plus tard, un changement de langue re-signe le jeton — par
    // la vraie route, `locale.update` (L40-2).
    $this->travel(12)->days();

    $language = $this->withUnencryptedCookie(PlayerTokenCookie::NAME, playerTokenRaw($seated))
        ->from('/')
        ->post(route('locale.update'), ['locale' => Locale::French->value])
        ->assertRedirect('/');

    expect(playerTokenSetCookies($language))->toHaveCount(1)
        ->and($language->getCookie(PlayerTokenCookie::NAME, decrypt: false)?->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp())
        ->and(playerTokenClaims($language)['locale'])->toBe('fr')
        ->and(playerTokenClaims($language)['tid'])->toBe($tid);

    // Trois jours plus tard, une re-signature d'avatar repart elle aussi de 30 jours pleins.
    $this->travel(3)->days();

    $avatar = $this->withUnencryptedCookie(PlayerTokenCookie::NAME, playerTokenRaw($language))
        ->postJson(playerTokenPrefix().'/resign', ['avatar' => 'preset-09'])
        ->assertOk();

    expect($avatar->getCookie(PlayerTokenCookie::NAME, decrypt: false)?->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp())
        ->and(playerTokenClaims($avatar))->toBe(['v' => PlayerToken::VERSION, 'tid' => $tid, 'locale' => 'fr', 'avatar' => 'preset-09']);
});

it("re-signe le player_token avec l'avatar choisi sous le même tid, que suggest() présélectionne au salon suivant s'il y est libre", function () {
    $roomA = Room::factory()->create();
    $free = Room::factory()->create();
    $busy = Room::factory()->create();

    Player::factory()->for($busy)->create(['avatar_preset' => 'preset-07']);
    Player::factory()->for($busy)->create(['avatar_preset' => 'preset-01']);
    // Un siège parti ne tient plus son avatar.
    Player::factory()->for($busy)->left()->create(['avatar_preset' => 'preset-02']);

    $seated = $this->postJson(playerTokenSeatUri($roomA), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])->assertCreated();
    $claims = playerTokenClaims($seated);

    expect($claims['avatar'])->toBe('preset-07')
        ->and(Player::query()->whereBelongsTo($roomA)->sole()->avatar_preset)->toBe('preset-07');

    $this->withUnencryptedCookie(PlayerTokenCookie::NAME, playerTokenRaw($seated));

    // Libre au salon suivant : présélectionné.
    $this->getJson(playerTokenSuggestUri($free))->assertOk()->assertJson(['suggested' => 'preset-07']);

    // Pris : le premier libre dans l'ordre du catalogue.
    $this->getJson(playerTokenSuggestUri($busy))->assertOk()->assertJson(['suggested' => 'preset-02']);

    // Un autre choix au salon suivant re-signe le jeton sous le même tid.
    $next = $this->postJson(playerTokenSeatUri($free), ['nickname' => 'Zoé', 'avatar' => 'preset-03'])->assertCreated();

    expect(playerTokenClaims($next)['avatar'])->toBe('preset-03')
        ->and(playerTokenClaims($next)['tid'])->toBe($claims['tid'])
        ->and(Player::query()->where('player_token_hash', hash('sha256', (string) $claims['tid']))->count())->toBe(2);
});

it("prend le siège d'un compte connecté sous le pseudo saisi, sans user_id au jalon 1", function () {
    $user = User::factory()->create(['name' => 'Alice Martin']);

    $seated = $this->actingAs($user)
        ->postJson(playerTokenSeatUri(Room::factory()->create()), ['nickname' => 'Zoé', 'avatar' => 'preset-04'])
        ->assertCreated();

    $seat = Player::query()->sole();

    expect($seat->nickname)->toBe('Zoé')
        ->and($seat->user_id)->toBeNull()
        ->and($seat->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($seat->avatar_preset)->toBe('preset-04')
        // Le compte ne passe jamais dans le jeton : quatre revendications, aucune du compte.
        ->and(array_keys(playerTokenClaims($seated)))->toBe(['v', 'tid', 'locale', 'avatar'])
        ->and(json_encode(playerTokenClaims($seated), JSON_THROW_ON_ERROR))
        ->not->toContain((string) $user->email)
        ->not->toContain($user->name)
        // Aucune écriture de masse ne peut rattacher un siège à un compte.
        ->and($seat->isFillable('user_id'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| La langue portée par le jeton — spec 40 § 4.2, I4.8 (L40-2)
|--------------------------------------------------------------------------
|
| `locale.update` garde son comportement (compte, cookie `locale`, `back()`)
| et, SI la requête porte un jeton, le re-signe sous le même `tid` puis passe
| chaque siège qu'il tient à la nouvelle langue. Sans jeton, rien n'est frappé.
|
*/

/**
 * Les lignes brutes d'une table, par identifiant, telles que la base les
 * stocke : millisecondes comprises, sans aucun cast du modèle.
 *
 * @return array<int, array<string, mixed>>
 */
function playerTokenRawRows(string $table): array
{
    $rows = [];

    foreach (DB::table($table)->orderBy('id')->get() as $row) {
        $rows[(int) $row->id] = (array) $row;
    }

    return $rows;
}

it('re-signe le player_token avec la nouvelle langue et le même tid', function () {
    $this->freezeSecond();
    $room = Room::factory()->create();

    $seated = $this->withUnencryptedCookie(LocaleCookie::NAME, Locale::English->value)
        ->postJson(playerTokenSeatUri($room), ['nickname' => 'Zoé', 'avatar' => 'preset-07'])
        ->assertCreated();
    $claims = playerTokenClaims($seated);
    $seat = Player::query()->sole();

    expect($claims['locale'])->toBe('en');

    // Le changement de langue garde son comportement — cookie `locale`,
    // `back()` — et re-signe le jeton : un seul Set-Cookie, même tid, avatar
    // conservé, nouvelle langue, 30 jours pleins.
    $changed = $this->withUnencryptedCookie(PlayerTokenCookie::NAME, playerTokenRaw($seated))
        ->from('/')
        ->post(route('locale.update'), ['locale' => Locale::French->value])
        ->assertRedirect('/')
        ->assertCookie(LocaleCookie::NAME, Locale::French->value, encrypted: false);

    expect(playerTokenSetCookies($changed))->toHaveCount(1)
        ->and(playerTokenClaims($changed))->toBe(['v' => PlayerToken::VERSION, 'tid' => $claims['tid'], 'locale' => 'fr', 'avatar' => 'preset-07'])
        ->and(playerTokenRaw($changed))->not->toBe(playerTokenRaw($seated))
        ->and($changed->getCookie(PlayerTokenCookie::NAME, decrypt: false)?->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp());

    // Le siège ne change pas : le hash du tid le retrouve, et le jeton re-signé
    // le reprend, comme un navigateur qui renvoie les deux cookies reçus.
    $this->withUnencryptedCookie(PlayerTokenCookie::NAME, playerTokenRaw($changed))
        ->withUnencryptedCookie(LocaleCookie::NAME, Locale::French->value);

    $this->getJson(playerTokenPrefix().'/probe')
        ->assertOk()
        ->assertJson(['present' => true, 'hash' => $seat->player_token_hash, 'locale' => 'fr', 'avatar' => 'preset-07']);

    $this->postJson(playerTokenSeatUri($room))
        ->assertOk()
        ->assertJson(['outcome' => 'resumed', 'seat' => $seat->public_id]);

    // Idempotent : rejouer le geste rend le même jeton et le même siège.
    $again = $this->from('/')
        ->post(route('locale.update'), ['locale' => Locale::French->value])
        ->assertRedirect('/');

    expect(playerTokenSetCookies($again))->toHaveCount(1)
        ->and(playerTokenClaims($again))->toBe(['v' => PlayerToken::VERSION, 'tid' => $claims['tid'], 'locale' => 'fr', 'avatar' => 'preset-07'])
        ->and(Player::query()->sole()->is($seat))->toBeTrue()
        ->and(Player::query()->sole()->locale)->toBe(Locale::French)
        ->and(Player::query()->sole()->player_token_hash)->toBe(hash('sha256', (string) $claims['tid']));

    // Connecté, le compte ET le jeton suivent : la connexion ne remplace pas
    // le jeton, elle s'y ajoute (I4.6).
    $user = User::factory()->locale(Locale::French)->create();

    $account = $this->actingAs($user)
        ->withUnencryptedCookie(PlayerTokenCookie::NAME, playerTokenRaw($again))
        ->from('/')
        ->post(route('locale.update'), ['locale' => Locale::English->value])
        ->assertRedirect('/');

    expect($user->refresh()->locale)->toBe(Locale::English)
        ->and(playerTokenClaims($account))->toBe(['v' => PlayerToken::VERSION, 'tid' => $claims['tid'], 'locale' => 'en', 'avatar' => 'preset-07'])
        ->and(Player::query()->sole()->locale)->toBe(Locale::English);
});

it("passe chaque siège tenu par le jeton à la nouvelle langue, et rien d'autre", function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00.000'));

    $token = PlayerToken::mint(Locale::English, 'preset-07');
    $room = Room::factory()->create();

    // Tenus par le jeton : connecté, déconnecté (il tient toujours sa place),
    // et le siège solo, sans salon.
    $held = [
        'connecté' => Player::factory()->for($room)->create(['player_token_hash' => $token->hash()]),
        'déconnecté' => Player::factory()->disconnected()->create(['player_token_hash' => $token->hash()]),
        'solo' => Player::factory()->solo()->create(['player_token_hash' => $token->hash()]),
    ];

    // Hors d'atteinte : un siège parti (sa reprise réaligne la langue, § 2.2),
    // un siège expulsé (`left`), un siège archivé (plus de hash), et le siège
    // d'un autre jeton dans le même salon.
    $untouched = [
        'parti' => Player::factory()->left()->create(['player_token_hash' => $token->hash()]),
        'expulsé' => Player::factory()->kicked()->create(['player_token_hash' => $token->hash()]),
        'archivé' => Player::factory()->for(Room::factory()->archived())->archivedIdentity()->create(),
        'autre jeton' => Player::factory()->for($room)->create(['player_token_hash' => PlayerToken::mint(Locale::English)->hash()]),
    ];

    // Un QCM déjà composé en anglais pour le siège connecté : jamais recomposé.
    RoundPlayer::factory()->withChoices(Locale::English)->create(['player_id' => $held['connecté']->id]);

    foreach ([...$held, ...$untouched] as $case => $seat) {
        expect($seat->locale)->toBe(Locale::English, "[{$case}] ne part pas de l'anglais.");
    }

    $players = playerTokenRawRows('player');
    $roundPlayers = playerTokenRawRows('round_player');
    $users = playerTokenRawRows('users');

    // Plus tard, à une milliseconde non nulle : l'écriture doit la garder.
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:05:00.456'));

    $this->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
        ->from('/')
        ->post(route('locale.update'), ['locale' => Locale::French->value])
        ->assertRedirect('/');

    $after = playerTokenRawRows('player');

    // Chaque siège tenu : la langue, et l'horodatage d'Eloquent en `timestamp(3)`
    // (jamais `DB::table()`, qui ne le poserait pas) — rien d'autre.
    foreach ($held as $case => $seat) {
        $changed = array_keys(array_diff_assoc($after[$seat->id], $players[$seat->id]));
        sort($changed);

        expect($changed)->toBe(['locale', 'updated_at'], "[{$case}] : colonnes écrites inattendues.")
            ->and($after[$seat->id]['locale'])->toBe(Locale::French->value, "[{$case}] n'est pas passé au français.")
            ->and($after[$seat->id]['updated_at'])->toBe('2026-09-25 10:05:00.456', "[{$case}] : millisecondes perdues.");
    }

    foreach ($untouched as $case => $seat) {
        expect($after[$seat->id])->toBe($players[$seat->id], "[{$case}] a été touché.");
    }

    // Aucun état de jeu : le QCM reste dans sa langue de composition, et aucun
    // compte n'existe ni n'est écrit pour un invité.
    expect(playerTokenRawRows('round_player'))->toBe($roundPlayers)
        ->and(playerTokenRawRows('users'))->toBe($users)
        ->and(Player::query()->count())->toBe(count($held) + count($untouched));
});

it("ne frappe pas de player_token quand un invité qui n'en a pas change de langue", function () {
    // Des sièges existent sous d'autres jetons : aucun n'est touché.
    Player::factory()->count(2)->create();
    $players = playerTokenRawRows('player');

    $guest = $this->from('/')
        ->post(route('locale.update'), ['locale' => Locale::French->value])
        ->assertRedirect('/')
        ->assertCookie(LocaleCookie::NAME, Locale::French->value, encrypted: false);

    expect(playerTokenSetCookies($guest))->toBe([]);

    // Connecté sans jeton : le compte suit, aucun jeton n'est frappé — la
    // connexion ne lit ni n'écrit le cookie (I4.6).
    $user = User::factory()->locale(Locale::English)->create();

    $account = $this->actingAs($user)
        ->from('/')
        ->post(route('locale.update'), ['locale' => Locale::French->value])
        ->assertRedirect('/');

    expect($user->refresh()->locale)->toBe(Locale::French)
        ->and(playerTokenSetCookies($account))->toBe([]);

    // Un jeton invalide — ici non chiffré — vaut absence (I4.7) : pas de frappe
    // non plus.
    $forged = $this->withUnencryptedCookie(PlayerTokenCookie::NAME, json_encode(PlayerToken::mint(Locale::English)->toClaims(), JSON_THROW_ON_ERROR))
        ->from('/')
        ->post(route('locale.update'), ['locale' => Locale::French->value])
        ->assertRedirect('/');

    expect(playerTokenSetCookies($forged))->toBe([])
        ->and(playerTokenRawRows('player'))->toBe($players);

    $this->getJson(playerTokenPrefix().'/probe')
        ->assertOk()
        ->assertJson(['present' => false]);
});
