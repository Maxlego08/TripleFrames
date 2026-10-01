<?php

use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ContentOrigin;
use App\Enums\FrameLevel;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Journal `admin_action` — liste fermée, acteurs réservés, écrivain unique
|--------------------------------------------------------------------------
|
| Contrat C14 (`20` § 2.7, `10` § 8.3). Les invariants sont gardés à deux
| endroits, et ce fichier les éprouve aux deux : la garde `creating` du
| modèle, qui vaut pour toute écriture d'une instance, et `AdminJournal`,
| l'écrivain unique, qui fige le nom réel et exige une transaction.
|
| Chaque écriture passe par `DB::transaction()` : la transaction englobante
| de `RefreshDatabase` suffirait à la garde de l'écrivain, mais un test qui
| s'y reposerait décrirait un appel que la production refuse.
|
*/

/** Un sujet plausible pour chaque action : un identifiant, sauf pour le site et l'ensemble des comptes. */
function adminJournalSubjectId(AdminActionType $action): ?int
{
    return $action->subject()->hasIdentifier() ? 4242 : null;
}

/** Un complément pour les cas qui en déclarent un (D41 du 30/09), et eux seuls. */
function adminJournalDetails(AdminActionType $action): ?AdminActionDetails
{
    return $action->hasDetails()
        ? AdminActionDetails::levelChanged(FrameLevel::Level1, FrameLevel::Level2)
        : null;
}

/** Les rôles de `role.changed`, et eux seuls. */
function adminJournalRoles(AdminActionType $action): array
{
    return $action === AdminActionType::RoleChanged
        ? [UserRole::Player, UserRole::Curator]
        : [null, null];
}

/**
 * Une ligne composée À LA MAIN, hors de l'écrivain : c'est la garde du modèle
 * seule qui est éprouvée.
 *
 * @param  array<string, mixed>  $overrides
 */
function adminJournalRawLine(AdminActionType $action, array $overrides = []): AdminAction
{
    [$before, $after] = adminJournalRoles($action);

    $line = new AdminAction;
    $line->actor_id = User::factory()->admin()->create()->id;
    $line->actor_name = 'Camille Martin';
    $line->action = $action;
    $line->subject_id = adminJournalSubjectId($action);
    $line->reason = $action->requiresReason() ? 'Motif du geste.' : null;
    $line->role_before = $before;
    $line->role_after = $after;
    $line->details = adminJournalDetails($action);

    foreach ($overrides as $column => $value) {
        $line->setAttribute($column, $value);
    }

    return $line;
}

test('la liste fermée compte exactement cinquante et un cas', function (): void {
    expect(AdminActionType::cases())->toHaveCount(51)
        ->and(array_map(static fn (AdminActionType $case): string => $case->value, AdminActionType::cases()))
        ->toEqualCanonicalizing([
            'role.changed',
            'user.real_name_changed',
            'movie.published',
            'movie.unpublished',
            'movie.republished',
            'movie.content_verified',
            'movie.suspended',
            'movie.unsuspended',
            'movie.withdrawn',
            'frame.unpublished',
            'frame.grid_unpublished',
            'frame.suspended',
            'frame.unsuspended',
            'frame.withdrawn',
            'avatar.hidden',
            'avatar.unhidden',
            'nickname.masked',
            'nickname.unmasked',
            'nickname.banned',
            'takedown.decided',
            'site.closed',
            'site.reopened',
            // D41 du 30/09 : gestes du back-office.
            'movie.title_saved',
            'movie.title_removed',
            'movie.alias_added',
            'movie.alias_removed',
            'movie.grouped',
            'movie.ungrouped',
            'frame.added',
            'frame.recropped',
            'frame.processing_retried',
            'frame.level_changed',
            'frame.reviewed',
            'import.discover_started',
            'import.paste_started',
            'import.seed_list_started',
            'import.resumed',
            // D42 du 30/09 : validation en lot des images d'un film.
            'movie.frames_reviewed',
            // D41 du 30/09 : lectures sensibles.
            'accounts.directory_viewed',
            'accounts.access_viewed',
            'user.looked_up',
            'user.viewed',
            // D46 du 01/10 : inspection des parties et des sièges.
            'games.directory_viewed',
            'game.viewed',
            'players.directory_viewed',
            'player.viewed',
            // D43 du 01/10 : l'écran des thèmes.
            'theme.created',
            'theme.updated',
            'theme.published',
            'theme.unpublished',
            'movie.theme_set',
        ]);

    // `action` reste un `string(40)` : aucun cas ne dépasse la colonne, et
    // c'est ce qui dispense la liste d'une migration.
    foreach (AdminActionType::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(40);
    }

    expect(AdminActionSubject::cases())->toHaveCount(12)
        ->and(AdminActionSubject::ImportRun->value)->toBe('import_run')
        ->and(AdminActionSubject::Accounts->value)->toBe('accounts')
        ->and(AdminActionSubject::Theme->value)->toBe('theme')
        ->and(AdminActionSubject::Theme->hasIdentifier())->toBeTrue()
        // D46 du 01/10 : une partie inspectée, et deux ensembles sans identifiant.
        ->and(AdminActionSubject::Game->hasIdentifier())->toBeTrue()
        ->and(AdminActionSubject::Games->hasIdentifier())->toBeFalse()
        ->and(AdminActionSubject::Players->hasIdentifier())->toBeFalse();

    // `subject_type` reste un `string(20)`.
    foreach (AdminActionSubject::cases() as $subject) {
        expect(strlen($subject->value))->toBeLessThanOrEqual(20);
    }

    // Huit lectures sensibles (D41 du 30/09, D46 du 01/10), et elles seules
    // ne sont pas des gestes.
    expect(array_values(array_filter(
        AdminActionType::cases(),
        static fn (AdminActionType $case): bool => $case->isRead(),
    )))->toBe([
        AdminActionType::AccountsDirectoryViewed,
        AdminActionType::AccountsAccessViewed,
        AdminActionType::UserLookedUp,
        AdminActionType::UserViewed,
        AdminActionType::GamesDirectoryViewed,
        AdminActionType::GameViewed,
        AdminActionType::PlayersDirectoryViewed,
        AdminActionType::PlayerViewed,
    ]);

    // Aucun cas nouveau n'exige de motif ni n'admet la console ou le système.
    foreach (AdminActionType::cases() as $case) {
        if ($case->hasDetails() || $case->isRead()) {
            expect($case->requiresReason())->toBeFalse($case->value)
                ->and($case->allowsConsoleActor())->toBeFalse($case->value)
                ->and($case->isAutomatic())->toBeFalse($case->value);
        }
    }
});

test('tout sujet movie, frame, takedown_request, site, import_run ou accounts est permanent', function (): void {
    $permanentSubjects = [
        AdminActionSubject::Movie,
        AdminActionSubject::Frame,
        AdminActionSubject::TakedownRequest,
        AdminActionSubject::Site,
        AdminActionSubject::ImportRun,
        AdminActionSubject::Accounts,
    ];

    foreach (AdminActionType::cases() as $case) {
        if (in_array($case->subject(), $permanentSubjects, true)) {
            expect($case->retentionClass())->toBe(AdminActionRetention::Permanent, $case->value);
        }
    }

    // Aucun cas de la liste ne tombe en `rolling_12m` (`10` § 8.3) —
    // `user.real_name_changed` compris, malgré son sujet `user` (EN20-3).
    expect(AdminActionType::UserRealNameChanged->subject())->toBe(AdminActionSubject::User)
        ->and(AdminActionType::UserViewed->subject())->toBe(AdminActionSubject::User)
        ->and(AdminActionType::UserLookedUp->subject())->toBe(AdminActionSubject::User);

    foreach (AdminActionType::cases() as $case) {
        expect($case->retentionClass())->toBe(AdminActionRetention::Permanent, $case->value);
    }

    // Et la classe écrite en base est celle de l'action, jamais celle fournie.
    $line = adminJournalRawLine(AdminActionType::SiteClosed, [
        'actor_id' => null,
        'actor_name' => AdminAction::CONSOLE_ACTOR,
        'retention_class' => AdminActionRetention::Rolling12m,
    ]);
    $line->save();

    expect($line->fresh()?->retention_class)->toBe(AdminActionRetention::Permanent);
});

test('system n\'est accepté que pour avatar.hidden et nickname.masked', function (): void {
    $journal = app(AdminJournal::class);

    foreach (AdminActionType::cases() as $case) {
        $write = fn (): AdminAction => DB::transaction(
            fn (): AdminAction => $journal->recordAutomatic($case, 4242, 2),
        );

        if (in_array($case, [AdminActionType::AvatarHidden, AdminActionType::NicknameMasked], true)) {
            $line = $write();

            expect($line->actor_id)->toBeNull()
                ->and($line->fresh()?->actor_name)->toBe(AdminAction::SYSTEM_ACTOR)
                ->and($line->reports_count)->toBe(2);

            continue;
        }

        expect($write)->toThrow(LogicException::class);
    }

    // Par l'écrivain, une autre garde peut lever la première (sujet du site,
    // motif, rôles). Une ligne dont SEUL l'acteur est fautif prouve la garde
    // d'acteur elle-même, sur chaque geste non automatique et dans chaque
    // graphie : aucune ne contourne la réserve, avec ou sans auteur identifié.
    $refusal = 'La valeur réservée ['.AdminAction::SYSTEM_ACTOR.'] est refusée';

    foreach (AdminActionType::cases() as $case) {
        if ($case->isAutomatic()) {
            continue;
        }

        foreach (['system', 'System', ' SYSTEM '] as $spelling) {
            expect(fn () => adminJournalRawLine($case, ['actor_id' => null, 'actor_name' => $spelling])->save())
                ->toThrow(LogicException::class, $refusal);
        }

        expect(fn () => adminJournalRawLine($case, ['actor_name' => AdminAction::SYSTEM_ACTOR])->save())
            ->toThrow(LogicException::class, $refusal);
    }

    // Un geste automatique n'a jamais d'auteur identifié.

    expect(fn () => adminJournalRawLine(AdminActionType::NicknameMasked, ['actor_name' => AdminAction::SYSTEM_ACTOR])->save())
        ->toThrow(LogicException::class);

    expect(AdminAction::query()->count())->toBe(2);
});

test('console n\'est accepté que pour role.changed, site.closed et site.reopened, avec actor_id nul', function (): void {
    $journal = app(AdminJournal::class);

    foreach (AdminActionType::cases() as $case) {
        [$before, $after] = adminJournalRoles($case);

        $write = fn (): AdminAction => DB::transaction(fn (): AdminAction => $journal->recordFromConsole(
            $case,
            adminJournalSubjectId($case),
            $case->requiresReason() ? 'Motif du geste.' : null,
            $before,
            $after,
        ));

        if ($case->allowsConsoleActor()) {
            $line = $write();

            expect($line->actor_id)->toBeNull()
                ->and($line->fresh()?->actor_name)->toBe(AdminAction::CONSOLE_ACTOR);

            continue;
        }

        expect($write)->toThrow(LogicException::class);
    }

    expect(array_values(array_filter(
        AdminActionType::cases(),
        static fn (AdminActionType $case): bool => $case->allowsConsoleActor(),
    )))->toBe([AdminActionType::RoleChanged, AdminActionType::SiteClosed, AdminActionType::SiteReopened]);

    // `console` avec un compte identifié : refusé, même sur un geste qui
    // l'admet — l'opérateur du shell n'est pas un compte.
    foreach (['console', 'Console'] as $spelling) {
        expect(fn () => adminJournalRawLine(AdminActionType::RoleChanged, ['actor_name' => $spelling])->save())
            ->toThrow(LogicException::class);
    }

    expect(AdminAction::query()->count())->toBe(3);
});

test('un geste non automatique exige un actor_id', function (): void {
    foreach (AdminActionType::cases() as $case) {
        if ($case->isAutomatic()) {
            continue;
        }

        expect(fn () => adminJournalRawLine($case, ['actor_id' => null])->save())
            ->toThrow(LogicException::class, 'actor_id');
    }

    // Un auteur identifié mais sans nom réel ne signe rien : la ligne se
    // refuserait à figer un nom vide.
    $nameless = User::factory()->create();

    expect(fn () => DB::transaction(fn () => app(AdminJournal::class)->record(
        $nameless,
        AdminActionType::MoviePublished,
        4242,
    )))->toThrow(LogicException::class);

    expect(AdminAction::query()->count())->toBe(0);
});

test('un motif vide est refusé quand l\'action l\'exige', function (): void {
    expect(array_values(array_filter(
        AdminActionType::cases(),
        static fn (AdminActionType $case): bool => $case->requiresReason(),
    )))->toEqualCanonicalizing([
        AdminActionType::MovieUnpublished,
        AdminActionType::MovieContentVerified,
        AdminActionType::MovieWithdrawn,
        AdminActionType::FrameGridUnpublished,
        AdminActionType::FrameWithdrawn,
        AdminActionType::TakedownDecided,
        AdminActionType::SiteClosed,
    ]);

    $journal = app(AdminJournal::class);
    $admin = User::factory()->admin()->create();

    foreach ([null, '', '   '] as $empty) {
        expect(fn () => DB::transaction(fn () => $journal->record(
            $admin,
            AdminActionType::MovieUnpublished,
            4242,
            $empty,
        )))->toThrow(LogicException::class);

        expect(fn () => DB::transaction(fn () => $journal->recordFromConsole(
            AdminActionType::SiteClosed,
            null,
            $empty,
        )))->toThrow(LogicException::class);
    }

    // Le motif ne dépasse jamais la colonne `string(500)`.
    expect(fn () => DB::transaction(fn () => $journal->record(
        $admin,
        AdminActionType::MovieUnpublished,
        4242,
        str_repeat('é', AdminAction::REASON_MAX_LENGTH + 1),
    )))->toThrow(LogicException::class);

    expect(AdminAction::query()->count())->toBe(0);

    // Un motif présent est rogné ; un motif de blancs sur un geste facultatif
    // ne dit rien, et n'est pas écrit.
    $line = DB::transaction(fn () => $journal->record($admin, AdminActionType::MovieUnpublished, 4242, '  Doublon.  '));
    $optional = DB::transaction(fn () => $journal->record($admin, AdminActionType::MovieRepublished, 4242, '   '));

    expect($line->fresh()?->reason)->toBe('Doublon.')
        ->and($optional->fresh()?->reason)->toBeNull();
});

test('subject_type est dérivé de l\'action', function (): void {
    $journal = app(AdminJournal::class);
    $admin = User::factory()->admin()->create();

    foreach (AdminActionType::cases() as $case) {
        if ($case->isAutomatic()) {
            $line = DB::transaction(fn (): AdminAction => $journal->recordAutomatic($case, 4242, 2));
        } elseif ($case->isRead()) {
            $line = $journal->recordRead($admin, $case, adminJournalSubjectId($case));
        } else {
            [$before, $after] = adminJournalRoles($case);

            $line = DB::transaction(fn (): AdminAction => $journal->record(
                $admin,
                $case,
                adminJournalSubjectId($case),
                $case->requiresReason() ? 'Motif du geste.' : null,
                roleBefore: $before,
                roleAfter: $after,
                details: adminJournalDetails($case),
            ));
        }

        expect($line->fresh()?->subject_type)->toBe($case->subject(), $case->value);
    }

    // Un sujet fourni à la main est ignoré : c'est l'action qui décide.
    $forced = adminJournalRawLine(AdminActionType::MoviePublished, ['subject_type' => AdminActionSubject::User]);
    $forced->save();

    expect($forced->fresh()?->subject_type)->toBe(AdminActionSubject::Movie);
});

test('subject_id est nul si et seulement si le sujet n\'a pas d\'identifiant, site ou accounts', function (): void {
    expect(array_values(array_filter(
        AdminActionSubject::cases(),
        static fn (AdminActionSubject $subject): bool => ! $subject->hasIdentifier(),
    )))->toBe([AdminActionSubject::Site, AdminActionSubject::Accounts, AdminActionSubject::Games, AdminActionSubject::Players]);

    expect(fn () => adminJournalRawLine(AdminActionType::AccountsDirectoryViewed, ['subject_id' => 4242])->save())
        ->toThrow(LogicException::class);

    expect(fn () => adminJournalRawLine(AdminActionType::UserViewed, ['subject_id' => null])->save())
        ->toThrow(LogicException::class);

    expect(fn () => adminJournalRawLine(AdminActionType::MoviePublished, ['subject_id' => null])->save())
        ->toThrow(LogicException::class);

    expect(fn () => adminJournalRawLine(AdminActionType::SiteReopened, [
        'actor_id' => null,
        'actor_name' => AdminAction::CONSOLE_ACTOR,
        'subject_id' => 4242,
    ])->save())->toThrow(LogicException::class);

    expect(AdminAction::query()->count())->toBe(0);
});

test('role_before et role_after ne sont remplis, et différents, que pour role.changed', function (): void {
    foreach ([[null, UserRole::Admin], [UserRole::Curator, null], [UserRole::Admin, UserRole::Admin]] as [$before, $after]) {
        expect(fn () => adminJournalRawLine(AdminActionType::RoleChanged, [
            'role_before' => $before,
            'role_after' => $after,
        ])->save())->toThrow(LogicException::class);
    }

    expect(fn () => adminJournalRawLine(AdminActionType::MoviePublished, [
        'role_before' => UserRole::Player,
        'role_after' => UserRole::Curator,
    ])->save())->toThrow(LogicException::class);

    expect(AdminAction::query()->count())->toBe(0);
});

test('AdminJournal refuse d\'écrire hors transaction', function (): void {
    $journal = app(AdminJournal::class);
    $admin = User::factory()->admin()->create();
    $connection = (new AdminAction)->getConnection();

    // `RefreshDatabase` enveloppe chaque test dans une transaction : on en sort
    // le temps de l'assertion, puis on la rouvre pour que le nettoyage de fin
    // de test la retrouve. Sans cette sortie, le niveau vaut 1 et le refus ne
    // serait jamais observable.
    $level = $connection->transactionLevel();

    for ($i = 0; $i < $level; $i++) {
        $connection->rollBack();
    }

    try {
        expect($connection->transactionLevel())->toBe(0);

        expect(fn () => $journal->record($admin, AdminActionType::MoviePublished, 4242))
            ->toThrow(LogicException::class, 'hors transaction');

        expect(fn () => $journal->recordFromConsole(
            AdminActionType::RoleChanged,
            4242,
            null,
            UserRole::Player,
            UserRole::Admin,
        ))->toThrow(LogicException::class, 'hors transaction');

        expect(fn () => $journal->recordAutomatic(AdminActionType::NicknameMasked, 4242, 2))
            ->toThrow(LogicException::class, 'hors transaction');

        expect(AdminAction::query()->count())->toBe(0);

        // Une lecture sensible n'a pas de transaction : elle s'écrit sans.
        // Son auteur est créé ici même — celui du test a disparu avec la
        // transaction englobante —, et tout est effacé aussitôt, pour ne rien
        // laisser aux tests suivants.
        $reader = User::factory()->admin()->create();
        $read = $journal->recordRead($reader, AdminActionType::UserViewed, 4242);

        expect($read->exists)->toBeTrue()
            ->and($connection->transactionLevel())->toBe(0);

        $read->delete();
        $reader->delete();
    } finally {
        for ($i = 0; $i < $level; $i++) {
            $connection->beginTransaction();
        }
    }

    // Dans une transaction, la même écriture passe.
    $admin = User::factory()->admin()->create();

    DB::transaction(fn () => $journal->record($admin, AdminActionType::MoviePublished, 4242));

    expect(AdminAction::query()->count())->toBe(1);
});

test('chaque cas a sa clé admin.enum.admin_action', function (): void {
    foreach (AdminActionType::cases() as $case) {
        $key = $case->labelKey();

        expect($key)->toBe('admin.enum.admin_action.'.str_replace('.', '_', $case->value));

        $label = trans($key, [], 'fr');

        expect($label)->toBeString()
            ->and($label)->not->toBe($key, "Libellé manquant pour [{$case->value}].");
    }

    foreach (AdminActionSubject::cases() as $subject) {
        $key = $subject->labelKey();

        expect(trans($key, [], 'fr'))->not->toBe($key, "Libellé manquant pour le sujet [{$subject->value}].");
    }

    // Exactement une feuille par cas : ni orpheline, ni oubliée.
    expect(array_keys((array) trans('admin.enum.admin_action', [], 'fr')))
        ->toEqualCanonicalizing(array_map(
            static fn (AdminActionType $case): string => str_replace('.', '_', $case->value),
            AdminActionType::cases(),
        ))
        ->and(array_keys((array) trans('admin.enum.admin_action_subject', [], 'fr')))
        ->toEqualCanonicalizing(array_map(
            static fn (AdminActionSubject $subject): string => $subject->value,
            AdminActionSubject::cases(),
        ));
});

test('une lecture ne s\'écrit que par recordRead, un geste jamais par elle', function (): void {
    $journal = app(AdminJournal::class);
    $admin = User::factory()->admin()->create();

    foreach (AdminActionType::cases() as $case) {
        if ($case->isRead()) {
            expect(fn () => DB::transaction(fn () => $journal->record($admin, $case, adminJournalSubjectId($case))))
                ->toThrow(LogicException::class, 'recordRead');

            $line = $journal->recordRead($admin, $case, adminJournalSubjectId($case));

            expect($line->fresh()?->actor_name)->toBe($admin->real_name)
                ->and($line->actor_id)->toBe($admin->id)
                ->and($line->retention_class)->toBe(AdminActionRetention::Permanent)
                ->and($line->reason)->toBeNull()
                ->and($line->details)->toBeNull();

            continue;
        }

        expect(fn () => $journal->recordRead($admin, $case, adminJournalSubjectId($case)))
            ->toThrow(LogicException::class, 'record()');
    }

    expect(AdminAction::query()->count())->toBe(8);
});

test('details est exigé des seuls cas qui en déclarent, et borné', function (): void {
    $journal = app(AdminJournal::class);
    $admin = User::factory()->admin()->create();

    // Absent là où il est dû, présent là où il ne l'est pas : refusé.
    expect(fn () => DB::transaction(fn () => $journal->record($admin, AdminActionType::FrameLevelChanged, 4242)))
        ->toThrow(LogicException::class, 'details');

    expect(fn () => DB::transaction(fn () => $journal->record(
        $admin,
        AdminActionType::MoviePublished,
        4242,
        details: AdminActionDetails::levelChanged(FrameLevel::Level1, FrameLevel::Level2),
    )))->toThrow(LogicException::class, 'details');

    // Au-delà de la borne : refusé.
    expect(fn () => DB::transaction(fn () => $journal->record(
        $admin,
        AdminActionType::MovieTitleRemoved,
        4242,
        details: AdminActionDetails::titleRemoved('fr', str_repeat('a', AdminActionDetails::MAX_BYTES)),
    )))->toThrow(LogicException::class, (string) AdminActionDetails::MAX_BYTES);

    expect(AdminAction::query()->count())->toBe(0);

    // Écrit puis relu à l'identique, en valeur typée.
    $line = DB::transaction(fn (): AdminAction => $journal->record(
        $admin,
        AdminActionType::MovieTitleSaved,
        4242,
        details: AdminActionDetails::titleSaved('fr', 'Ancien titre', ContentOrigin::Tmdb, 'Nouveau titre'),
    ));

    expect($line->fresh()?->details?->values)->toBe([
        'locale' => 'fr',
        'before' => 'Ancien titre',
        'before_origin' => 'tmdb',
        'after' => 'Nouveau titre',
    ]);
});

test('une visite compte, un rechargement partiel ou un préchargement non', function (): void {
    expect(AdminJournal::countsAsVisit(Request::create('/admin/users')))->toBeTrue();

    $partial = Request::create('/admin/users');
    $partial->headers->set('X-Inertia-Partial-Component', 'admin/users/index');

    $prefetch = Request::create('/admin/users');
    $prefetch->headers->set('Purpose', 'prefetch');

    expect(AdminJournal::countsAsVisit($partial))->toBeFalse()
        ->and(AdminJournal::countsAsVisit($prefetch))->toBeFalse();
});
