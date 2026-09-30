<?php

use App\Actions\Curation\ChangeFrameLevel;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameProcessingState;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Changer le niveau d'une image — spec 20 § 5.7, arbitrage B3
|--------------------------------------------------------------------------
|
| Une image publiée sort du jeu et repasse en revue, dans la transaction qui
| écrit son niveau, la ligne `frame.unpublished` et la nouvelle projection :
| la revue ne porte pas le niveau, et les items applicables en dépendent. Le
| motif de cette ligne est écrit par le serveur, en TEXTE.
|
*/

beforeEach(function (): void {
    // Les images publiées de fixture écrivent leurs octets : sur un disque
    // faux, jamais dans la racine réelle de `frames`.
    Storage::fake(FrameStoragePrefix::DISK);

    // Changer un niveau ne distribue aucun traitement.
    Queue::fake();
});

/**
 * L'envoi du nouveau niveau, posté depuis la fiche du film.
 */
function frameLevelPatch(Frame $frame, FrameLevel $level, ?User $curator = null): TestResponse
{
    return test()
        ->actingAs($curator ?? User::factory()->curator()->create())
        ->from(route('admin.catalog.show', ['movie' => $frame->movie_id]))
        ->patch(route('admin.catalog.frames.level.update', ['movie' => $frame->movie_id, 'frame' => $frame->id]), [
            'frame_level' => $level->value,
        ]);
}

/**
 * Un texte du back-office, résolu en français — sa clé vérifiée d'abord : une
 * clé absente se rendrait telle quelle des deux côtés d'une comparaison.
 */
function frameLevelText(string $key): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, [], Locale::French->value);
}

test('changer le niveau d\'une frame publiée la renvoie en revue et recalcule la couverture dans la même transaction', function (): void {
    $curator = User::factory()->curator()->create();
    [$movie, [$level1, $level3, $level5]] = FrameBank::publishedMovie([1, 3, 5]);
    $hash = $level3->published_hash;

    // La revue précède strictement le geste, comme dans la vraie vie.
    $this->travel(5)->minutes();

    frameLevelPatch($level3, FrameLevel::Level4, $curator)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $level3->refresh();

    // Nouveau niveau, hors du jeu, mêmes octets.
    expect($level3->frame_level)->toBe(FrameLevel::Level4)
        ->and($level3->availability)->toBe(ContentAvailability::Unpublished)
        ->and($level3->processing_state)->toBe(FrameProcessingState::Ready)
        ->and($level3->published_hash)->toBe($hash)
        ->and($level3->isServable())->toBeFalse();

    // Renvoyée en revue (§ 7.3, « À revoir ») : prête, jamais écartée — elle
    // a été publiée —, et aucune revue n'est postérieure à sa sortie du jeu,
    // datée par `availability_changed_at`.
    expect($level3->first_published_at)->not->toBeNull()
        ->and($level3->availability_changed_at)->not->toBeNull()
        ->and($level3->reviewed_at?->lessThan($level3->availability_changed_at))->toBeTrue()
        ->and($level3->reviews()->where('reviewed_at', '>=', $level3->availability_changed_at)->exists())->toBeFalse();

    // La ligne du journal.
    $line = AdminAction::query()->where('action', AdminActionType::FrameUnpublished->value)->sole();

    expect($line->subject_id)->toBe($level3->id)
        ->and($line->actor_id)->toBe($curator->id);

    // La couverture recalculée : ni le niveau 3 quitté, ni le niveau 4 visé,
    // tant qu'une revue ne l'y remet pas. Le film reste publié, incomplet.
    $projection = FrameBank::projection($movie);

    expect($projection->levels_mask)->toBe(FrameLevelCoverage::maskOf([FrameLevel::Level1, FrameLevel::Level5]))
        ->and($projection->level_3_variants)->toBe(0)
        ->and($projection->level_4_variants)->toBe(0)
        ->and($movie->refresh()->availability)->toBe(ContentAvailability::Published);

    // Même transaction : une panne du recalcul de la projection annule le
    // niveau, la sortie du jeu et la ligne du journal.
    Event::listen('eloquent.saving: '.MovieProjection::class, function (): never {
        throw new RuntimeException('Panne simulée du recalcul de projection.');
    });

    expect(fn () => app(ChangeFrameLevel::class)->handle($level1, $curator, FrameLevel::Level2))
        ->toThrow(RuntimeException::class, 'Panne simulée');

    $level1->refresh();

    expect($level1->frame_level)->toBe(FrameLevel::Level1)
        ->and($level1->availability)->toBe(ContentAvailability::Published)
        ->and(AdminAction::query()->where('subject_id', $level1->id)->exists())->toBeFalse();
});

/**
 * Une image hors du jeu, dans l'état nommé.
 */
function frameLevelUnpublished(Movie $movie, string $state): Frame
{
    $factory = Frame::factory()->for($movie);

    return match ($state) {
        'draft_ready' => $factory->withFiles()->create(),
        'draft_pending' => $factory->create(),
        'draft_failed' => $factory->processingFailed(FrameProcessingFailure::CropInvalid)->create(),
        'set_aside' => $factory->withFiles()->create([
            'availability' => ContentAvailability::Unpublished,
            'availability_changed_at' => now(),
        ]),
        'unpublished' => $factory->withFiles()->create([
            'availability' => ContentAvailability::Unpublished,
            'availability_changed_at' => now(),
            'first_published_at' => now()->subDay(),
        ]),
        default => throw new InvalidArgumentException($state),
    };
}

test('changer le niveau d\'une frame non publiée ne touche pas sa disponibilité', function (string $state): void {
    $movie = Movie::factory()->create();
    $frame = frameLevelUnpublished($movie, $state);
    $availability = $frame->availability;
    $changedAt = $frame->availability_changed_at?->toIso8601String();
    $recomputedAt = FrameBank::projection($movie)->recomputed_at;

    $this->travel(5)->minutes();

    frameLevelPatch($frame, FrameLevel::Level2)->assertSessionHasNoErrors();

    $frame->refresh();

    expect($frame->frame_level)->toBe(FrameLevel::Level2)
        ->and($frame->availability)->toBe($availability)
        ->and($frame->availability_changed_at?->toIso8601String())->toBe($changedAt);

    // Une seule ligne : le changement de niveau (D41 du 30/09) ; aucune
    // sortie du jeu, la frame n'y était pas.
    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::FrameLevelChanged)
        ->and($line->subject_id)->toBe($frame->id)
        ->and($line->details?->values)->toBe(['from' => 3, 'to' => 2]);

    // La projection est recalculée dans la transaction du geste.
    expect(FrameBank::projection($movie)->recomputed_at->greaterThan($recomputedAt))->toBeTrue();
})->with([
    'brouillon prêt' => 'draft_ready',
    'brouillon en traitement' => 'draft_pending',
    'brouillon en échec' => 'draft_failed',
    'image écartée' => 'set_aside',
    'image dépubliée' => 'unpublished',
]);

test('le motif écrit par le serveur est un texte et jamais une clé de traduction', function (): void {
    $curator = User::factory()->curator()->create();
    [, [$viaScreen, $viaAction]] = FrameBank::publishedMovie([3, 3]);
    $text = frameLevelText('admin.frame.level.default_reason');

    // Le texte existe, et ce n'est pas la clé.
    expect($text)->not->toBe('admin.frame.level.default_reason')
        ->and($text)->not->toStartWith('admin.');

    // Depuis l'écran, sous la locale forcée du back-office…
    frameLevelPatch($viaScreen, FrameLevel::Level2, $curator)->assertSessionHasNoErrors();

    // …comme hors de toute requête, sous la locale anglaise de l'instance,
    // où le domaine `admin` n'existe pas et où la clé resterait brute.
    App::setLocale(Locale::English->value);
    app(ChangeFrameLevel::class)->handle($viaAction, $curator, FrameLevel::Level4);

    foreach ([$viaScreen, $viaAction] as $frame) {
        $reason = AdminAction::query()
            ->where('subject_type', AdminActionSubject::Frame->value)
            ->where('subject_id', $frame->id)
            ->where('action', AdminActionType::FrameUnpublished->value)
            ->sole()
            ->reason;

        expect($reason)->toBe($text)
            ->and($reason)->not->toContain('admin.frame');
    }
});

test('changer le niveau d\'une frame suspendue ou retirée est refusé par une erreur traduite', function (ContentAvailability $state): void {
    $curator = User::factory()->curator()->create();
    $frame = Frame::factory()->for(Movie::factory()->create())->withFiles()->create(['availability' => $state]);

    $response = frameLevelPatch($frame, FrameLevel::Level5, $curator);

    expect($response->getStatusCode())->toBe(302);

    $response->assertSessionHasErrors(['frame' => frameLevelText('admin.frame.level.locked')]);

    expect($frame->refresh()->frame_level)->toBe(FrameLevel::Level3)
        ->and($frame->availability)->toBe($state);

    expect(fn () => app(ChangeFrameLevel::class)->handle($frame, $curator, FrameLevel::Level5))
        ->toThrow(ValidationException::class);
})->with([
    'suspendue' => ContentAvailability::Suspended,
    'retirée' => ContentAvailability::Withdrawn,
]);

test('reclasser une image publiée à son propre niveau ne la sort pas du jeu', function (): void {
    [$movie, [$frame]] = FrameBank::publishedMovie([3]);

    frameLevelPatch($frame, FrameLevel::Level3)->assertSessionHasNoErrors();

    expect($frame->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(AdminAction::query()->count())->toBe(0)
        ->and(FrameBank::projection($movie)->level_3_variants)->toBe(1);
});
