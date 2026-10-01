<?php

use App\Actions\Curation\AddAlias;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\NearMiss;
use App\Models\Player;
use App\Models\Round;
use App\Models\User;
use App\Models\WrongAnswer;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Curation\NearMissAggregator;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create();
});

/** Crée une manche distincte portant le film dont on agrège les réponses. */
function nearMissRound(Movie $movie, int $sequence): Round
{
    return Round::factory()->forMovie($movie)->atSequence($sequence)->create();
}

/** Enregistre une formulation refusée avec sa forme déjà normalisée. */
function nearMissWrongAnswer(
    Round $round,
    Player $player,
    string $text,
    CarbonImmutable $receivedAt,
    int $attempt = 1,
): WrongAnswer {
    return WrongAnswer::factory()->forRound($round, $player)->create([
        'submitted_text' => $text,
        'submitted_normalized' => AnswerKeyNormalizer::normalize($text),
        'attempt_number' => $attempt,
        'received_at' => $receivedAt,
    ]);
}

test('les réponses texte récurrentes deviennent des suggestions agrégées et idempotentes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00'));

    $movie = Movie::factory()->create([
        'title_original' => 'Harry Potter à l’école des sorciers',
        'title_original_latin' => null,
    ]);
    (new AnswerKeyProjector)->project($movie);

    $player = Player::factory()->solo()->create();
    $rounds = [
        nearMissRound($movie, 1),
        nearMissRound($movie, 2),
        nearMissRound($movie, 3),
    ];

    $alternative = 'Harry Potter et la pierre philosophale';
    nearMissWrongAnswer($rounds[0], $player, $alternative, CarbonImmutable::parse('2026-01-20 18:00:00'));
    nearMissWrongAnswer($rounds[0], $player, $alternative, CarbonImmutable::parse('2026-01-20 18:00:01'), 2);
    nearMissWrongAnswer($rounds[1], $player, $alternative, CarbonImmutable::parse('2026-02-10 18:00:00'));
    nearMissWrongAnswer($rounds[2], $player, $alternative, CarbonImmutable::parse('2026-03-01 18:00:00'));

    // Deux manches ne franchissent pas le seuil, même avec plusieurs essais.
    nearMissWrongAnswer($rounds[0], $player, 'Harry Potter pierre magique', CarbonImmutable::parse('2026-03-02 18:00:00'), 3);
    nearMissWrongAnswer($rounds[1], $player, 'Harry Potter pierre magique', CarbonImmutable::parse('2026-03-03 18:00:00'), 2);

    // Un clic QCM n'est jamais une formulation proposée par le joueur.
    WrongAnswer::factory()->forRound($rounds[2], $player)->viaChoice($alternative)->create([
        'received_at' => CarbonImmutable::parse('2026-03-04 18:00:00'),
    ]);

    $aggregator = app(NearMissAggregator::class);

    expect($aggregator->refresh())->toBe(1)
        ->and($aggregator->refresh())->toBe(1)
        ->and(NearMiss::query()->count())->toBe(1);

    $suggestion = NearMiss::query()->sole();

    expect($suggestion->movie_id)->toBe($movie->id)
        ->and($suggestion->normalized_text)->toBe(AnswerKeyNormalizer::normalize($alternative))
        ->and($suggestion->occurrences)->toBe(4)
        ->and($suggestion->distinct_rounds)->toBe(3)
        ->and($suggestion->first_seen_on->toDateString())->toBe('2026-01-01')
        ->and($suggestion->last_seen_on->toDateString())->toBe('2026-03-01')
        ->and($suggestion->best_distance)->toBeGreaterThan(0);

    // Une formulation acceptée ne revient pas à chaque reconstruction, même
    // si les réponses historiques restent dans leur fenêtre de conservation.
    app(AddAlias::class)->handle($movie, $this->curator, Locale::French, $alternative);

    expect($aggregator->refresh())->toBe(0)
        ->and(NearMiss::query()->count())->toBe(0);
});

test('la file permet au curateur d’accepter une formulation comme alias exact', function (): void {
    $movie = Movie::factory()->create([
        'title_original' => 'Harry Potter à l’école des sorciers',
        'title_original_latin' => null,
    ]);
    (new AnswerKeyProjector)->project($movie);

    $text = AnswerKeyNormalizer::normalize('Harry Potter et la pierre philosophale');
    $aliasText = 'Harry Potter et la pierre philosophale';
    $suggestion = NearMiss::factory()->forMovie($movie)->withText($text)->create();

    $this->actingAs($this->curator)
        ->get(route('admin.near_misses.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/near-misses/index')
            ->has('suggestions.data', 1)
            ->where('suggestions.data.0.id', $suggestion->id)
            ->where('suggestions.data.0.normalized_text', $text)
            ->where('suggestions.data.0.movie.id', $movie->id));

    $this->actingAs($this->curator)
        ->post(route('admin.near_misses.promote', ['nearMiss' => $suggestion->id]), [
            'locale' => Locale::French->value,
            'alias' => $aliasText,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.near_misses.index'));

    /** @var Alias $alias */
    $alias = Alias::query()->where('movie_id', $movie->id)->sole();

    expect($alias->alias)->toBe($aliasText)
        ->and($alias->locale)->toBe(Locale::French->value)
        ->and($alias->origin)->toBe(ContentOrigin::Curator)
        ->and($alias->created_by_id)->toBe($this->curator->id)
        ->and(NearMiss::query()->whereKey($suggestion->id)->exists())->toBeFalse();

    /** @var AnswerKey $key */
    $key = AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->where('normalized', AnswerKeyNormalizer::normalize($aliasText))
        ->sole();

    expect($key->key_kind)->toBe(AnswerKeyKind::Alias)
        ->and($key->source_locale)->toBe(Locale::French->value);
});

test('une suggestion ignorée disparaît de la file sans être transformée en alias', function (): void {
    $suggestion = NearMiss::factory()->create();

    $this->actingAs($this->curator)
        ->post(route('admin.near_misses.dismiss', ['nearMiss' => $suggestion->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.near_misses.index'));

    expect($suggestion->refresh()->dismissed_at)->not->toBeNull()
        ->and(Alias::query()->where('movie_id', $suggestion->movie_id)->exists())->toBeFalse();

    $this->actingAs($this->curator)
        ->get(route('admin.near_misses.index'))
        ->assertInertia(fn (Assert $page) => $page->has('suggestions.data', 0));
});
