<?php

use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Theme;
use App\Models\ThemeLabel;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use Illuminate\Testing\TestResponse;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Journal des gestes de thème — D41 du 30/09, D43 du 01/10
|--------------------------------------------------------------------------
|
| Les trois gestes de l'écran des thèmes écrivent leur ligne `admin_action`
| dans la transaction du geste, signée du nom réel, sujet `theme` :
| `theme.created`, `theme.updated` (avant et après des seuls champs
| changés), `theme.published` et `theme.unpublished` (nombre d'œuvres relu
| dans la transaction). Un geste sans changement, un refus ou une
| dépublication d'un thème déjà dépublié n'écrivent rien. La lecture de
| l'écran n'est pas journalisée.
|
*/

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create(['real_name' => 'Camille Martin']);
});

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, int>  $parameters
 */
function themeJournalSend(string $method, string $route, array $payload, array $parameters = []): TestResponse
{
    return test()
        ->actingAs(test()->curator)
        ->from(route('admin.themes.index'))
        ->{$method}(route($route, $parameters), $payload);
}

test('chaque geste de thème écrit son cas au journal dans sa transaction', function (): void {
    // `AdminJournal::record()` refuse d'écrire hors d'une transaction : une
    // ligne présente est une ligne écrite dans celle de son geste.
    themeJournalSend('post', 'admin.themes.store', [
        'theme_kind' => 'decade',
        'rule_value' => 1990,
        'labels' => ['fr' => 'Années 1990', 'en' => 'The 1990s'],
    ])->assertSessionHasNoErrors();

    $theme = Theme::query()->where('key', 'decade.1990s')->sole();
    $created = AdminAction::query()->where('action', AdminActionType::ThemeCreated->value)->sole();

    expect($created->subject_type)->toBe(AdminActionSubject::Theme)
        ->and($created->subject_id)->toBe($theme->id)
        ->and($created->retention_class)->toBe(AdminActionRetention::Permanent)
        ->and($created->reason)->toBeNull()
        ->and($created->details?->values)->toBe([
            'key' => 'decade.1990s',
            'kind' => 'decade',
            'rule_value' => '1990',
            'rule_negated' => false,
            'labels' => ['en' => 'The 1990s', 'fr' => 'Années 1990'],
        ]);

    themeJournalSend('patch', 'admin.themes.update', [
        'rule_value' => 1980,
        'labels' => ['fr' => 'Années 1990', 'en' => 'The Eighties'],
        'sort_order' => $theme->sort_order,
    ], ['theme' => $theme->id])->assertSessionHasNoErrors();

    $updated = AdminAction::query()->where('action', AdminActionType::ThemeUpdated->value)->sole();

    expect($updated->subject_id)->toBe($theme->id)
        ->and($updated->details?->values)->toBe([
            'before' => [
                'rule_value' => '1990',
                'labels' => ['en' => 'The 1990s', 'fr' => 'Années 1990'],
            ],
            'after' => [
                'rule_value' => '1980',
                'labels' => ['en' => 'The Eighties', 'fr' => 'Années 1990'],
            ],
        ])
        // La clé ne suit jamais le libellé.
        ->and($theme->refresh()->key)->toBe('decade.1990s');

    PoolFixtures::fakeFramesDisk();

    foreach (PoolFixtures::movies(RoomSettingsBounds::DEFAULT_ROUNDS_COUNT) as $movie) {
        PoolFixtures::member($movie, $theme);
    }

    themeJournalSend('post', 'admin.themes.publish', ['is_published' => true], ['theme' => $theme->id])
        ->assertSessionHasNoErrors();
    themeJournalSend('post', 'admin.themes.publish', ['is_published' => false], ['theme' => $theme->id])
        ->assertSessionHasNoErrors();

    $published = AdminAction::query()->where('action', AdminActionType::ThemePublished->value)->sole();
    $unpublished = AdminAction::query()->where('action', AdminActionType::ThemeUnpublished->value)->sole();

    expect($published->details?->values)->toBe(['works' => RoomSettingsBounds::DEFAULT_ROUNDS_COUNT])
        ->and($unpublished->details?->values)->toBe(['works' => RoomSettingsBounds::DEFAULT_ROUNDS_COUNT])
        ->and(AdminAction::query()->count())->toBe(4);
});

test('une modification sans changement, un refus ou une bascule déjà faite n’écrivent rien', function (): void {
    $theme = Theme::factory()->unpublished()->genre(878)->sortedAt(120)->create();
    $theme->load('labels');

    $labels = [];

    foreach (Locale::cases() as $locale) {
        $labels[$locale->value] = (string) $theme->labels->first(fn (ThemeLabel $label): bool => $label->locale === $locale)?->label;
    }

    themeJournalSend('patch', 'admin.themes.update', [
        'rule_value' => 878,
        'labels' => $labels,
        'sort_order' => 120,
    ], ['theme' => $theme->id])->assertSessionHasNoErrors();

    // Refus : sous le seuil.
    themeJournalSend('post', 'admin.themes.publish', ['is_published' => true], ['theme' => $theme->id])
        ->assertSessionHasErrors('is_published');

    // Déjà dépublié.
    themeJournalSend('post', 'admin.themes.publish', ['is_published' => false], ['theme' => $theme->id])
        ->assertSessionHasNoErrors();

    // Refus de création : nature interdite.
    themeJournalSend('post', 'admin.themes.store', [
        'theme_kind' => 'difficulty',
        'rule_value' => 'hard',
        'labels' => ['fr' => 'Difficile', 'en' => 'Hard'],
    ])->assertSessionHasErrors('theme_kind');

    // La lecture de l'écran n'est pas une lecture sensible.
    test()->actingAs(test()->curator)->get(route('admin.themes.index'))->assertOk();

    expect(AdminAction::query()->exists())->toBeFalse();
});

test('le journal est signé du nom réel du curateur et lisible dans l’écran Journal', function (): void {
    themeJournalSend('post', 'admin.themes.store', [
        'theme_kind' => 'language',
        'rule_value' => 'ko',
        'labels' => ['fr' => 'Coréen', 'en' => 'Korean'],
    ])->assertSessionHasNoErrors();

    $line = AdminAction::query()->sole();

    expect($line->actor_id)->toBe($this->curator->id)
        ->and($line->actor_name)->toBe('Camille Martin');

    $admin = User::factory()->admin()->create();

    test()->actingAs($admin)
        ->get(route('admin.journal.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lines.data.0.action', 'theme.created')
            ->where('lines.data.0.subject.type', 'theme')
            ->where('lines.data.0.subject.label', 'language.korean')
            ->where('lines.data.0.subject.exists', true));
});
