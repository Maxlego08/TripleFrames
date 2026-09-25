<?php

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\User;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\AnswerKeyProjector;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;

/*
|--------------------------------------------------------------------------
| Alias d'un film — spec 20 § 9.2, spec 10 § 3.4 et § 3.5
|--------------------------------------------------------------------------
|
| Un alias VALIDE une réponse, il n'est jamais affiché. Ajouté sur la fiche
| dans une locale ACTIVÉE — seules elles entrent dans `answer_key` —, il y
| devient une clé EXACTE, et jamais un préfixe ni un sous-titre (décision
| 13, D23 du 23/09). Tout alias se retire, TMDB compris : c'est le geste qui
| corrige une forme exacte partagée, sans aucune commande (D10 du 23/09).
|
*/

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create();
});

/**
 * Un film au titre original donné, ses clés projetées.
 */
function aliasTestFilm(string $original): Movie
{
    $movie = Movie::factory()->create([
        'title_original' => $original,
        'title_original_latin' => null,
    ]);

    (new AnswerKeyProjector)->project($movie);

    return $movie->refresh();
}

/**
 * L'ajout d'un alias, posté depuis la fiche du film.
 */
function aliasTestPost(Movie $movie, string $locale, ?string $alias): TestResponse
{
    return test()
        ->actingAs(test()->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.aliases.store', ['movie' => $movie->id]), [
            'locale' => $locale,
            'alias' => $alias,
        ]);
}

/**
 * Le rechargement partiel qui ouvre la confirmation d'un alias saisi.
 */
function aliasTestPreview(Movie $movie, string $text): TestResponse
{
    $page = test()->actingAs(test()->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->viewData('page');

    return test()
        ->actingAs(test()->curator)
        ->get(route('admin.catalog.show', [
            'movie' => $movie->id,
            'preview_text' => $text,
            'preview_target' => 'alias',
        ]), [
            Header::INERTIA => 'true',
            Header::VERSION => (string) $page['version'],
            Header::PARTIAL_COMPONENT => 'admin/catalog/show',
            Header::PARTIAL_ONLY => 'text_preview',
        ]);
}

/**
 * Les clés du film, forme normalisée → nature.
 *
 * @return array<string, string>
 */
function aliasTestKeys(Movie $movie): array
{
    $keys = [];

    foreach (AnswerKey::query()->where('movie_id', $movie->id)->get() as $key) {
        $keys[(string) $key->normalized] = $key->key_kind->value;
    }

    ksort($keys);

    return $keys;
}

/**
 * Un texte du back-office, résolu en français, sa clé vérifiée d'abord.
 */
function aliasTestText(string $key): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, [], Locale::French->value);
}

test('ajouter un alias crée une clé exacte et jamais une forme dérivée', function (): void {
    $movie = aliasTestFilm('Brume Lointaine');

    // Un alias qui porte un séparateur de sous-titre : s'il était un titre,
    // il donnerait un préfixe et un sous-titre.
    $text = 'Seigneur des brumes : Le Retour du Nord';
    $form = AnswerKeyNormalizer::normalize($text);
    $prefix = AnswerKeyNormalizer::prefixOf($text);
    $subtitle = AnswerKeyNormalizer::subtitleOf($text);

    expect($prefix)->not->toBeNull()
        ->and($subtitle)->not->toBeNull();

    // L'aperçu avant l'envoi : la forme exacte seule, pas encore acceptée ;
    // un brouillon ne pèse dans aucun recompte d'ambiguïté.
    aliasTestPreview($movie, $text)
        ->assertOk()
        ->assertJsonPath('props.text_preview.target', 'alias')
        ->assertJsonPath('props.text_preview.form', $form)
        ->assertJsonPath('props.text_preview.accepted_as', null)
        ->assertJsonPath('props.text_preview.ambiguity', null);

    aliasTestPost($movie, Locale::French->value, '  '.$text.'  ')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertiaFlash('toast.message', aliasTestText('admin.movie.aliases.flash.added'));

    /** @var Alias $alias */
    $alias = Alias::query()->where('movie_id', $movie->id)->sole();

    expect($alias->alias)->toBe($text)
        ->and($alias->locale)->toBe(Locale::French->value)
        ->and($alias->origin)->toBe(ContentOrigin::Curator)
        ->and($alias->created_by_id)->toBe($this->curator->id);

    // Une clé exacte, de nature `alias`, tracée à sa locale — et ni son
    // préfixe ni son sous-titre.
    /** @var AnswerKey $key */
    $key = AnswerKey::query()->where('movie_id', $movie->id)->where('normalized', $form)->sole();

    expect($key->key_kind)->toBe(AnswerKeyKind::Alias)
        ->and($key->source_locale)->toBe(Locale::French->value)
        ->and($key->is_ambiguous)->toBeFalse()
        ->and(aliasTestKeys($movie))->not->toHaveKey((string) $prefix)
        ->and(aliasTestKeys($movie))->not->toHaveKey((string) $subtitle);

    // La fiche liste les formes acceptées, en lecture seule.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn ($page) => $page
            ->where('aliases.0.id', $alias->id)
            ->where('aliases.0.created_by', $this->curator->name)
            ->has('answer_keys', 2)
            ->where('answer_keys.1', ['form' => $form, 'kind' => AnswerKeyKind::Alias->value, 'is_ambiguous' => false]));

    // L'écran avertit qu'une forme est déjà acceptée — sans unicité sur le
    // texte : un second alias de même forme coexiste, une seule clé reste.
    aliasTestPreview($movie, 'SEIGNEUR DES BRUMES — le retour du nord')
        ->assertJsonPath('props.text_preview.form', $form)
        ->assertJsonPath('props.text_preview.accepted_as', AnswerKeyKind::Alias->value);

    aliasTestPost($movie, Locale::English->value, 'SEIGNEUR DES BRUMES — le retour du nord')->assertSessionHasNoErrors();

    expect(Alias::query()->where('movie_id', $movie->id)->count())->toBe(2)
        ->and(AnswerKey::query()->where('movie_id', $movie->id)->where('normalized', $form)->count())->toBe(1);

    // Un alias vide est refusé sous le champ.
    aliasTestPost($movie, Locale::French->value, '   ')->assertSessionHasErrors('alias');

    // Un alias qui reprend une forme DÉRIVÉE d'un titre du film n'est pas
    // redondant : il la rend exacte, donc toujours acceptée (10 § 3.5). Ici
    // le préfixe d'un brouillon, refusé seul parce qu'un film publié le
    // porte, et son sous-titre, que personne d'autre ne porte.
    $saga = aliasTestFilm('Vent d’Écume : Le Premier Rivage');
    $published = Movie::factory()->create([
        'title_original' => 'Vent d’Écume : Le Dernier Rivage',
        'title_original_latin' => null,
        'availability' => ContentAvailability::Published,
        'first_published_at' => now(),
    ]);
    (new AnswerKeyProjector)->project($published);

    $prefixForm = AnswerKeyNormalizer::normalize('Vent d’Écume');

    expect(aliasTestKeys($saga)[$prefixForm] ?? null)->toBe(AnswerKeyKind::Prefix->value);

    aliasTestPreview($saga, 'VENT D’ÉCUME')
        ->assertJsonPath('props.text_preview.form', $prefixForm)
        ->assertJsonPath('props.text_preview.accepted_as', null)
        ->assertJsonPath('props.text_preview.promoted_from', ['kind' => AnswerKeyKind::Prefix->value, 'is_ambiguous' => true]);

    aliasTestPreview($saga, 'Le Premier Rivage')
        ->assertJsonPath('props.text_preview.accepted_as', null)
        ->assertJsonPath('props.text_preview.promoted_from', ['kind' => AnswerKeyKind::Subtitle->value, 'is_ambiguous' => false]);

    aliasTestPost($saga, Locale::French->value, 'Vent d’Écume')->assertSessionHasNoErrors();

    /** @var AnswerKey $promoted */
    $promoted = AnswerKey::query()->where('movie_id', $saga->id)->where('normalized', $prefixForm)->sole();

    expect($promoted->key_kind)->toBe(AnswerKeyKind::Alias)
        ->and($promoted->is_ambiguous)->toBeFalse();

    aliasTestPreview($saga, 'Vent d’Écume')
        ->assertJsonPath('props.text_preview.accepted_as', AnswerKeyKind::Alias->value)
        ->assertJsonPath('props.text_preview.promoted_from', null);
});

test('retirer un alias TMDB supprime sa clé', function (): void {
    $movie = aliasTestFilm('Glasgarten');

    $alias = Alias::factory()->tmdb()->forLocale(Locale::French)->accepting('Le Verger de cristal')->create([
        'movie_id' => $movie->id,
    ]);

    (new AnswerKeyProjector)->project($movie);

    $form = AnswerKeyNormalizer::normalize('Le Verger de cristal');

    expect(aliasTestKeys($movie)[$form] ?? null)->toBe(AnswerKeyKind::Alias->value);

    // L'alias d'un AUTRE film n'est pas adressable depuis celui-ci.
    $stranger = aliasTestFilm('Nachtzug');

    $this->actingAs($this->curator)
        ->delete(route('admin.catalog.aliases.destroy', ['movie' => $stranger->id, 'alias' => $alias->id]))
        ->assertNotFound();

    expect(Alias::query()->whereKey($alias->id)->exists())->toBeTrue();

    // Tout alias se retire, TMDB compris — et sa clé avec lui.
    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->delete(route('admin.catalog.aliases.destroy', ['movie' => $movie->id, 'alias' => $alias->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertiaFlash('toast.message', aliasTestText('admin.movie.aliases.flash.removed'));

    expect(Alias::query()->whereKey($alias->id)->exists())->toBeFalse()
        ->and(aliasTestKeys($movie))->not->toHaveKey($form)
        ->and(aliasTestKeys($movie))->toHaveKey(AnswerKeyNormalizer::normalize('Glasgarten'));
});

test('un alias d\'une locale non activée est refusé', function (): void {
    $movie = aliasTestFilm('Glasgarten');
    $before = aliasTestKeys($movie);

    // `ja` est une locale de CATALOGUE admise sur `alias.locale`, mais pas
    // une langue activée du jeu : l'alias n'y serait accepté nulle part.
    aliasTestPost($movie, 'ja', 'Garasu no niwa')
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['locale' => aliasTestText('admin.movie.aliases.locale_not_enabled')]);

    expect(Alias::query()->where('movie_id', $movie->id)->exists())->toBeFalse()
        ->and(aliasTestKeys($movie))->toBe($before);
});
