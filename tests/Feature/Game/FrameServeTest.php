<?php

use App\Actions\Game\CancelRound;
use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingState;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\SeenFrame;
use App\Models\User;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Frames\WebpPadding;
use App\Support\Game\ServeGuard;
use App\Support\Game\ServeUrl;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Frames\SourceImages;
use Tests\Support\Game\EngineFixtures;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Service d'image `GET /f/{serveToken}` — spec 60 § 7, contrat C8 (lot L60-8)
|--------------------------------------------------------------------------
|
| La route résout le `serve_token` d'une MANCHE, applique `ServeGuard` —
| catalogue, temps, appartenance du demandeur, à chaque service — puis sert
| les octets par `FrameImageResponse`. Tout refus rend la même 404 vide ;
| une signature invalide ou expirée, 403. Elle n'écrit rien, n'ouvre rien,
| ne pose ni session ni cookie.
|
| Vraies parties matérialisées sur de vraies variantes, fichiers réels sur
| le disque `frames` simulé (aplats générés, aucune image réelle), étapes de
| manche jouées par les actions réelles à leurs instants théoriques
| (`EngineFixtures`). Les jobs de frontière sont retenus : le temps n'avance
| que par l'horloge figée du test, et une requête d'image ne rattrape jamais
| une étape due.
|
| Chaque requête part d'un limiteur neuf : le débit de `frame-serve` est
| prouvé par `GameRateLimitersTest`, et les en-têtes `X-RateLimit-*` restent
| ainsi identiques d'une requête à l'autre.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Une partie en cours et un siège qui présente son `player_token`, la
 * partie matérialisée, sa manche 1 programmée au bout du décompte de
 * lancement (le palier 1 frappé).
 *
 * @return array{Game, Round, PlayerToken}
 */
function frameServeGame(?RoomSettings $settings = null, ?int $preloadLeadMs = null, bool $solo = false): array
{
    $token = PlayerToken::mint(Locale::French);
    $game = EngineFixtures::game($settings ?? EngineFixtures::settings(), solo: $solo);

    // `preload_lead_ms` est une colonne figée de la partie (50 § 12.7) : la
    // fixture la pose en base, comme si la partie était née avec elle,
    // jamais par une sauvegarde du modèle, que la garde refuse.
    if ($preloadLeadMs !== null) {
        Game::query()->whereKey($game->id)->update(['preload_lead_ms' => $preloadLeadMs]);
        $game->refresh();
    }

    EngineFixtures::seat($game, ['player_token_hash' => $token->hash()]);
    EngineFixtures::materialize($game);

    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));

    return [$game->refresh(), $round->refresh(), $token];
}

/**
 * Un siège de plus dans la partie, et son jeton.
 *
 * @param  array<string, mixed>  $player
 * @return array{Player, PlayerToken}
 */
function frameServeSeat(
    Game $game,
    array $player = [],
    ?int $firstRoundNumber = null,
    GamePlayerStatus $status = GamePlayerStatus::Playing,
): array {
    $token = PlayerToken::mint(Locale::English);
    $seat = EngineFixtures::seat($game, ['player_token_hash' => $token->hash(), ...$player], $firstRoundNumber, $status);

    return [$seat, $token];
}

/**
 * Un GET de l'URL, comme le navigateur l'envoie : le cookie `player_token`
 * chiffré (charge d'un jeton, ou charge brute), les seuls cookies chiffrés
 * supplémentaires donnés (valeurs en clair), et des en-têtes serveur au
 * choix. Limiteur remis à neuf.
 *
 * @param  PlayerToken|array<string, mixed>|null  $token
 * @param  array<string, string>  $server
 * @param  array<string, string>  $cookies
 * @return TestResponse<Response>
 */
function frameServeGet(TestCase $test, string $url, PlayerToken|array|null $token, array $server = [], array $cookies = []): TestResponse
{
    Cache::flush();

    $key = app('encrypter')->getKey();

    if ($token !== null) {
        $claims = $token instanceof PlayerToken ? $token->toClaims() : $token;
        $cookies[PlayerTokenCookie::NAME] = json_encode($claims, JSON_THROW_ON_ERROR);
    }

    foreach ($cookies as $name => $value) {
        $cookies[$name] = encrypt(CookieValuePrefix::create($name, $key).$value, false);
    }

    $response = $test->call('GET', $url, [], $cookies, [], $server);

    // Le flux est lu comme le client le lirait : le rappel referme le fichier
    // (sous Windows, un descripteur resté ouvert verrouille le fichier).
    if ($response->baseResponse instanceof StreamedResponse) {
        $response->streamedContent();
    }

    return $response;
}

/**
 * Le statut de l'URL du palier à l'instant `$at`.
 */
function frameServeStatus(TestCase $test, RoundTier $tier, ?PlayerToken $token, CarbonImmutable $at): int
{
    Date::setTestNow($at);

    return frameServeGet($test, ServeUrl::for($tier), $token)->getStatusCode();
}

/**
 * Les octets du dérivé servi du palier, lus sur le disque.
 */
function frameServeBytes(RoundTier $tier): string
{
    $path = $tier->servedFrame?->game_path ?? throw new LogicException('Palier sans dérivé servi.');

    return (string) Storage::disk(FrameStoragePrefix::DISK)->get($path);
}

/**
 * Ce que le client peut observer d'une réponse de refus : statut, corps et
 * en-têtes complets, hors `Date`.
 *
 * @param  TestResponse<Response>  $response
 * @return array{status: int, body: string|false, headers: array<string, list<string|null>>}
 */
function frameServeFingerprint(TestResponse $response): array
{
    $headers = $response->headers->all();
    unset($headers['date']);
    ksort($headers);

    return [
        'status' => $response->getStatusCode(),
        'body' => $response->baseResponse->getContent(),
        'headers' => $headers,
    ];
}

/**
 * Le palier, relu avec sa manche, sa partie et sa frame servie, et les trois
 * parties du prédicat pour ce jeton à cet instant.
 *
 * @return array{catalogue: bool, time: bool, membership: bool}
 */
function frameServeParts(RoundTier $tier, ?PlayerToken $token, CarbonImmutable $at): array
{
    $tier = RoundTier::query()->with(['round.game', 'servedFrame'])->findOrFail($tier->id);
    $guard = app(ServeGuard::class);

    return [
        'catalogue' => $guard->catalogueAllows($tier),
        'time' => $guard->timeAllows($tier, $at),
        'membership' => $guard->membershipAllows($tier, $token?->hash()),
    ];
}

test('le palier i est refusé à Tᵢ − preload_lead_ms − 1 ms', function (): void {
    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        [$game, $round, $token] = frameServeGame(EngineFixtures::settings($framesPerRound));

        foreach (range(1, $framesPerRound) as $tierIndex) {
            $label = "N={$framesPerRound}, palier {$tierIndex}";

            // Le jeton du palier i est frappé à l'ouverture du palier i−1.
            if ($tierIndex > 1) {
                EngineFixtures::openTier($round, $tierIndex - 1);
            }

            $tier = EngineFixtures::tier($round, $tierIndex);
            $opensAt = EngineFixtures::opensAt($round, $tierIndex);
            $refusedAt = $opensAt->subMilliseconds($game->preload_lead_ms + 1);

            expect(frameServeStatus($this, $tier, $token, $refusedAt))->toBe(404, $label)
                // Refusé par la garde temporelle seule : le catalogue et le
                // siège l'auraient admis.
                ->and(frameServeParts($tier, $token, $refusedAt))->toBe(['catalogue' => true, 'time' => false, 'membership' => true], $label);
        }
    }
});

test('le palier i est servi à Tᵢ − preload_lead_ms + 1 ms', function (): void {
    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        [$game, $round, $token] = frameServeGame(EngineFixtures::settings($framesPerRound));

        foreach (range(1, $framesPerRound) as $tierIndex) {
            $label = "N={$framesPerRound}, palier {$tierIndex}";

            if ($tierIndex > 1) {
                EngineFixtures::openTier($round, $tierIndex - 1);
            }

            $tier = EngineFixtures::tier($round, $tierIndex);
            $windowOpens = EngineFixtures::opensAt($round, $tierIndex)->subMilliseconds($game->preload_lead_ms);

            Date::setTestNow($windowOpens->addMillisecond());
            $response = frameServeGet($this, ServeUrl::for($tier), $token);

            expect($response->getStatusCode())->toBe(200, $label)
                ->and($response->streamedContent())->toBe(frameServeBytes($tier), $label)
                // La borne est incluse : servi dès `Tᵢ − preload_lead_ms` pile.
                ->and(frameServeStatus($this, $tier, $token, $windowOpens))->toBe(200, $label)
                ->and(frameServeStatus($this, $tier, $token, $windowOpens->subMillisecond()))->toBe(404, $label);
        }
    }
});

test('la garde relit preload_lead_ms depuis game et non depuis la configuration', function (): void {
    // Deux parties lancées sous les deux bornes de la fenêtre, puis un
    // redéploiement qui change la valeur de plateforme dans l'autre sens :
    // la fenêtre d'une partie en cours ne bouge pas.
    $cases = [
        'partie à la borne basse' => [PlatformLimits::MIN_PRELOAD_LEAD_MS, PlatformLimits::MAX_PRELOAD_LEAD_MS],
        'partie à la borne haute' => [PlatformLimits::MAX_PRELOAD_LEAD_MS, PlatformLimits::MIN_PRELOAD_LEAD_MS],
    ];

    foreach ($cases as $label => [$frozen, $platform]) {
        app()->forgetInstance(PlatformLimits::class);
        [$game, $round, $token] = frameServeGame(preloadLeadMs: $frozen);

        app()->instance(PlatformLimits::class, new PlatformLimits(...[
            ...get_object_vars(PlatformLimits::current()),
            'preloadLeadMs' => $platform,
        ]));

        expect($game->preload_lead_ms)->toBe($frozen, $label)
            ->and(PlatformLimits::preloadLeadMs())->toBe($platform, $label);

        $tier = EngineFixtures::tier($round, 1);
        $t1 = EngineFixtures::opensAt($round, 1);

        // Servi dès `T₁ − game.preload_lead_ms`, refusé une milliseconde
        // avant, quelle que soit la valeur de plateforme.
        expect(frameServeStatus($this, $tier, $token, $t1->subMilliseconds($frozen)))->toBe(200, $label)
            ->and(frameServeStatus($this, $tier, $token, $t1->subMilliseconds($frozen + 1)))->toBe(404, $label);

        // Entre les deux fenêtres, la colonne décide, jamais la plateforme.
        $between = $t1->subMilliseconds(intdiv($frozen + $platform, 2));

        expect(frameServeStatus($this, $tier, $token, $between))->toBe($frozen > $platform ? 200 : 404, $label);
    }

    app()->forgetInstance(PlatformLimits::class);
});

test('le palier 1 de la manche 1 n\'a aucune fenêtre d\'exception', function (): void {
    $launchedAt = Date::now()->toImmutable();
    [$game, $first, $token] = frameServeGame();
    $tier = EngineFixtures::tier($first, 1);
    $t1 = EngineFixtures::opensAt($first, 1);

    expect($t1->equalTo($launchedAt->addMilliseconds(EngineConstants::launchCountdownMs())))->toBeTrue();

    // Le décompte de lancement n'est pas une fenêtre de préchargement : du
    // lancement à `T₁ − preload_lead_ms`, refusé.
    foreach ([$launchedAt, $launchedAt->addMilliseconds(EngineConstants::launchCountdownMs() - $game->preload_lead_ms - 1)] as $at) {
        expect(frameServeStatus($this, $tier, $token, $at))->toBe(404)
            ->and(frameServeParts($tier, $token, $at)['time'])->toBeFalse();
    }

    expect(frameServeStatus($this, $tier, $token, $t1->subMilliseconds($game->preload_lead_ms)))->toBe(200);

    // La même règle, à la milliseconde, que le palier 1 de la manche 2,
    // programmée par la révélation de la manche 1 à sa fin (`T₁(2) =
    // reveal_ends_at(1)`) : sa fenêtre s'ouvre pendant cette révélation.
    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    EngineFixtures::reveal($first);
    $second = EngineFixtures::round($game, 2);
    $secondTier = EngineFixtures::tier($second, 1);
    $t1Second = EngineFixtures::opensAt($second, 1);

    expect(frameServeStatus($this, $secondTier, $token, $t1Second->subMilliseconds($game->preload_lead_ms + 1)))->toBe(404)
        ->and(frameServeStatus($this, $secondTier, $token, $t1Second->subMilliseconds($game->preload_lead_ms)))->toBe(200);
});

test('un palier jamais ouvert n\'est pas servi pendant la révélation d\'une manche close par fin anticipée', function (): void {
    [$game, $round, $token] = frameServeGame();
    $last = $game->frames_per_round;

    foreach (range(1, $last - 1) as $tierIndex) {
        EngineFixtures::openTier($round, $tierIndex);
    }

    // Fin anticipée dans la fenêtre de préchargement du dernier palier,
    // déjà frappé : il ne s'ouvrira jamais.
    $tLast = EngineFixtures::opensAt($round, $last);
    EngineFixtures::close($round, $tLast->subMilliseconds(intdiv($game->preload_lead_ms, 4)));
    EngineFixtures::reveal($round);

    $round->refresh();
    $unopened = EngineFixtures::tier($round, $last);
    $revealEndsAt = $round->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.');
    $revealStartsAt = $revealEndsAt->subSeconds($game->settings_snapshot->revealDuration);

    expect($round->status)->toBe(RoundStatus::Revealing)
        ->and($unopened->serve_token)->toMatch('/^[0-9a-f]{32}$/')
        ->and($unopened->served_at)->toBeNull();

    // Du début à la fin de la révélation, `Tᵢ` franchi compris : jamais servi.
    foreach ([$revealStartsAt, $tLast->addSecond(), $revealEndsAt->subMillisecond()] as $at) {
        expect(frameServeStatus($this, $unopened, $token, $at))->toBe(404)
            ->and(frameServeParts($unopened, $token, $at))->toBe(['catalogue' => true, 'time' => false, 'membership' => true]);

        // Les paliers ouverts, eux, sont remontrés (D14).
        foreach (range(1, $last - 1) as $tierIndex) {
            expect(frameServeStatus($this, EngineFixtures::tier($round, $tierIndex), $token, $at))->toBe(200);
        }
    }
});

test('un palier que la fin anticipée a empêché de s\'ouvrir n\'est pas servi pendant la grâce finale', function (): void {
    foreach (['avant Tᵢ' => true, 'à Tᵢ pile' => false] as $label => $beforeOpening) {
        [$game, $round, $token] = frameServeGame();
        $last = $game->frames_per_round;

        foreach (range(1, $last - 1) as $tierIndex) {
            EngineFixtures::openTier($round, $tierIndex);
        }

        $unopened = EngineFixtures::tier($round, $last);
        $tLast = EngineFixtures::opensAt($round, $last);
        $endedAt = $beforeOpening ? $tLast->subMilliseconds(intdiv($game->preload_lead_ms, 4)) : $tLast;

        // Dans sa fenêtre de préchargement et avant la clôture : servi.
        expect(frameServeStatus($this, $unopened, $token, $endedAt->subMillisecond()))->toBe(200, $label);

        EngineFixtures::close($round, $endedAt);

        expect($round->refresh()->status)->toBe(RoundStatus::Running, $label)
            ->and($round->ended_at?->equalTo($endedAt))->toBeTrue();

        // Pendant la grâce finale, job de révélation compris en retard : la
        // manche est encore `running`, la fenêtre ouverte, et pourtant
        // `Tᵢ ≥ ended_at` refuse.
        $graceEnds = $endedAt->addMilliseconds($game->tier_grace_ms);

        foreach ([$endedAt, $graceEnds->subMillisecond(), $graceEnds->addMilliseconds(700)] as $at) {
            expect(frameServeStatus($this, $unopened, $token, $at))->toBe(404, $label)
                ->and(frameServeParts($unopened, $token, $at))->toBe(['catalogue' => true, 'time' => false, 'membership' => true], $label);

            foreach (range(1, $last - 1) as $tierIndex) {
                expect(frameServeStatus($this, EngineFixtures::tier($round, $tierIndex), $token, $at))->toBe(200, $label);
            }
        }

        expect($round->refresh()->status)->toBe(RoundStatus::Running, $label)
            ->and(EngineFixtures::tier($round, $last)->served_at)->toBeNull();
    }
});

test('les paliers ouverts restent servis jusqu\'à reveal_ends_at puis sont refusés', function (): void {
    [$game, $round, $token] = frameServeGame();
    $tierIndexes = range(1, $game->frames_per_round);

    foreach ($tierIndexes as $tierIndex) {
        EngineFixtures::openTier($round, $tierIndex);
    }

    EngineFixtures::close($round);
    $endedAt = $round->refresh()->ended_at ?? throw new LogicException('Manche non close.');

    // Grâce finale d'une manche close à `D` : tous ses paliers ont été ouverts.
    foreach ($tierIndexes as $tierIndex) {
        expect(frameServeStatus($this, EngineFixtures::tier($round, $tierIndex), $token, $endedAt->addMilliseconds($game->tier_grace_ms - 1)))->toBe(200);
    }

    EngineFixtures::reveal($round);
    $revealEndsAt = $round->refresh()->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.');

    foreach ($tierIndexes as $tierIndex) {
        $tier = EngineFixtures::tier($round, $tierIndex);

        expect(frameServeStatus($this, $tier, $token, $endedAt->addMilliseconds($game->tier_grace_ms)))->toBe(200)
            ->and(frameServeStatus($this, $tier, $token, $revealEndsAt->subMillisecond()))->toBe(200)
            // À `reveal_ends_at`, même si le job de fin de révélation tarde.
            ->and(frameServeStatus($this, $tier, $token, $revealEndsAt))->toBe(404)
            ->and(frameServeParts($tier, $token, $revealEndsAt)['time'])->toBeFalse();
    }

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing);

    EngineFixtures::endReveal($round);

    expect($round->refresh()->status)->toBe(RoundStatus::Completed);

    // Manche complétée : refus, signature encore valide (404, jamais 403).
    foreach ($tierIndexes as $tierIndex) {
        expect(frameServeStatus($this, EngineFixtures::tier($round, $tierIndex), $token, $revealEndsAt->addSecond()))->toBe(404);
    }
});

test('le palier 1 d\'une manche déprogrammée par une pause n\'est plus servi', function (): void {
    [$game, $first, $token] = frameServeGame();
    $seat = Player::query()->where('player_token_hash', $token->hash())->firstOrFail();

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    EngineFixtures::reveal($first);

    $revealEndsAt = $first->refresh()->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.');
    $second = EngineFixtures::round($game, 2);
    $tier = EngineFixtures::tier($second, 1);

    // L'URL émise par `round.scheduled` : le client la garde.
    $url = ServeUrl::for($tier);

    Date::setTestNow($revealEndsAt->subMilliseconds($game->preload_lead_ms - 1));
    expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe(200);

    // Le siège perd le réseau : la fin de révélation met la partie en pause
    // et déprogramme la manche 2.
    $seat->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()])->save();
    EngineFixtures::endReveal($first);

    expect($game->refresh()->status)->toBe(GameStatus::Paused)
        ->and($second->refresh()->started_at)->toBeNull()
        ->and(EngineFixtures::tier($second, 1)->serve_token)->toBe($tier->serve_token);

    // La même URL, signature toujours valide, n'est plus servie.
    foreach ([$revealEndsAt, $revealEndsAt->addSecond(), $revealEndsAt->addSeconds(5)] as $at) {
        Date::setTestNow($at);

        expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe(404)
            ->and(frameServeParts($tier, $token, $at))->toBe(['catalogue' => true, 'time' => false, 'membership' => true]);
    }
});

test('un retardataire admis à la manche suivante est refusé sur la manche en cours', function (): void {
    [$game, $first, $token] = frameServeGame();
    [, $lateToken] = frameServeSeat($game, firstRoundNumber: 2);
    [, $laterToken] = frameServeSeat($game, firstRoundNumber: 3);

    $tier = EngineFixtures::tier($first, 1);
    $at = EngineFixtures::opensAt($first, 1)->subMilliseconds($game->preload_lead_ms - 1);

    expect(frameServeStatus($this, $tier, $token, $at))->toBe(200)
        ->and(frameServeStatus($this, $tier, $lateToken, $at))->toBe(404)
        ->and(frameServeStatus($this, $tier, $laterToken, $at))->toBe(404)
        ->and(frameServeParts($tier, $lateToken, $at))->toBe(['catalogue' => true, 'time' => true, 'membership' => false]);

    // À la manche 2, il est admis ; celui de la manche 3 attend encore.
    EngineFixtures::play($first);
    $second = EngineFixtures::round($game, 2);
    $secondTier = EngineFixtures::tier($second, 1);
    $secondAt = EngineFixtures::opensAt($second, 1)->addSecond();

    expect(frameServeStatus($this, $secondTier, $lateToken, $secondAt))->toBe(200)
        ->and(frameServeStatus($this, $secondTier, $token, $secondAt))->toBe(200)
        ->and(frameServeStatus($this, $secondTier, $laterToken, $secondAt))->toBe(404);
});

test('un siège expulsé est refusé', function (): void {
    [$game, $round, $token] = frameServeGame();
    [$kicked, $kickedToken] = frameServeSeat($game);
    [$playerKicked, $playerKickedToken] = frameServeSeat($game);
    [$rowKicked, $rowKickedToken] = frameServeSeat($game);

    $tier = EngineFixtures::tier($round, 1);
    $at = EngineFixtures::opensAt($round, 1)->addSecond();
    $tokens = [
        'expulsé' => $kickedToken,
        'player.kicked_at seul' => $playerKickedToken,
        'game_player.status seul' => $rowKickedToken,
    ];

    foreach ($tokens as $label => $seatToken) {
        expect(frameServeStatus($this, $tier, $seatToken, $at))->toBe(200, $label);
    }

    $kickedColumns = ['connection_state' => PlayerConnectionState::Left, 'left_at' => $at, 'kicked_at' => $at];

    // L'expulsion réelle écrit les deux ; chacune des deux suffit au refus.
    $kicked->forceFill($kickedColumns)->save();
    GamePlayer::query()->where('player_id', $kicked->id)->update(['status' => GamePlayerStatus::Kicked->value]);
    $playerKicked->forceFill($kickedColumns)->save();
    GamePlayer::query()->where('player_id', $rowKicked->id)->update(['status' => GamePlayerStatus::Kicked->value]);

    foreach ($tokens as $label => $seatToken) {
        expect(frameServeStatus($this, $tier, $seatToken, $at))->toBe(404, $label)
            ->and(frameServeParts($tier, $seatToken, $at))->toBe(['catalogue' => true, 'time' => true, 'membership' => false], $label);
    }

    // Le siège resté en jeu est toujours servi.
    expect(frameServeStatus($this, $tier, $token, $at))->toBe(200);
});

test('une frame devenue non servable est refusée à chaque service', function (): void {
    // Le palier 1 est substitué à sa frappe : la variante tirée est
    // suspendue, une autre variante publiée du même niveau la remplace.
    $token = PlayerToken::mint(Locale::French);
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game, ['player_token_hash' => $token->hash()]);
    $movies = EngineFixtures::materialize($game);
    $level = FrameLevelCoverage::nominal($game->frames_per_round)[0];
    $drawn = EngineFixtures::variant($movies[0], $level);
    $substitute = Frame::factory()->for($movies[0])->level($level)->published()->create();
    $drawn->forceFill(['availability' => ContentAvailability::Suspended])->save();

    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));
    $tier = EngineFixtures::tier($round, 1);

    expect($tier->frame_id)->toBe($drawn->id)
        ->and($tier->served_frame_id)->toBe($substitute->id);

    // Une seule URL, rejouée : le prédicat est relu à chaque service, sur la
    // variante SERVIE, jamais sur la variante tirée.
    Date::setTestNow(EngineFixtures::opensAt($round, 1)->addSecond());
    $url = ServeUrl::for($tier);
    $gamePath = (string) $substitute->game_path;

    $states = [
        'servable' => [[], 200],
        'suspendue' => [['availability' => ContentAvailability::Suspended], 404],
        'republiée' => [['availability' => ContentAvailability::Published], 200],
        'dépubliée' => [['availability' => ContentAvailability::Unpublished], 404],
        'publiée de nouveau' => [['availability' => ContentAvailability::Published], 200],
        'en retraitement' => [['processing_state' => FrameProcessingState::Pending], 404],
        'traitée' => [['processing_state' => FrameProcessingState::Ready], 200],
        'sans dérivé' => [['game_path' => null], 404],
        'dérivé rendu' => [['game_path' => $gamePath], 200],
        // Retrait juridique : état terminal, dernier de la liste.
        'retirée' => [['availability' => ContentAvailability::Withdrawn], 404],
    ];

    foreach ($states as $label => [$columns, $status]) {
        $substitute->forceFill($columns)->save();

        expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe($status, $label)
            ->and(frameServeParts($tier, $token, Date::now()->toImmutable())['catalogue'])->toBe($status === 200, $label);
    }
});

test('la route ne sert jamais un chemin hors du préfixe game/', function (): void {
    [, $round, $token] = frameServeGame();
    $tier = EngineFixtures::tier($round, 1);
    $frame = $tier->servedFrame ?? throw new LogicException('Palier sans frame servie.');
    $gamePath = (string) $frame->game_path;
    $masterPath = (string) $frame->master_path;
    $disk = Storage::disk(FrameStoragePrefix::DISK);

    Date::setTestNow(EngineFixtures::opensAt($round, 1)->addSecond());
    $url = ServeUrl::for($tier);

    expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe(200)
        ->and($disk->exists($masterPath))->toBeTrue();

    // Des fichiers qui EXISTENT sur le disque, hors du préfixe servable : le
    // master du film, une traversée vers lui, un chemin `game/` fabriqué
    // hors disposition. Le refus est celui du préfixe, jamais d'une absence.
    $fabricated = 'game/'.basename($gamePath);
    $disk->put($fabricated, frameServeBytes($tier));

    foreach (['master' => $masterPath, 'traversée' => 'game/../'.$masterPath, 'hors disposition' => $fabricated] as $label => $path) {
        $frame->forceFill(['game_path' => $path])->save();

        $response = frameServeGet($this, $url, $token);

        expect($response->getStatusCode())->toBe(404, $label)
            ->and($response->baseResponse->getContent())->toBe('', $label)
            ->and($response->baseResponse)->not->toBeInstanceOf(StreamedResponse::class)
            ->and(frameServeParts($tier, $token, Date::now()->toImmutable()))->toBe(['catalogue' => false, 'time' => true, 'membership' => true], $label);
    }

    $frame->forceFill(['game_path' => $gamePath])->save();

    expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe(200);
});

test('tous les refus du prédicat rendent la même réponse 404 vide', function (): void {
    [$game, $round, $token] = frameServeGame();
    [$kicked, $kickedToken] = frameServeSeat($game);
    [, $lateToken] = frameServeSeat($game, firstRoundNumber: 2);
    [, , $foreignToken] = frameServeGame();

    $tier = EngineFixtures::tier($round, 1);
    $frame = $tier->servedFrame ?? throw new LogicException('Palier sans frame servie.');
    $inWindow = EngineFixtures::opensAt($round, 1)->subMilliseconds($game->preload_lead_ms - 1);
    $url = ServeUrl::for($tier);

    Date::setTestNow($inWindow);
    $served = frameServeGet($this, $url, $token);

    expect($served->getStatusCode())->toBe(200);

    $refusals = [];

    // Jeton inconnu, signature valide.
    $refusals['jeton inconnu'] = frameServeGet($this, URL::temporarySignedRoute(
        'frame.serve',
        $inWindow->addMinute(),
        ['serveToken' => bin2hex(random_bytes(16))],
        absolute: false,
    ), $token);

    // (2) Temps.
    Date::setTestNow($inWindow->subMilliseconds(2));
    $refusals['avant sa garde'] = frameServeGet($this, $url, $token);
    Date::setTestNow($inWindow);

    $game->forceFill(['status' => GameStatus::Paused])->save();
    $refusals['partie en pause'] = frameServeGet($this, $url, $token);
    $game->forceFill(['status' => GameStatus::Running])->save();

    // (3) Appartenance.
    // Sans jeton, même si un siège de la partie n'a plus d'empreinte : une
    // absence ne désigne jamais un siège.
    EngineFixtures::seat($game, ['player_token_hash' => null]);
    $refusals['sans jeton'] = frameServeGet($this, $url, null);
    $refusals['jeton d\'une autre partie'] = frameServeGet($this, $url, $foreignToken);
    $refusals['retardataire'] = frameServeGet($this, $url, $lateToken);
    $kicked->forceFill(['connection_state' => PlayerConnectionState::Left, 'left_at' => $inWindow, 'kicked_at' => $inWindow])->save();
    $refusals['siège expulsé'] = frameServeGet($this, $url, $kickedToken);

    // (1) Catalogue.
    $frame->forceFill(['availability' => ContentAvailability::Suspended])->save();
    $refusals['frame suspendue'] = frameServeGet($this, $url, $token);
    $frame->forceFill(['availability' => ContentAvailability::Published])->save();

    $gamePath = (string) $frame->game_path;
    $frame->forceFill(['game_path' => (string) $frame->master_path])->save();
    $refusals['chemin hors game/'] = frameServeGet($this, $url, $token);
    $frame->forceFill(['game_path' => $gamePath])->save();

    // Le fichier absent (refus de `FrameImageResponse`, après le prédicat).
    $bytes = frameServeBytes($tier);
    Storage::disk(FrameStoragePrefix::DISK)->delete($gamePath);
    $refusals['fichier absent'] = frameServeGet($this, $url, $token);
    Storage::disk(FrameStoragePrefix::DISK)->put($gamePath, $bytes);

    expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe(200);

    // (2) Manche annulée.
    app(CancelRound::class)->handle($round, RoundIncidentReason::FrameUnavailable, $inWindow);

    expect($round->refresh()->status)->toBe(RoundStatus::Cancelled);

    $refusals['manche annulée'] = frameServeGet($this, $url, $token);

    $reference = frameServeFingerprint($refusals['jeton inconnu']);

    expect($reference['status'])->toBe(404)
        ->and($reference['body'])->toBe('')
        ->and($reference['headers']['cache-control'] ?? null)->toBe(['no-store, private'])
        ->and($reference['headers']['x-robots-tag'] ?? null)->toBe(['noindex, nofollow'])
        ->and($reference['headers'])->not->toHaveKey('set-cookie')
        ->and($reference['headers'])->not->toHaveKey('content-length')
        ->and(($reference['headers']['content-type'] ?? [''])[0])->not->toContain('image/');

    foreach ($refusals as $label => $response) {
        expect(frameServeFingerprint($response))->toBe($reference, $label);
    }
});

test('une signature invalide ou expirée est refusée', function (): void {
    [, $round, $token] = frameServeGame();
    $tier = EngineFixtures::tier($round, 1);
    $inWindow = EngineFixtures::opensAt($round, 1)->addSecond();
    $url = ServeUrl::for($tier);
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    Date::setTestNow($inWindow);

    expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe(200);

    $signature = (string) $query['signature'];
    $expires = (int) $query['expires'];
    $otherToken = bin2hex(random_bytes(16));

    $forged = [
        'signature altérée' => $path.'?expires='.$expires.'&signature='.strrev($signature),
        'sans signature' => $path.'?expires='.$expires,
        'sans rien' => $path,
        'expiration prolongée' => $path.'?expires='.($expires + 3600).'&signature='.$signature,
        'signature d\'un autre jeton' => '/f/'.$otherToken.'?expires='.$expires.'&signature='.$signature,
        // Une signature ABSOLUE ne vaut pas sur la route relative.
        'signature absolue' => (static function () use ($tier, $expires): string {
            $absolute = URL::temporarySignedRoute('frame.serve', CarbonImmutable::createFromTimestamp($expires), ['serveToken' => $tier->serve_token]);

            return (string) parse_url($absolute, PHP_URL_PATH).'?'.parse_url($absolute, PHP_URL_QUERY);
        })(),
    ];

    foreach ($forged as $label => $forgedUrl) {
        $response = frameServeGet($this, $forgedUrl, $token);

        expect($response->getStatusCode())->toBe(403, $label)
            ->and($response->headers->getCookies())->toBe([], $label)
            ->and($response->headers->all('x-robots-tag'))->toBe(['noindex, nofollow'], $label);
    }

    // Une URL expirée est refusée même dans la fenêtre du palier : la même
    // URL signée pour dix secondes, servie avant, refusée après.
    $short = URL::temporarySignedRoute('frame.serve', $inWindow->addSeconds(10), ['serveToken' => $tier->serve_token], absolute: false);

    Date::setTestNow($inWindow->addSeconds(9));
    expect(frameServeGet($this, $short, $token)->getStatusCode())->toBe(200);

    Date::setTestNow($inWindow->addSeconds(11));
    expect(frameServeGet($this, $short, $token)->getStatusCode())->toBe(403)
        ->and(frameServeGet($this, $url, $token)->getStatusCode())->toBe(200);
});

test('la réponse porte no-store, noindex, nosniff et same-origin, sans Content-Disposition, Last-Modified, ETag, Accept-Ranges ni Set-Cookie', function (): void {
    [, $round, $token] = frameServeGame();
    $tier = EngineFixtures::tier($round, 1);

    Date::setTestNow(EngineFixtures::opensAt($round, 1)->addSecond());
    $response = frameServeGet($this, ServeUrl::for($tier), $token);

    $response->assertOk();

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->baseResponse)->not->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->streamedContent())->toBe(frameServeBytes($tier))
        ->and($response->headers->get('Content-Type'))->toBe('image/webp')
        ->and($response->headers->all('cache-control'))->toBe(['no-store, private'])
        ->and($response->headers->all('x-robots-tag'))->toBe(['noindex, nofollow'])
        ->and($response->headers->all('x-content-type-options'))->toBe(['nosniff'])
        ->and($response->headers->all('cross-origin-resource-policy'))->toBe(['same-origin'])
        // Posé par le middleware global `RobotsDirectives`, toléré (§ 7.4).
        ->and($response->headers->all('referrer-policy'))->toBe(['strict-origin-when-cross-origin'])
        ->and($response->headers->getCookies())->toBe([]);

    foreach (['Content-Disposition', 'Last-Modified', 'ETag', 'Accept-Ranges', 'Expires', 'Set-Cookie'] as $absent) {
        expect($response->headers->has($absent))->toBeFalse($absent);
    }

    // L'ensemble complet : rien de dérivé du fichier (nom, date, empreinte).
    expect(array_diff(array_keys($response->headers->all()), [
        'content-type',
        'content-length',
        'cache-control',
        'x-robots-tag',
        'x-content-type-options',
        'cross-origin-resource-policy',
        'referrer-policy',
        'vary',
        'x-ratelimit-limit',
        'x-ratelimit-remaining',
        'date',
    ]))->toBe([]);
});

test('répond sans session et sans aucun Set-Cookie, même quand la langue est négociée', function (): void {
    [, $round, $token] = frameServeGame();
    $tier = EngineFixtures::tier($round, 1);

    Date::setTestNow(EngineFixtures::opensAt($round, 1)->addSecond());
    $url = ServeUrl::for($tier);

    // Le jeton du siège porte une langue retirée depuis sa frappe : il reste
    // valide (C4), et `SetLocale` négocie la langue, puis met le cookie
    // `locale` en file — il ne doit jamais partir. Même chose sans jeton.
    $requests = [
        'siège, langue négociée' => [[...$token->toClaims(), 'locale' => 'de'], 200],
        'sans jeton, langue négociée' => [null, 404],
        'siège, langue du jeton' => [$token, 200],
    ];

    foreach ($requests as $label => [$claims, $status]) {
        app('cookie')->flushQueuedCookies();

        $response = frameServeGet($this, $url, $claims, ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);

        expect($response->getStatusCode())->toBe($status, $label)
            ->and($response->headers->getCookies())->toBe([], $label)
            ->and($response->headers->has('Set-Cookie'))->toBeFalse($label)
            // `StartSession` appelle `setLaravelSession()` sur la requête
            // traitée : aucune session n'y a été posée.
            ->and($response->baseRequest->hasSession())->toBeFalse($label);

        if ($label !== 'siège, langue du jeton') {
            expect(Cookie::hasQueued('locale'))->toBeTrue($label);
        }
    }

    // La pile réelle de la route.
    $route = app('router')->getRoutes()->getByName('frame.serve') ?? throw new LogicException('Route frame.serve absente.');
    $stack = app('router')->gatherRouteMiddleware($route);

    expect($stack)->not->toContain(StartSession::class)
        ->and($stack)->not->toContain(ShareErrorsFromSession::class)
        ->and($stack)->not->toContain(PreventRequestForgery::class)
        ->and($stack)->not->toContain(AddQueuedCookiesToResponse::class)
        ->and($stack)->toContain(EncryptCookies::class)
        ->and($stack)->toContain(ValidateSignature::class.':relative')
        ->and($stack)->toContain(ThrottleRequests::class.':frame-serve');
});

test('Content-Length vaut game_bytes, multiple de 8 192', function (): void {
    [, $round, $token] = frameServeGame();
    $tier = EngineFixtures::tier($round, 1);
    $frame = $tier->servedFrame ?? throw new LogicException('Palier sans frame servie.');

    Date::setTestNow(EngineFixtures::opensAt($round, 1)->addSecond());
    $url = ServeUrl::for($tier);

    // Le dérivé de fixture (un quantum), puis un dérivé chargé de plusieurs
    // quanta, écrit comme la chaîne d'image l'écrit : WebP paddé au
    // multiple de 8 192.
    $image = new Imagick;
    $image->readImageBlob(SourceImages::plasma(FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT));
    $image->setImageFormat('webp');
    $image->setImageCompressionQuality(80);
    $image->stripImage();
    $loaded = WebpPadding::pad($image->getImageBlob());
    $image->clear();

    expect(strlen($loaded))->toBeGreaterThan(FrameGeometry::GAME_PAD_BYTES);

    foreach (['aplat de fixture' => null, 'dérivé chargé' => $loaded] as $label => $bytes) {
        if ($bytes !== null) {
            $path = FrameStoragePrefix::Game->newPath();
            Storage::disk(FrameStoragePrefix::DISK)->put($path, $bytes);
            $frame->forceFill(['game_path' => $path, 'game_bytes' => strlen($bytes), 'published_hash' => hash('sha256', $bytes)])->save();
        }

        $frame->refresh();
        $response = frameServeGet($this, $url, $token);
        $served = $response->streamedContent();

        expect($response->getStatusCode())->toBe(200, $label)
            ->and($response->headers->get('Content-Length'))->toBe((string) $frame->game_bytes, $label)
            ->and(strlen($served))->toBe($frame->game_bytes, $label)
            ->and($frame->game_bytes % FrameGeometry::GAME_PAD_BYTES)->toBe(0, $label)
            ->and($served)->toBe(frameServeBytes(EngineFixtures::tier($round, 1)), $label);
    }
});

test('la route n\'exécute aucune écriture', function (): void {
    [$game, $round, $token] = frameServeGame();
    $tier = EngineFixtures::tier($round, 1);
    $t1 = EngineFixtures::opensAt($round, 1);
    $url = ServeUrl::for($tier);
    $gameUpdatedAt = $game->refresh()->updated_at;

    // Un compte « se souvenir de moi » sur le même navigateur, et des
    // sessions en base comme en production. Sans session, le guard
    // retomberait sur ce cookie : lecture de `users`, régénération d'une
    // session (un DELETE sur `sessions`), `Login` émis à chaque image.
    $account = User::factory()->create(['remember_token' => Str::random(60)]);
    $remember = [Auth::guard('web')->getRecallerName() => $account->id.'|'.$account->remember_token.'|'.$account->password];

    Event::fake([Login::class]);
    config(['session.driver' => 'database']);
    app()->forgetInstance('session');
    app()->forgetInstance('session.store');
    app('auth')->forgetGuards();

    /** @var list<string> $statements */
    $statements = [];
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    // Le palier 1 est demandé trois secondes APRÈS T₁, son job d'ouverture
    // en retard : servi, sans jamais rattraper l'ouverture.
    Date::setTestNow($t1->addSeconds(3));
    expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe(200);

    $servedStatements = $statements;

    // Le même service, le cookie « se souvenir de moi » en plus : aucun
    // compte n'est résolu, ni par `SetLocale`, ni par les props partagées.
    expect(frameServeGet($this, $url, $token, cookies: $remember)->getStatusCode())->toBe(200);

    $rememberedStatements = array_slice($statements, count($servedStatements));

    // Des refus de chaque partie, et un jeton inconnu.
    Date::setTestNow($t1->subMilliseconds($game->preload_lead_ms + 1));
    expect(frameServeGet($this, $url, $token)->getStatusCode())->toBe(404);

    Date::setTestNow($t1->addSeconds(3));
    expect(frameServeGet($this, $url, null)->getStatusCode())->toBe(404)
        ->and(frameServeGet($this, URL::temporarySignedRoute('frame.serve', $t1->addMinute(), ['serveToken' => bin2hex(random_bytes(16))], absolute: false), $token)->getStatusCode())->toBe(404);

    $writes = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|upsert|create|drop|alter)\b/i', $sql) === 1,
    ));

    // Environ cinq lectures par service (E10-66) : le palier par son jeton,
    // sa manche, sa partie, sa frame servie, l'appartenance. Compte ou non.
    expect($writes)->toBe([])
        ->and(count($servedStatements))->toBeLessThanOrEqual(5)
        ->and(count($rememberedStatements))->toBeLessThanOrEqual(5)
        ->and(preg_grep('/"(users|sessions)"/', $statements))->toBe([]);

    Event::assertNotDispatched(Login::class);

    // Rien n'a bougé : ni transition, ni `served_at`, ni `seen_frame`, ni
    // participation, ni frappe du palier suivant.
    expect($round->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(EngineFixtures::tier($round, 1)->served_at)->toBeNull()
        ->and(EngineFixtures::tier($round, 2)->serve_token)->toBeNull()
        ->and(SeenFrame::query()->count())->toBe(0)
        ->and(RoundPlayer::query()->where('round_id', $round->id)->count())->toBe(0)
        ->and($game->refresh()->updated_at?->equalTo($gameUpdatedAt))->toBeTrue();
});
