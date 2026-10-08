<?php

use App\Actions\Curation\SuspendFrame;
use App\Actions\Curation\SuspendMovie;
use App\Actions\Curation\UnpublishMovie;
use App\Actions\Curation\UnsuspendFrame;
use App\Actions\Curation\UnsuspendMovie;
use App\Enums\AdminActionType;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Jobs\Game\WithdrawContentFromLiveRounds;
use App\Models\AdminAction;
use App\Models\AnswerKey;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Models\Room;
use App\Models\SeenFrame;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Suspension conservatoire et levée — spec 20 § 11.2, lot L20-20 (J2)
|--------------------------------------------------------------------------
|
| Administrateur seul, un clic, réversible. Suspendre un film n'emporte que
| ses images publiées (sans ligne par image) et supprime leurs seen_frame ;
| la levée reconstitue l'état antérieur depuis le journal, rend les images
| de la cascade selon la règle de la revue, rejoue la garde de publication
| et, en échec, écrit movie.unsuspended puis movie.unpublished. L'annulation
| en manche se prouve dans `tests/Feature/Game/LiveWithdrawalTest.php`.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    $this->withoutVite();

    $this->admin = User::factory()->admin()->create();
});

/**
 * Une ligne de journal, dans sa transaction — ce qu'aurait écrit le geste.
 */
function suspensionJournal(User $actor, AdminActionType $action, int $subjectId, ?string $reason = null): void
{
    DB::transaction(static fn () => app(AdminJournal::class)->record($actor, $action, $subjectId, $reason));
}

/**
 * Un film publiable au titre original donné — contenu vérifié, une image
 * publiée à chacun des niveaux demandés, clés projetées —, publié et prouvé
 * par sa ligne `movie.published`, ou brouillon sans aucune ligne.
 *
 * @param  list<int>  $levels
 * @return array{0: Movie, 1: list<Frame>}
 */
function suspensionFilm(User $actor, string $title, bool $published = true, array $levels = [1, 3, 5]): array
{
    $factory = Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear);

    $movie = ($published ? $factory->published() : $factory)->create([
        'title_original' => $title,
        'title_original_latin' => null,
    ]);

    [$movie, $frames] = FrameBank::movieWith($movie, $levels);
    (new AnswerKeyProjector)->project($movie);

    if ($published) {
        suspensionJournal($actor, AdminActionType::MoviePublished, $movie->id);
    }

    return [$movie->refresh(), $frames];
}

/** L'empreinte de l'aperçu d'ambiguïté que la confirmation montrerait. */
function suspensionDigest(Movie $movie): string
{
    return app(AmbiguityPreview::class)->forPublication($movie->fresh() ?? $movie)->digest();
}

/** Les actions du journal d'un sujet, dans l'ordre d'écriture. */
function suspensionLines(string $subjectType, int $subjectId): array
{
    return AdminAction::query()
        ->where('subject_type', $subjectType)
        ->where('subject_id', $subjectId)
        ->orderBy('id')
        ->get()
        ->map(static fn (AdminAction $line): string => $line->action->value)
        ->all();
}

/** La forme préfixe d'un film, telle que l'index la porte. */
function suspensionPrefix(Movie $movie, string $form): AnswerKey
{
    return AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->where('key_kind', AnswerKeyKind::Prefix->value)
        ->where('normalized', $form)
        ->sole();
}

/**
 * Le rechargement partiel de la fiche qui ouvre la confirmation de levée :
 * l'aperçu d'ambiguïté seul.
 */
function suspensionPreview(User $admin, Movie $movie): TestResponse
{
    $page = test()->actingAs($admin)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->viewData('page');

    return test()->actingAs($admin)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]), [
            Header::INERTIA => 'true',
            Header::VERSION => (string) $page['version'],
            Header::PARTIAL_COMPONENT => 'admin/catalog/show',
            Header::PARTIAL_ONLY => 'publication_preview,unsuspension',
        ]);
}

test('suspendre un film ne suspend que ses images publiées et supprime leurs seen_frame dans la même transaction', function (): void {
    [$movie, $frames] = suspensionFilm($this->admin, 'Phare Lointain');
    $draft = Frame::factory()->for($movie)->level(FrameLevel::Level2)->withFiles()->create();
    $setAside = Frame::factory()->for($movie)->level(FrameLevel::Level4)->published()->create();
    $setAside->forceFill(['availability' => ContentAvailability::Unpublished])->save();

    $room = Room::factory()->create();
    $seen = SeenFrame::factory()->forRoom($room)->forFrame($frames[0])->create();
    $seenSetAside = SeenFrame::factory()->forRoom($room)->forFrame($setAside)->create();

    // Une transaction annulée ne laisse rien : ni état, ni mémoire effacée,
    // ni ligne de journal.
    try {
        DB::transaction(function () use ($movie): void {
            app(SuspendMovie::class)->handle($movie, $this->admin, 'Signalement.');

            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }

    expect($movie->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and($frames[0]->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and(SeenFrame::query()->whereKey($seen->id)->exists())->toBeTrue()
        ->and(suspensionLines('movie', $movie->id))->toBe([AdminActionType::MoviePublished->value]);

    $cascaded = app(SuspendMovie::class)->handle($movie, $this->admin, 'Signalement.');

    expect($cascaded)->toBe(3)
        ->and($movie->availability)->toBe(ContentAvailability::Suspended)
        ->and($movie->availability_reason)->toBe('Signalement.')
        ->and(array_map(static fn (Frame $frame): ContentAvailability => $frame->fresh()->availability, $frames))
        ->toBe(array_fill(0, 3, ContentAvailability::Suspended))
        ->and($draft->fresh()?->availability)->toBe(ContentAvailability::Draft)
        ->and($setAside->fresh()?->availability)->toBe(ContentAvailability::Unpublished)
        ->and(SeenFrame::query()->whereKey($seen->id)->exists())->toBeFalse()
        ->and(SeenFrame::query()->whereKey($seenSetAside->id)->exists())->toBeTrue()
        ->and(suspensionLines('movie', $movie->id))->toBe([
            AdminActionType::MoviePublished->value,
            AdminActionType::MovieSuspended->value,
        ])
        // Aucune ligne par image : c'est ce qui signe la cascade (invariant 9).
        ->and(AdminAction::query()->where('subject_type', 'frame')->count())->toBe(0);
});

test('la suspension sort le film du vivier à la seconde', function (): void {
    $movie = PoolFixtures::movie();
    $scope = PoolScope::catalogue([], RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);

    expect(app(PoolQuery::class)->countWorks($scope))->toBe(1);

    app(SuspendMovie::class)->handle($movie, $this->admin, null);

    expect(app(PoolQuery::class)->countWorks($scope))->toBe(0)
        ->and(MovieProjection::query()->findOrFail($movie->id)->levels_mask)->toBe(0);
});

test('la suspension distribue l\'annulation active après commit', function (): void {
    [$movie] = suspensionFilm($this->admin, 'Rivage Gelé');
    $base = DB::transactionLevel();

    /** @var list<array{string, int}> $ran */
    $ran = [];
    Queue::before(function (JobProcessing $event) use (&$ran): void {
        $ran[] = [$event->job->resolveName(), DB::transactionLevel()];
    });

    // Annulée : aucun job ne part.
    try {
        DB::transaction(function () use ($movie): void {
            app(SuspendMovie::class)->handle($movie, $this->admin, null);

            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }

    expect($ran)->toBe([]);

    // Validée : le job part au commit, jamais avant.
    DB::transaction(function () use ($movie, &$ran): void {
        app(SuspendMovie::class)->handle($movie, $this->admin, null);

        expect($ran)->toBe([]);
    });

    expect($ran)->toBe([[WithdrawContentFromLiveRounds::class, $base]]);

    Queue::fake();
    $other = PoolFixtures::movie();
    app(SuspendMovie::class)->handle($other, $this->admin, null);

    Queue::assertPushedOn('game', WithdrawContentFromLiveRounds::class, fn (WithdrawContentFromLiveRounds $job): bool => $job->movieId === $other->id
        && $job->frameId === null
        && $job->reason->value === 'movie_suspended');
});

test('la levée rétablit l\'état antérieur reconstitué depuis le journal', function (): void {
    // Publié : il revient publié, ses images avec lui — deux fois de suite,
    // les lignes de suspension et de levée étant ignorées.
    [$published, $frames] = suspensionFilm($this->admin, 'Forêt Noire');

    foreach ([1, 2] as $round) {
        app(SuspendMovie::class)->handle($published, $this->admin, null);
        $outcome = app(UnsuspendMovie::class)->handle($published, $this->admin, null, suspensionDigest($published));

        expect($outcome)->toBe(['state' => ContentAvailability::Published, 'guard_failed' => false], "cycle {$round}")
            ->and($published->availability)->toBe(ContentAvailability::Published)
            ->and($published->availability_reason)->toBeNull()
            ->and($frames[2]->fresh()?->availability)->toBe(ContentAvailability::Published);
    }

    // Dépublié par un curateur : il revient dépublié, avec son motif.
    [$unpublished] = suspensionFilm($this->admin, 'Ville Engloutie');
    app(UnpublishMovie::class)->handle($unpublished, User::factory()->curator()->create(), 'Doublon de fiche.');
    app(SuspendMovie::class)->handle($unpublished, $this->admin, 'Signalement.');

    expect(app(UnsuspendMovie::class)->handle($unpublished, $this->admin, null, null))
        ->toBe(['state' => ContentAvailability::Unpublished, 'guard_failed' => false])
        ->and($unpublished->availability_reason)->toBe('Doublon de fiche.');

    // Brouillon, sans aucune ligne : il revient brouillon.
    [$draft] = suspensionFilm($this->admin, 'Désert Rouge', published: false);
    app(SuspendMovie::class)->handle($draft, $this->admin, null);

    expect(app(UnsuspendMovie::class)->handle($draft, $this->admin, 'Levée.', null))
        ->toBe(['state' => ContentAvailability::Draft, 'guard_failed' => false])
        ->and(suspensionLines('movie', $draft->id))->toBe([
            AdminActionType::MovieSuspended->value,
            AdminActionType::MovieUnsuspended->value,
        ]);
});

test('la levée recalcule la couverture et l\'ambiguïté des formes dans la même transaction', function (): void {
    [$first] = suspensionFilm($this->admin, 'Vent d’Écume : Le Premier Rivage');
    [$second] = suspensionFilm($this->admin, 'Vent d’Écume : La Marée Haute');

    expect(suspensionPrefix($second, 'vent d ecume')->is_ambiguous)->toBeTrue();

    app(SuspendMovie::class)->handle($first, $this->admin, null);

    // Le film sort du catalogue publié : le préfixe de l'autre redevient sûr.
    expect(suspensionPrefix($second, 'vent d ecume')->is_ambiguous)->toBeFalse()
        ->and(MovieProjection::query()->findOrFail($first->id)->levels_mask)->toBe(0);

    // Une levée annulée n'écrit rien, projection comprise.
    try {
        DB::transaction(function () use ($first): void {
            app(UnsuspendMovie::class)->handle($first, $this->admin, null, suspensionDigest($first));

            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }

    expect(MovieProjection::query()->findOrFail($first->id)->levels_mask)->toBe(0)
        ->and(suspensionPrefix($second, 'vent d ecume')->is_ambiguous)->toBeFalse();

    app(UnsuspendMovie::class)->handle($first, $this->admin, null, suspensionDigest($first));

    expect(MovieProjection::query()->findOrFail($first->id)->levels_mask)->toBe(MovieProjection::publishableLevelsMask())
        ->and(suspensionPrefix($second, 'vent d ecume')->is_ambiguous)->toBeTrue()
        ->and(suspensionPrefix($first, 'vent d ecume')->is_ambiguous)->toBeTrue();
});

test('une levée en échec de garde écrit movie.unsuspended puis movie.unpublished', function (): void {
    [$movie] = suspensionFilm($this->admin, 'Îles Lointaines');
    app(SuspendMovie::class)->handle($movie, $this->admin, null);

    // Pendant la suspension, le contenu redevient à vérifier : la garde de
    // publication rejouée à la levée échoue.
    $movie->forceFill(['content_flag' => ContentFlag::UnratedPending])->save();

    $outcome = app(UnsuspendMovie::class)->handle($movie, $this->admin, 'Instruction close.', null);
    $guardFailed = UnsuspendMovie::guardFailedReason();

    $lines = AdminAction::query()->where('subject_type', 'movie')->where('subject_id', $movie->id)->orderBy('id')->get();

    expect($outcome)->toBe(['state' => ContentAvailability::Unpublished, 'guard_failed' => true])
        ->and($movie->availability)->toBe(ContentAvailability::Unpublished)
        ->and($movie->availability_reason)->toBe($guardFailed)
        ->and($guardFailed)->not->toStartWith('admin.')
        ->and($lines->map(fn (AdminAction $line): string => $line->action->value)->all())->toBe([
            AdminActionType::MoviePublished->value,
            AdminActionType::MovieSuspended->value,
            AdminActionType::MovieUnsuspended->value,
            AdminActionType::MovieUnpublished->value,
        ])
        ->and($lines[2]->reason)->toBe('Instruction close.')
        ->and($lines[3]->reason)->toBe($guardFailed);
});

test('une seconde suspension après une levée en échec de garde restaure unpublished, jamais published', function (): void {
    [$movie] = suspensionFilm($this->admin, 'Glacier Bleu');
    app(SuspendMovie::class)->handle($movie, $this->admin, null);
    $movie->forceFill(['content_flag' => ContentFlag::UnratedPending])->save();
    app(UnsuspendMovie::class)->handle($movie, $this->admin, null, null);

    // Le contenu est de nouveau vérifié, mais personne n'a republié.
    $movie->forceFill(['content_flag' => ContentFlag::Clear])->save();

    app(SuspendMovie::class)->handle($movie, $this->admin, null);
    $outcome = app(UnsuspendMovie::class)->handle($movie, $this->admin, null, suspensionDigest($movie));

    expect($outcome)->toBe(['state' => ContentAvailability::Unpublished, 'guard_failed' => false])
        ->and($movie->availability)->toBe(ContentAvailability::Unpublished)
        ->and($movie->availability_reason)->toBe(UnsuspendMovie::guardFailedReason());
});

test('une levée vers published affiche l\'aperçu d\'ambiguïté et refuse une empreinte périmée sans rien écrire', function (): void {
    suspensionFilm($this->admin, 'Ombre Verte : Le Retour');
    [$movie, $frames] = suspensionFilm($this->admin, 'Ombre Verte : La Fuite');
    app(SuspendMovie::class)->handle($movie, $this->admin, null);

    $digest = suspensionDigest($movie);

    // La fiche dit ce que la levée rendra, et sert l'aperçu au seul
    // rechargement qui ouvre la confirmation.
    suspensionPreview($this->admin, $movie)
        ->assertOk()
        ->assertJsonPath('props.unsuspension.restores', 'published')
        ->assertJsonPath('props.publication_preview.digest', $digest)
        ->assertJsonPath('props.publication_preview.lines.0.form', 'ombre verte');

    $linesBefore = AdminAction::query()->count();

    foreach ([str_repeat('0', 64), null] as $stale) {
        $this->actingAs($this->admin)
            ->from(route('admin.catalog.show', ['movie' => $movie->id]))
            ->post(route('admin.catalog.unsuspend', ['movie' => $movie->id]), array_filter(['ambiguity_digest' => $stale]))
            ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
            ->assertSessionHasErrors('ambiguity_digest');

        expect($movie->fresh()?->availability)->toBe(ContentAvailability::Suspended)
            ->and($frames[0]->fresh()?->availability)->toBe(ContentAvailability::Suspended)
            ->and(AdminAction::query()->count())->toBe($linesBefore);
    }

    $this->actingAs($this->admin)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.unsuspend', ['movie' => $movie->id]), ['ambiguity_digest' => $digest])
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasNoErrors();

    expect($movie->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and($frames[0]->fresh()?->availability)->toBe(ContentAvailability::Published);
});

test('une image suspendue par la cascade ne revient en jeu que si sa revue porte sur ses octets et la version courants', function (): void {
    [$movie, $frames] = suspensionFilm($this->admin, 'Lagune Claire', published: false, levels: [1, 3, 5, 5]);
    [$intact, $recropped, $outdated, $neverPublished] = $frames;

    app(SuspendMovie::class)->handle($movie, $this->admin, null);

    // Pendant la suspension : des octets qui ne sont plus ceux de la revue ;
    // une dernière revue passante sous une autre version de la grille ; une
    // image jamais publiée dont les octets ont changé.
    $recropped->forceFill(['published_hash' => hash('sha256', 'autre rendu')])->save();
    FrameReview::factory()->forFrame($outdated)->passed()->gridVersion(0)->create();
    $neverPublished->forceFill([
        'published_hash' => hash('sha256', 'autre rendu encore'),
        'first_published_at' => null,
    ])->save();

    app(UnsuspendMovie::class)->handle($movie, $this->admin, null, null);

    expect($intact->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and($recropped->fresh()?->availability)->toBe(ContentAvailability::Unpublished)
        ->and($outdated->fresh()?->availability)->toBe(ContentAvailability::Unpublished)
        ->and($neverPublished->fresh()?->availability)->toBe(ContentAvailability::Draft)
        // Aucune ligne par image : la levée du film les couvre (invariant 9).
        ->and(AdminAction::query()->where('subject_type', 'frame')->count())->toBe(0);
});

test('une image suspendue individuellement ne se lève que par son propre geste', function (): void {
    [$movie, $frames] = suspensionFilm($this->admin, 'Col des Brumes');
    $target = $frames[1];

    app(SuspendFrame::class)->handle($target, $this->admin, 'Image signalée.');

    expect($target->fresh()?->availability)->toBe(ContentAvailability::Suspended)
        ->and($movie->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and(suspensionLines('frame', $target->id))->toBe([AdminActionType::FrameSuspended->value]);

    // Le film suspendu à son tour puis rendu : l'image reste suspendue, et ne
    // se lève pas tant que son film est suspendu.
    app(SuspendMovie::class)->handle($movie, $this->admin, null);

    $this->actingAs($this->admin)
        ->post(route('admin.catalog.frames.unsuspend', ['movie' => $movie->id, 'frame' => $target->id]))
        ->assertForbidden();

    app(UnsuspendMovie::class)->handle($movie, $this->admin, null, suspensionDigest($movie));

    expect($target->fresh()?->availability)->toBe(ContentAvailability::Suspended)
        ->and($frames[0]->fresh()?->availability)->toBe(ContentAvailability::Published);

    $this->actingAs($this->admin)
        ->from(route('admin.catalog.bank', ['movie' => $movie->id]))
        ->post(route('admin.catalog.frames.unsuspend', ['movie' => $movie->id, 'frame' => $target->id]), ['reason' => 'Instruction close.'])
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $movie->id]));

    expect($target->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and(suspensionLines('frame', $target->id))->toBe([
            AdminActionType::FrameSuspended->value,
            AdminActionType::FrameUnsuspended->value,
        ]);
});

test('une image suspendue individuellement avant la suspension de son film n\'est pas restaurée par la levée du film', function (): void {
    [$movie, $frames] = suspensionFilm($this->admin, 'Plaine Grise', levels: [1, 3, 5, 5]);
    [, , $individual, $relifted] = $frames;

    // Même instant serveur pour la suspension de l'image et celle du film :
    // seule la dernière ligne de l'image décide.
    app(SuspendFrame::class)->handle($individual, $this->admin, null);

    // Une image suspendue puis levée individuellement redevient une image
    // comme les autres : la cascade la reprendra et la rendra.
    app(SuspendFrame::class)->handle($relifted, $this->admin, null);
    app(UnsuspendFrame::class)->handle($relifted, $this->admin, null);

    app(SuspendMovie::class)->handle($movie, $this->admin, null);

    expect($relifted->fresh()?->availability)->toBe(ContentAvailability::Suspended);

    app(UnsuspendMovie::class)->handle($movie, $this->admin, null, suspensionDigest($movie));

    expect($individual->fresh()?->availability)->toBe(ContentAvailability::Suspended)
        ->and($relifted->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and($frames[0]->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and(Gate::forUser($this->admin)->allows('unsuspend', $individual->fresh()))->toBeTrue();
});

test('on ne suspend ni un film déjà suspendu ni un film retiré', function (): void {
    $suspended = Movie::factory()->suspended()->create();
    $withdrawn = Movie::factory()->withdrawn()->create();

    foreach ([$suspended, $withdrawn] as $movie) {
        $this->actingAs($this->admin)
            ->post(route('admin.catalog.suspend', ['movie' => $movie->id]))
            ->assertForbidden();

        expect(fn () => app(SuspendMovie::class)->handle($movie, $this->admin, null))
            ->toThrow(AuthorizationException::class);
    }

    // Un curateur ne suspend rien : la suspension est un geste de l'admin.
    $published = Movie::factory()->published()->create();

    $this->actingAs(User::factory()->curator()->create())
        ->post(route('admin.catalog.suspend', ['movie' => $published->id]))
        ->assertForbidden();

    expect(AdminAction::query()->count())->toBe(0)
        ->and($suspended->fresh()?->availability)->toBe(ContentAvailability::Suspended)
        ->and($withdrawn->fresh()?->availability)->toBe(ContentAvailability::Withdrawn)
        ->and($published->fresh()?->availability)->toBe(ContentAvailability::Published);
});
