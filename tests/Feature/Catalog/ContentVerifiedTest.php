<?php

use App\Actions\Curation\VerifyMovieContent;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Movie;
use App\Models\User;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Coche « contenu vérifié, pas de classification restrictive » — spec 20 § 4.4
|--------------------------------------------------------------------------
|
| Question 12 : pour un film `unrated_pending`, un curateur déclare, motif
| obligatoire, qu'aucune classification restrictive ne le frappe. La preuve
| s'écrit avec l'état qu'elle justifie — `content_flag = clear`, auteur,
| horodatage, ligne `movie.content_verified` — dans la même transaction.
| Pas de décoche. Et `blocked` ne se lève par AUCUN geste (décision 12).
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    Queue::fake();
});

/**
 * La coche, postée depuis la fiche du film.
 */
function contentVerifiedPost(Movie $movie, User $user, ?string $reason): TestResponse
{
    return test()
        ->actingAs($user)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.content_verified', ['movie' => $movie->id]), ['reason' => $reason]);
}

test('cocher le contenu vérifié exige un motif et écrit movie.content_verified dans la même transaction', function (): void {
    $curator = User::factory()->curator()->create();
    $movie = Movie::factory()->create();

    expect($movie->content_flag)->toBe(ContentFlag::UnratedPending);

    // Sans motif, ou un motif fait d'espaces : refusé sous le champ, rien
    // n'est écrit.
    foreach ([null, '', '   '] as $empty) {
        contentVerifiedPost($movie, $curator, $empty)
            ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
            ->assertSessionHasErrors('reason');
    }

    expect($movie->refresh()->content_flag)->toBe(ContentFlag::UnratedPending)
        ->and($movie->content_verified_by_id)->toBeNull()
        ->and(AdminAction::query()->count())->toBe(0);

    // Un motif plus long que la colonne du journal est refusé aussi.
    contentVerifiedPost($movie, $curator, str_repeat('x', AdminAction::REASON_MAX_LENGTH + 1))
        ->assertSessionHasErrors('reason');

    $reason = 'Aucune classification FR ni US connue ; film d’animation tout public vérifié sur la fiche du distributeur.';

    contentVerifiedPost($movie, $curator, $reason)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $movie->refresh();

    expect($movie->content_flag)->toBe(ContentFlag::Clear)
        ->and($movie->content_verified_by_id)->toBe($curator->id)
        ->and($movie->content_verified_at)->not->toBeNull()
        ->and($movie->availability)->toBe(ContentAvailability::Draft);

    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::MovieContentVerified)
        ->and($line->subject_id)->toBe($movie->id)
        ->and($line->actor_id)->toBe($curator->id)
        ->and($line->actor_name)->toBe($curator->real_name)
        ->and($line->reason)->toBe($reason);

    // Pas de décoche : une fois vérifié, le geste n'est plus offert, et la
    // garde le refuse.
    expect(Gate::forUser($curator)->allows('verifyContent', $movie))->toBeFalse();

    contentVerifiedPost($movie, $curator, $reason)->assertForbidden();

    // Même transaction : une panne à l'écriture de la ligne du journal
    // annule la coche.
    $other = Movie::factory()->create();

    Event::listen('eloquent.creating: '.AdminAction::class, function (): never {
        throw new RuntimeException('Panne simulée du journal.');
    });

    expect(fn () => app(VerifyMovieContent::class)->handle($other, $curator, $reason))
        ->toThrow(RuntimeException::class, 'Panne simulée');

    $other->refresh();

    expect($other->content_flag)->toBe(ContentFlag::UnratedPending)
        ->and($other->content_verified_by_id)->toBeNull()
        ->and($other->content_verified_at)->toBeNull();
});

test('un contenu bloqué ne se lève par aucun geste', function (): void {
    $curator = User::factory()->curator()->create();
    $admin = User::factory()->admin()->create();

    // Un film bloqué, par ailleurs complet : trois niveaux en jeu, des clés.
    $movie = Movie::factory()->contentFlag(ContentFlag::Blocked)->create();
    FrameBank::movieWith($movie, [1, 3, 5]);
    (new AnswerKeyProjector)->project($movie);

    foreach (['curateur' => $curator, 'administrateur' => $admin] as $label => $user) {
        // Aucune coche, pour aucun rôle : la garde refuse avant tout.
        expect(Gate::forUser($user)->allows('verifyContent', $movie))->toBeFalse($label);

        contentVerifiedPost($movie, $user, 'Vérifié à la main.')->assertForbidden();

        expect(fn () => app(VerifyMovieContent::class)->handle($movie, $user, 'Vérifié à la main.'))
            ->toThrow(AuthorizationException::class);

        // La publication refuse, condition nommée, sans rien écrire.
        test()->actingAs($user)
            ->from(route('admin.catalog.show', ['movie' => $movie->id]))
            ->post(route('admin.catalog.publish', ['movie' => $movie->id]), [
                'ambiguity_digest' => app(AmbiguityPreview::class)->forPublication($movie)->digest(),
            ])
            ->assertSessionHasErrors([
                'movie' => (string) __('admin.movie.publish.content_not_clear', [], Locale::French->value),
            ]);

        // La fiche ne propose pas la coche, et dit pourquoi.
        test()->actingAs($user)
            ->get(route('admin.catalog.show', ['movie' => $movie->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('movie.content_flag', ContentFlag::Blocked->value)
                ->where('abilities.verifyContent', false)
                ->where('publication.blockers', ['content_not_clear']));
    }

    expect(Lang::hasForLocale('admin.movie.content_verified.blocked_notice', Locale::French->value))->toBeTrue();

    // Écarté puis présenté de nouveau : toujours bloqué, toujours refusé.
    test()->actingAs($curator)
        ->post(route('admin.catalog.unpublish', ['movie' => $movie->id]), ['reason' => 'Classification restrictive.'])
        ->assertSessionHasNoErrors();

    contentVerifiedPost($movie, $admin, 'Vérifié à la main.')->assertForbidden();

    expect($movie->refresh()->content_flag)->toBe(ContentFlag::Blocked)
        ->and($movie->content_verified_by_id)->toBeNull()
        ->and($movie->availability)->toBe(ContentAvailability::Unpublished)
        ->and(AdminAction::query()->where('action', AdminActionType::MovieContentVerified->value)->exists())->toBeFalse()
        ->and(AdminAction::query()->where('action', AdminActionType::MoviePublished->value)->exists())->toBeFalse();

    // Et aucune route du back-office n'est là pour le lever : la seule qui
    // écrit le drapeau est la coche, gardée par `verifyContent`.
    $writers = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.catalog.')
            && str_contains($route->uri(), 'content'))
        ->map(fn ($route): string => (string) $route->getName())
        ->values()
        ->all();

    expect($writers)->toBe(['admin.catalog.content_verified']);
});
