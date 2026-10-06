<?php

use App\Models\Player;
use App\Models\Room;
use App\Models\Visitor;
use App\Settings\RoomSettings;
use App\Support\Visitor\ConsentCookie;
use App\Support\Visitor\UserAgentFamily;
use App\Support\Visitor\VisitorTracker;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Le visiteur consentant — D62 du 06/10
|--------------------------------------------------------------------------
|
| Rien sans consentement : ni cookie `visitor`, ni ligne `visitor`, ni lien
| d'un siège à un visiteur, ni appareil. Accepter dépose l'identifiant et la
| preuve du consentement ; refuser — ou retirer — supprime le visiteur et
| délie ses sièges.
|
*/

const VISITOR_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    config()->set('game.room.joins_per_minute', 1000);
});

function visitorConsentPost(string $choice, array $cookies = []): TestResponse
{
    $test = test();

    foreach ($cookies as $name => $value) {
        $test->withCookie($name, $value);
    }

    return $test->from(route('home'))->post(route('consent.store'), ['choice' => $choice]);
}

/** Un salon au lobby et son hôte. */
function visitorConsentRoom(): Room
{
    $room = Room::factory()->withSettings(RoomSettings::defaults())->create();
    $host = Player::factory()->for($room)->withNickname('Hôte')->create();
    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

    return $room->refresh();
}

it('ne reconnaît personne tant que le visiteur n\'a pas répondu, et la bannière s\'affiche', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('consent', null));

    $room = visitorConsentRoom();

    $this->withHeader('User-Agent', VISITOR_UA)
        ->post(route('room.join', $room), SeatEntry::form('Zoé'))
        ->assertSessionHasNoErrors();

    $seat = Player::query()->where('room_id', $room->id)->where('nickname', 'Zoé')->sole();

    expect($seat->visitor_id)->toBeNull()
        ->and($seat->device_class)->toBeNull()
        ->and(Visitor::query()->count())->toBe(0);
});

it('accepter dépose l\'identifiant et la preuve du consentement, puis relie les sièges et l\'appareil', function (): void {
    $response = visitorConsentPost('accepted')->assertRedirect(route('home'));

    $visitor = Visitor::query()->sole();
    $token = $response->getCookie(VisitorTracker::COOKIE)?->getValue();

    expect($visitor->consent_version)->toBe(ConsentCookie::VERSION)
        ->and($visitor->consented_at)->not->toBeNull()
        ->and($token)->toBeString()
        ->and($visitor->token_hash)->toBe(hash('sha256', (string) $token))
        ->and($response->getCookie(ConsentCookie::NAME)?->getValue())->toBe(ConsentCookie::VERSION.':accepted');

    $room = visitorConsentRoom();

    $this->withCookie(ConsentCookie::NAME, ConsentCookie::VERSION.':accepted')
        ->withCookie(VisitorTracker::COOKIE, (string) $token)
        ->withHeader('User-Agent', VISITOR_UA)
        ->post(route('room.join', $room), SeatEntry::form('Zoé'))
        ->assertSessionHasNoErrors();

    $seat = Player::query()->where('room_id', $room->id)->where('nickname', 'Zoé')->sole();

    expect($seat->visitor_id)->toBe($visitor->id)
        ->and($seat->device_class)->toBe('mobile')
        ->and($seat->browser_family)->toBe('safari')
        ->and($seat->os_family)->toBe('ios');

    $this->withCookie(ConsentCookie::NAME, ConsentCookie::VERSION.':accepted')
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('consent', 'accepted'));
});

it('un cookie visitor sans accord valide ne relie aucun siège', function (): void {
    $token = visitorConsentPost('accepted')->getCookie(VisitorTracker::COOKIE)?->getValue();
    $room = visitorConsentRoom();

    // Accord d'une version périmée de la bannière : comme aucun accord.
    $this->withCookie(ConsentCookie::NAME, '0:accepted')
        ->withCookie(VisitorTracker::COOKIE, (string) $token)
        ->post(route('room.join', $room), SeatEntry::form('Zoé'))
        ->assertSessionHasNoErrors();

    expect(Player::query()->where('room_id', $room->id)->where('nickname', 'Zoé')->sole()->visitor_id)->toBeNull();
});

it('refuser ou retirer son accord supprime le visiteur et délie ses sièges', function (): void {
    $token = (string) visitorConsentPost('accepted')->getCookie(VisitorTracker::COOKIE)?->getValue();
    $visitor = Visitor::query()->sole();
    $seat = Player::factory()->for(visitorConsentRoom())->create(['visitor_id' => $visitor->id, 'device_class' => 'desktop']);

    $response = visitorConsentPost('refused', [VisitorTracker::COOKIE => $token]);

    expect(Visitor::query()->count())->toBe(0)
        ->and($seat->refresh()->visitor_id)->toBeNull()
        ->and($response->getCookie(ConsentCookie::NAME)?->getValue())->toBe(ConsentCookie::VERSION.':refused')
        ->and($response->getCookie(VisitorTracker::COOKIE, decrypt: false)?->getExpiresTime())->toBeLessThan(time());
});

it('réduit l\'en-tête du navigateur à trois familles grossières', function (string $userAgent, array $expected): void {
    expect([UserAgentFamily::device($userAgent), UserAgentFamily::browser($userAgent), UserAgentFamily::os($userAgent)])->toBe($expected);
})->with([
    'iPhone Safari' => [VISITOR_UA, ['mobile', 'safari', 'ios']],
    'Windows Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0', ['desktop', 'edge', 'windows']],
    'Android Chrome' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36', ['mobile', 'chrome', 'android']],
    'Mac Firefox' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:130.0) Gecko/20100101 Firefox/130.0', ['desktop', 'firefox', 'macos']],
    'inconnu' => ['curl', ['desktop', 'other', 'other']],
]);
