<?php

use App\Enums\ContentAvailability;
use App\Enums\Locale;
use App\Http\Middleware\RobotsDirectives;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\User;
use App\Support\Frames\FrameImageResponse;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Aperçu de l'image signalée — D63 du 07/10, amendé le 07/10
|--------------------------------------------------------------------------
|
| La page `/report` montre l'image désignée, et la route
| `content-report.frame` en sert les octets, au SEUL demandeur — compte ou
| `player_token` — qui a participé à une manche révélée où elle a été
| servie. Sinon : aucune URL dans la page, 404 uniforme sur la route.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    Storage::fake(FrameStoragePrefix::DISK);
});

/**
 * Un film publié, une image publiée servie au palier 1 d'une manche à l'état
 * voulu, et la manche.
 *
 * @return array{0: Movie, 1: Frame, 2: Round}
 */
function reportFrameServed(string $roundState = 'completed'): array
{
    $movie = Movie::factory()->published()->create(['tmdb_id' => 5151]);
    $frame = Frame::factory()->for($movie)->published()->create();
    $round = Round::factory()->forMovie($movie)->{$roundState}()->create();
    RoundTier::factory()->for($round)->atTier(1)->served($frame)->create();

    return [$movie, $frame, $round];
}

/** Un siège d'invité tenu par un jeton neuf, présenté par le cookie. */
function reportFrameSeat(TestCase $test): Player
{
    $token = PlayerToken::mint(Locale::French);
    $seat = Player::factory()->create(['player_token_hash' => $token->hash()]);

    LobbyWrites::actAs($test, $token);

    return $seat;
}

function reportFramePageUrl(Movie $movie, Frame $frame): string
{
    return route('content-report.create', ['movie' => $movie->tmdb_id, 'frame' => $frame->public_id]);
}

function reportFrameImageUrl(Frame $frame): string
{
    return route('content-report.frame', ['publicId' => $frame->public_id], false);
}

it('montre l’image au siège d’invité qui l’a vue dans une manche révélée, et en sert le dérivé de jeu sans cache ni indexation', function (): void {
    [$movie, $frame, $round] = reportFrameServed();
    RoundPlayer::factory()->forRound($round, reportFrameSeat($this))->create();

    $this->get(reportFramePageUrl($movie, $frame))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('report/create')
            ->where('frame', ['publicId' => $frame->public_id, 'imageUrl' => reportFrameImageUrl($frame)]));

    $response = $this->get(reportFrameImageUrl($frame));

    $response->assertOk()
        ->assertHeader('Content-Type', FrameImageResponse::CONTENT_TYPE)
        ->assertHeader('X-Robots-Tag', FrameImageResponse::ROBOTS)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Disposition');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->streamedContent())->toBe(Storage::disk(FrameStoragePrefix::DISK)->get((string) $frame->game_path));
});

it('montre l’image en révélation, avant la clôture de la manche', function (): void {
    [$movie, $frame, $round] = reportFrameServed('revealing');
    RoundPlayer::factory()->forRound($round, reportFrameSeat($this))->create();

    $this->get(reportFramePageUrl($movie, $frame))
        ->assertInertia(fn (Assert $page) => $page->where('frame.imageUrl', reportFrameImageUrl($frame)));
    $this->get(reportFrameImageUrl($frame))->assertOk();
});

it('montre l’image au compte connecté dont un siège a participé, sans aucun jeton de joueur', function (): void {
    [$movie, $frame, $round] = reportFrameServed();
    $user = User::factory()->create();
    $seat = Player::factory()->create(['user_id' => $user->id, 'player_token_hash' => null]);
    RoundPlayer::factory()->forRound($round, $seat)->create();

    $this->actingAs($user)->get(reportFramePageUrl($movie, $frame))
        ->assertInertia(fn (Assert $page) => $page->where('frame.imageUrl', reportFrameImageUrl($frame)));
    $this->actingAs($user)->get(reportFrameImageUrl($frame))->assertOk();
});

it('ne montre rien à qui n’a pas participé à la manche, compte, siège ou visiteur, et répond la même 404', function (): void {
    [$movie, $frame, $round] = reportFrameServed();
    RoundPlayer::factory()->forRound($round, Player::factory()->create())->create();

    // Visiteur sans jeton ni compte.
    $this->get(reportFramePageUrl($movie, $frame))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('frame', ['publicId' => $frame->public_id, 'imageUrl' => null]));
    $anonymous = $this->get(reportFrameImageUrl($frame));
    $anonymous->assertNotFound()
        ->assertHeader('X-Robots-Tag', FrameImageResponse::ROBOTS);
    expect($anonymous->getContent())->toBe('');

    // Un siège d'une autre partie.
    reportFrameSeat($this);
    $this->get(reportFramePageUrl($movie, $frame))
        ->assertInertia(fn (Assert $page) => $page->where('frame.imageUrl', null));
    $this->get(reportFrameImageUrl($frame))->assertNotFound();

    // Un compte sans siège dans la manche.
    $this->actingAs(User::factory()->create())->get(reportFrameImageUrl($frame))->assertNotFound();

    // Une image inconnue.
    $this->get(route('content-report.frame', ['publicId' => 'ZZZZZZZZZZZZ']))->assertNotFound();
});

it('ne montre pas une image servie dans une manche pas encore révélée, ni dans une manche annulée', function (string $state): void {
    [$movie, $frame, $round] = reportFrameServed($state);
    RoundPlayer::factory()->forRound($round, reportFrameSeat($this))->create();

    $this->get(reportFramePageUrl($movie, $frame))
        ->assertInertia(fn (Assert $page) => $page->where('frame.imageUrl', null));
    $this->get(reportFrameImageUrl($frame))->assertNotFound();
})->with(['running', 'cancelled']);

it('ne montre pas une autre variante du film que le salon n’a pas vue, ni un palier jamais ouvert', function (): void {
    [$movie, $frame, $round] = reportFrameServed();
    RoundPlayer::factory()->forRound($round, reportFrameSeat($this))->create();
    $other = Frame::factory()->for($movie)->published()->create();
    $unopened = Frame::factory()->for($movie)->published()->create();
    RoundTier::factory()->for($round)->atTier(2)->forFrame($unopened)->create(['served_frame_id' => $unopened->id]);

    foreach ([$other, $unopened] as $variant) {
        $this->get(reportFramePageUrl($movie, $variant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('frame.imageUrl', null));
        $this->get(reportFrameImageUrl($variant))->assertNotFound();
    }

    $this->get(reportFrameImageUrl($frame))->assertOk();
});

it('ne sert jamais une image ou un film suspendu ou retiré, même au joueur qui l’a vue', function (string $target, ContentAvailability $availability): void {
    [$movie, $frame, $round] = reportFrameServed();
    RoundPlayer::factory()->forRound($round, reportFrameSeat($this))->create();

    ($target === 'frame' ? $frame : $movie)->forceFill(['availability' => $availability])->save();

    $this->get(reportFrameImageUrl($frame))->assertNotFound();

    if ($availability === ContentAvailability::Suspended) {
        $this->get(reportFramePageUrl($movie, $frame))
            ->assertInertia(fn (Assert $page) => $page->where('frame.imageUrl', null));
    }
})->with([
    'image suspendue' => ['frame', ContentAvailability::Suspended],
    'image retirée' => ['frame', ContentAvailability::Withdrawn],
    'film suspendu' => ['movie', ContentAvailability::Suspended],
    'film retiré' => ['movie', ContentAvailability::Withdrawn],
]);

it('montre encore une image dépubliée depuis la manche au joueur qui l’a vue', function (): void {
    [$movie, $frame, $round] = reportFrameServed();
    RoundPlayer::factory()->forRound($round, reportFrameSeat($this))->create();
    $frame->forceFill(['availability' => ContentAvailability::Unpublished])->save();

    $this->get(reportFrameImageUrl($frame))->assertOk();
});

it('ne sert jamais le master, ni des fichiers effacés', function (): void {
    [, $frame, $round] = reportFrameServed();
    RoundPlayer::factory()->forRound($round, reportFrameSeat($this))->create();

    $gamePath = (string) $frame->game_path;

    $frame->forceFill(['game_path' => (string) $frame->master_path])->save();
    $this->get(reportFrameImageUrl($frame))->assertNotFound();

    $frame->forceFill(['game_path' => $gamePath, 'files_deleted_at' => now()])->save();
    $this->get(reportFrameImageUrl($frame))->assertNotFound();
});

/**
 * Une manche révélée dont le palier 1 s'ouvre il y a 30 s et le palier 3 il
 * y a 10 s, chacun sur sa variante, et un siège d'invité tenu par le cookie,
 * participant de la manche, aux attributs voulus.
 *
 * @param  array<string, mixed>  $seat
 * @return array{0: Movie, 1: Frame, 2: Frame}
 */
function reportFrameTwoTiers(TestCase $test, array $seat): array
{
    $movie = Movie::factory()->published()->create(['tmdb_id' => 5252]);
    $first = Frame::factory()->for($movie)->published()->create();
    $third = Frame::factory()->for($movie)->published()->create();
    $round = Round::factory()->forMovie($movie)->completed()->create();
    RoundTier::factory()->for($round)->atTier(1)->served($first)->create(['served_at' => now()->subSeconds(30)]);
    RoundTier::factory()->for($round)->atTier(3)->served($third)->create(['served_at' => now()->subSeconds(10)]);

    $token = PlayerToken::mint(Locale::French);
    $player = Player::factory()->create(['player_token_hash' => $token->hash(), ...$seat]);
    RoundPlayer::factory()->forRound($round, $player)->create();
    LobbyWrites::actAs($test, $token);

    return [$movie, $first, $third];
}

it('un siège expulsé pendant le palier 1 ne voit pas l’image du palier 3, ni aucune autre', function (): void {
    $kickedAt = now()->subSeconds(20);
    [$movie, $first, $third] = reportFrameTwoTiers($this, ['left_at' => $kickedAt, 'kicked_at' => $kickedAt]);

    foreach ([$third, $first] as $frame) {
        $this->get(reportFramePageUrl($movie, $frame))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('frame', ['publicId' => $frame->public_id, 'imageUrl' => null]));
        $this->get(reportFrameImageUrl($frame))->assertNotFound();
    }
});

it('un siège parti avant l’ouverture du palier ne voit pas son image', function (): void {
    [$movie, , $third] = reportFrameTwoTiers($this, ['left_at' => now()->subSeconds(20)]);

    $this->get(reportFramePageUrl($movie, $third))
        ->assertInertia(fn (Assert $page) => $page->where('frame.imageUrl', null));
    $this->get(reportFrameImageUrl($third))->assertNotFound();
});

it('un siège parti après l’ouverture du palier voit encore son image', function (): void {
    [$movie, $first] = reportFrameTwoTiers($this, ['left_at' => now()->subSeconds(20)]);

    $this->get(reportFramePageUrl($movie, $first))
        ->assertInertia(fn (Assert $page) => $page->where('frame.imageUrl', reportFrameImageUrl($first)));
    $this->get(reportFrameImageUrl($first))->assertOk();
});

it('débite l’aperçu sur son propre seau, jamais sur celui des images de jeu', function (): void {
    $route = app('router')->getRoutes()->getByName('content-report.frame');

    expect($route?->gatherMiddleware())->toContain('throttle:content-report-frame')
        ->not->toContain('throttle:frame-serve');
});

it('ne frappe aucun jeton de joueur à la lecture de la page ni de l’image', function (): void {
    [$movie, $frame] = reportFrameServed();

    $this->get(reportFramePageUrl($movie, $frame))->assertCookieMissing(PlayerTokenCookie::NAME);
    $this->get(reportFrameImageUrl($frame))->assertCookieMissing(PlayerTokenCookie::NAME);
});

it('ne porte pas le drapeau d’indexation sur la route de l’aperçu', function (): void {
    $route = app('router')->getRoutes()->getByName('content-report.frame');

    expect($route)->not->toBeNull()
        ->and($route?->defaults)->not->toHaveKey(RobotsDirectives::ROUTE_FLAG);
});
