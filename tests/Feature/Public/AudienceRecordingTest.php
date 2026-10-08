<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AudienceDaily;
use App\Models\AudiencePresence;
use App\Models\User;
use App\Support\Audience\AudienceRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Mesure d'audience sans cookie — spec 100 § 10.12, D48 du 01/10
|--------------------------------------------------------------------------
|
| Des compteurs quotidiens, une empreinte du jour jamais écrite telle
| quelle, aucune adresse. `phpunit.xml` coupe la mesure : chaque test
| l'active. Un chargement complet n'est compté qu'à sa preuve JavaScript
| (`audience.seen`, amendé le 08/10) : `audienceSeen()` la joue.
|
*/

const AUDIENCE_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

const AUDIENCE_DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

beforeEach(function (): void {
    $this->withoutVite();
    Config::set('audience.enabled', true);
    Date::setTestNow(CarbonImmutable::parse('2026-10-01 12:00:00'));
});

/**
 * Les compteurs du jour, `métrique/dimension => total`.
 *
 * @return array<string, int>
 */
function audienceCounters(): array
{
    $counters = [];

    foreach (AudienceDaily::query()->orderBy('metric')->orderBy('dimension')->get() as $row) {
        $counters[$row->metric.'/'.$row->dimension] = $row->total;
    }

    return array_filter($counters, static fn (int $total): bool => $total > 0);
}

/** La preuve JavaScript d'une page chargée par ce navigateur. */
function audienceSeen(string $userAgent): void
{
    test()->withHeaders(['User-Agent' => $userAgent])
        ->post(route('audience.seen'))
        ->assertNoContent();
}

it('compte une page vue, un visiteur, une visite, son entrée et sa sortie', function (): void {
    $this->withHeaders(['User-Agent' => AUDIENCE_PHONE])->get('/')->assertOk();
    audienceSeen(AUDIENCE_PHONE);

    expect(audienceCounters())->toMatchArray([
        'pageviews/home' => 1,
        'visitors/' => 1,
        'visits/' => 1,
        'entries/home' => 1,
        'exits/home' => 1,
        'devices/mobile' => 1,
    ])->and(AudiencePresence::query()->count())->toBe(1);
});

it('ne compte un visiteur qu\'une fois par jour, et sa sortie suit sa dernière page', function (): void {
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get('/')->assertOk();
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get('/')->assertOk();
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get(route('legal.notice'))->assertOk();
    audienceSeen(AUDIENCE_DESKTOP);

    $counters = audienceCounters();

    expect($counters['visitors/'])->toBe(1)
        ->and($counters['visits/'])->toBe(1)
        ->and($counters['pageviews/home'])->toBe(2)
        ->and($counters['pageviews/legal.notice'])->toBe(1)
        ->and($counters['entries/home'])->toBe(1)
        ->and($counters['exits/legal.notice'])->toBe(1)
        ->and($counters)->not->toHaveKey('exits/home');
});

it('ouvre une nouvelle visite après trente minutes d\'absence', function (): void {
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get('/')->assertOk();
    audienceSeen(AUDIENCE_DESKTOP);

    $this->travel(AudienceRecorder::VISIT_IDLE_MINUTES + 1)->minutes();
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get('/')->assertOk();
    audienceSeen(AUDIENCE_DESKTOP);

    expect(audienceCounters())->toMatchArray(['visitors/' => 1, 'visits/' => 2, 'exits/home' => 2]);
});

it('n\'écrit jamais l\'adresse ni une empreinte stable d\'un jour à l\'autre', function (): void {
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get('/')->assertOk();
    audienceSeen(AUDIENCE_DESKTOP);

    $dump = json_encode([
        DB::table('audience_daily')->get()->all(),
        DB::table('audience_presence')->get()->all(),
    ]);

    $recorder = app(AudienceRecorder::class);

    expect($dump)->not->toContain('127.0.0.1')
        ->and($dump)->not->toContain('Chrome')
        ->and($recorder->fingerprint('127.0.0.1', AUDIENCE_DESKTOP, '2026-10-01'))
        ->not->toBe($recorder->fingerprint('127.0.0.1', AUDIENCE_DESKTOP, '2026-10-02'));
});

it('ne compte un robot qu\'en robot, et ni un rechargement partiel, ni un préchargement, ni le back-office', function (): void {
    $this->withHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])->get('/')->assertOk();
    $this->withHeaders(['User-Agent' => 'WhatsApp/2.23.20.0 A'])->get('/')->assertOk();
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/120.0 Safari/537.36'])->get('/')->assertOk();
    $this->withHeaders(['User-Agent' => ''])->get('/')->assertOk();
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP, 'Purpose' => 'prefetch'])->get('/')->assertOk();

    $this->actingAs(User::factory()->admin()->create())
        ->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])
        ->get(route('admin.dashboard'))
        ->assertOk();

    audienceSeen(AUDIENCE_DESKTOP);

    expect(audienceCounters())->toBe([
        'bots/empty' => 1,
        'bots/googlebot' => 1,
        'bots/headless' => 1,
        'bots/whatsapp' => 1,
    ]);
});

it('retient le seul domaine d\'un référent externe, jamais un référent interne', function (): void {
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP, 'Referer' => 'https://www.example.org/search?q=secret'])->get('/')->assertOk();
    audienceSeen(AUDIENCE_DESKTOP);
    $this->travel(AudienceRecorder::VISIT_IDLE_MINUTES + 1)->minutes();
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP, 'Referer' => route('legal.notice')])->get('/')->assertOk();
    audienceSeen(AUDIENCE_DESKTOP);

    $counters = audienceCounters();

    expect($counters)->toHaveKey('referrers/example.org')
        ->and($counters['referrers/example.org'])->toBe(1)
        ->and(array_keys($counters))->each->not->toContain('secret')
        ->and(array_filter(array_keys($counters), static fn (string $key): bool => str_starts_with($key, 'referrers/')))->toHaveCount(1);
});

it('ne compte rien quand la mesure est coupée', function (): void {
    Config::set('audience.enabled', false);

    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get('/')->assertOk();
    audienceSeen(AUDIENCE_DESKTOP);

    expect(AudienceDaily::query()->count())->toBe(0)
        ->and(AudiencePresence::query()->count())->toBe(0);
});

it('ne compte un chargement complet qu\'à sa preuve JavaScript', function (): void {
    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get('/')->assertOk();

    expect(audienceCounters())->toBe(['unconfirmed/' => 1])
        ->and(AudiencePresence::query()->count())->toBe(0);

    audienceSeen(AUDIENCE_DESKTOP);

    expect(audienceCounters())->toMatchArray(['pageviews/home' => 1, 'visitors/' => 1])
        ->and(audienceCounters())->not->toHaveKey('unconfirmed/')
        ->and(AudiencePresence::query()->count())->toBe(1);
});

it('compte une visite Inertia sans attendre de preuve', function (): void {
    $this->withHeaders([
        'User-Agent' => AUDIENCE_DESKTOP,
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
    ])->get(route('legal.notice'))->assertOk();

    expect(audienceCounters())->toMatchArray(['pageviews/legal.notice' => 1, 'visitors/' => 1])
        ->and(audienceCounters())->not->toHaveKey('unconfirmed/');
});

it('ne confirme par le signal que ce que le serveur a vu du même navigateur', function (): void {
    audienceSeen(AUDIENCE_DESKTOP);

    expect(audienceCounters())->toBe([]);

    $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->get('/')->assertOk();
    audienceSeen(AUDIENCE_PHONE);
    audienceSeen('Googlebot/2.1 (+http://www.google.com/bot.html)');

    expect(audienceCounters())->toBe(['unconfirmed/' => 1]);

    audienceSeen(AUDIENCE_DESKTOP);
    audienceSeen(AUDIENCE_DESKTOP);

    expect(audienceCounters()['pageviews/home'])->toBe(1);
});

it('répond au signal sans cookie, sans cache et sans indexation', function (): void {
    $response = $this->withHeaders(['User-Agent' => AUDIENCE_DESKTOP])->post(route('audience.seen'));

    $response->assertNoContent();

    expect($response->headers->getCookies())->toBe([])
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
});
