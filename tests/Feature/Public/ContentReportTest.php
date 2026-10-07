<?php

use App\Enums\ContentReportReason;
use App\Enums\ContentReportStatus;
use App\Enums\Locale;
use App\Http\Controllers\Game\ContentReportController;
use App\Http\Middleware\RobotsDirectives;
use App\Models\ContentReport;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\Player;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Identity\PlayerToken;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Signaler un film ou une image vue en jeu — D63 du 07/10
|--------------------------------------------------------------------------
|
| La page publique `/report?movie=<tmdb_id>&frame=<public_id>` : tout joueur,
| invité compris, signale une fois une cible, sans effet automatique. Ni
| compte ni siège : la page s'affiche, l'envoi est refusé. Aucun
| identifiant interne dans l'URL ni dans la page, l'image seulement à qui
| l'a vue (`ContentReportFrameTest`),
| et `noindex` permanent.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    Storage::fake(FrameStoragePrefix::DISK);
});

/**
 * Un film publié et une image publiée de sa banque.
 *
 * @return array{0: Movie, 1: Frame}
 */
function contentReportTarget(): array
{
    $movie = Movie::factory()->published()->create(['tmdb_id' => 4242]);
    $frame = Frame::factory()->for($movie)->published()->create();

    return [$movie, $frame];
}

/**
 * Un siège d'invité tenu par un jeton neuf, présenté par le cookie.
 */
function contentReportSeat(TestCase $test): Player
{
    $token = PlayerToken::mint(Locale::French);
    $seat = Player::factory()->create(['player_token_hash' => $token->hash()]);

    LobbyWrites::actAs($test, $token);

    return $seat;
}

/**
 * L'envoi du formulaire.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function contentReportSend(TestCase $test, array $body): TestResponse
{
    return $test->from(route('content-report.create', ['movie' => $body['movie'] ?? null]))
        ->post(route('content-report.store'), $body);
}

it('affiche la page avec le titre localisé du film, l’image désignée et les motifs, sans aucun identifiant interne', function (): void {
    [$movie, $frame] = contentReportTarget();
    contentReportSeat($this);

    $response = $this->get(route('content-report.create', ['movie' => $movie->tmdb_id, 'frame' => $frame->public_id]));

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page
            ->component('report/create')
            ->where('movie.tmdb', $movie->tmdb_id)
            ->where('movie.year', $movie->release_year)
            ->has('movie.title')
            ->where('frame.publicId', $frame->public_id)
            ->where('scopes', ['frame', 'movie'])
            ->has('reasons', count(ContentReportReason::cases()))
            ->where('reasons.1', ['value' => 'title_visible', 'frameOnly' => true])
            ->where('commentMaxLength', ContentReport::COMMENT_MAX_LENGTH)
            ->where('canReport', true)
            ->where('alreadyReported', ['movie' => false, 'frame' => false]));

    $props = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);

    expect($props)->not->toContain('"id"')
        ->and($props)->not->toContain((string) $frame->game_path);
});

it('ne propose que la portée du film entier sans image désignée', function (): void {
    [$movie] = contentReportTarget();

    $this->get(route('content-report.create', ['movie' => $movie->tmdb_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('report/create')
            ->where('frame', null)
            ->where('scopes', ['movie'])
            ->where('canReport', false)
            ->where('alreadyReported', ['movie' => false, 'frame' => null]));
});

it('répond 404 pour un film inconnu, retiré ou jamais publié, et pour une image étrangère au film ou retirée', function (): void {
    [$movie, $frame] = contentReportTarget();
    $withdrawn = Movie::factory()->withdrawn()->create(['first_published_at' => now()]);
    $draft = Movie::factory()->create();
    $foreign = Frame::factory()->published()->create();
    $withdrawnFrame = Frame::factory()->for($movie)->published()->withdrawn()->create();

    $cases = [
        ['movie' => 999_999],
        ['movie' => 'abc'],
        [],
        ['movie' => $withdrawn->tmdb_id],
        ['movie' => $draft->tmdb_id],
        ['movie' => $movie->tmdb_id, 'frame' => $foreign->public_id],
        ['movie' => $movie->tmdb_id, 'frame' => $withdrawnFrame->public_id],
        ['movie' => $movie->tmdb_id, 'frame' => 'ZZZZZZZZZZZZ'],
    ];

    foreach ($cases as $query) {
        $this->get(route('content-report.create', $query))->assertNotFound();
    }

    expect($frame->public_id)->toHaveLength(12);
});

it('reste signalable pour un film dépublié depuis la manche', function (): void {
    $movie = Movie::factory()->unpublished()->create();

    $this->get(route('content-report.create', ['movie' => $movie->tmdb_id]))->assertOk();
});

it('enregistre le signalement d’une image par un siège d’invité, sans aucun effet sur l’image', function (): void {
    [$movie, $frame] = contentReportTarget();
    $seat = contentReportSeat($this);

    contentReportSend($this, [
        'movie' => $movie->tmdb_id,
        'frame' => $frame->public_id,
        'scope' => 'frame',
        'reason' => 'title_visible',
        'comment' => '  Le titre est sur l’affiche au fond.  ',
    ])->assertRedirect(route('content-report.create', ['movie' => $movie->tmdb_id, 'frame' => $frame->public_id]))
        ->assertSessionHas('inertia.flash_data.'.ContentReportController::FLASH_KEY, 'sent');

    $report = ContentReport::query()->sole();

    expect($report->movie_id)->toBe($movie->id)
        ->and($report->frame_id)->toBe($frame->id)
        ->and($report->target_key)->toBe('f:'.$frame->id)
        ->and($report->reason)->toBe(ContentReportReason::TitleVisible)
        ->and($report->comment)->toBe('Le titre est sur l’affiche au fond.')
        ->and($report->reporter_player_id)->toBe($seat->id)
        ->and($report->reporter_user_id)->toBeNull()
        ->and($report->status)->toBe(ContentReportStatus::Open)
        ->and($frame->fresh()?->availability)->toBe($frame->availability)
        ->and($movie->fresh()?->availability)->toBe($movie->availability);
});

it('enregistre le signalement du film entier par un compte, même quand une image est désignée', function (): void {
    [$movie, $frame] = contentReportTarget();
    $user = User::factory()->create();

    $this->actingAs($user);

    contentReportSend($this, [
        'movie' => $movie->tmdb_id,
        'frame' => $frame->public_id,
        'scope' => 'movie',
        'reason' => 'wrong_movie',
        'comment' => '',
    ])->assertRedirect();

    $report = ContentReport::query()->sole();

    expect($report->frame_id)->toBeNull()
        ->and($report->target_key)->toBe('m:'.$movie->id)
        ->and($report->reporter_user_id)->toBe($user->id)
        ->and($report->reporter_player_id)->toBeNull()
        ->and($report->comment)->toBeNull();
});

it('refuse l’envoi en 403 sans compte ni siège, et n’écrit rien', function (): void {
    [$movie] = contentReportTarget();

    contentReportSend($this, ['movie' => $movie->tmdb_id, 'scope' => 'movie', 'reason' => 'other'])
        ->assertForbidden();

    expect(ContentReport::query()->count())->toBe(0);
});

it('limite l’envoi par compte connecté, sans partager le seau de l’adresse entre deux comptes', function (): void {
    config()->set('game.content_report.reports_per_hour', 1);
    [$movie] = contentReportTarget();
    $other = Movie::factory()->published()->create();
    $body = ['scope' => 'movie', 'reason' => 'other'];

    // Deux comptes sans siège, donc sans jeton, derrière la même adresse.
    $this->actingAs(User::factory()->create());
    contentReportSend($this, [...$body, 'movie' => $movie->tmdb_id])->assertRedirect();
    contentReportSend($this, [...$body, 'movie' => $other->tmdb_id])->assertTooManyRequests();

    $this->actingAs(User::factory()->create());
    contentReportSend($this, [...$body, 'movie' => $movie->tmdb_id])->assertRedirect();

    expect(ContentReport::query()->count())->toBe(2);
});

it('dédoublonne un second envoi du même signaleur sur la même cible', function (): void {
    [$movie] = contentReportTarget();
    contentReportSeat($this);

    $body = ['movie' => $movie->tmdb_id, 'scope' => 'movie', 'reason' => 'offensive'];

    contentReportSend($this, $body)->assertRedirect();
    contentReportSend($this, [...$body, 'reason' => 'other'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.'.ContentReportController::FLASH_KEY, 'already_reported');

    expect(ContentReport::query()->count())->toBe(1);

    $this->get(route('content-report.create', ['movie' => $movie->tmdb_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('report/create')
            ->where('alreadyReported.movie', true));
});

it('refuse un motif propre à l’image pour le film entier, et une portée image sans image', function (): void {
    [$movie, $frame] = contentReportTarget();
    contentReportSeat($this);

    foreach (['title_visible', 'wrong_level', 'poor_quality'] as $reason) {
        contentReportSend($this, ['movie' => $movie->tmdb_id, 'frame' => $frame->public_id, 'scope' => 'movie', 'reason' => $reason])
            ->assertSessionHasErrors('reason');
    }

    contentReportSend($this, ['movie' => $movie->tmdb_id, 'scope' => 'frame', 'reason' => 'wrong_movie'])
        ->assertSessionHasErrors('scope');

    contentReportSend($this, ['movie' => $movie->tmdb_id, 'scope' => 'movie', 'reason' => 'inconnu'])
        ->assertSessionHasErrors('reason');

    contentReportSend($this, [
        'movie' => $movie->tmdb_id,
        'scope' => 'movie',
        'reason' => 'other',
        'comment' => str_repeat('a', ContentReport::COMMENT_MAX_LENGTH + 1),
    ])->assertSessionHasErrors('comment');

    expect(ContentReport::query()->count())->toBe(0);
});

it('répond 404 à l’envoi pour une cible introuvable', function (): void {
    [$movie] = contentReportTarget();
    contentReportSeat($this);

    contentReportSend($this, ['movie' => 999_999, 'scope' => 'movie', 'reason' => 'other'])->assertNotFound();
    contentReportSend($this, ['movie' => $movie->tmdb_id, 'frame' => 'ZZZZZZZZZZZZ', 'scope' => 'frame', 'reason' => 'other'])->assertNotFound();

    expect(ContentReport::query()->count())->toBe(0);
});

it('ne porte jamais le drapeau d’indexation sur les routes du signalement', function (): void {
    foreach (['content-report.create', 'content-report.store'] as $name) {
        $route = app('router')->getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route?->defaults)->not->toHaveKey(RobotsDirectives::ROUTE_FLAG);
    }
});
