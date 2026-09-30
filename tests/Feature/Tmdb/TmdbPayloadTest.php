<?php

use App\Support\Tmdb\TmdbDiscoverQuery;
use App\Support\Tmdb\TmdbErrorKind;
use App\Support\Tmdb\TmdbException;
use App\Support\Tmdb\TmdbImageSet;
use App\Support\Tmdb\TmdbMovie;
use App\Support\Tmdb\TmdbPage;
use App\Support\Tmdb\TmdbTitleKind;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| DTO TMDB — aucun `mixed` ne survit à un `fromArray()`
|--------------------------------------------------------------------------
|
| Ces épreuves ne touchent ni le réseau ni la base : elles éprouvent la
| traduction d'une charge utile figée en valeurs typées, et le refus net de
| toute forme hors contrat.
|
*/

it('lit une fiche complète sans en inventer une valeur', function (): void {
    $movie = TmdbMovie::fromArray(TmdbFixture::array('movie-987654'));

    expect($movie->tmdbId)->toBe(987654)
        ->and($movie->title)->toBe('The Glass Orchard')
        ->and($movie->originalTitle)->toBe('ガラスの果樹園')
        ->and($movie->originalLanguage)->toBe('ja')
        ->and($movie->releaseDate)->toBe('1998-07-18')
        ->and($movie->releaseYear())->toBe(1998)
        ->and($movie->voteCount)->toBe(2410)
        ->and($movie->adult)->toBeFalse()
        ->and($movie->collectionTmdbId)->toBe(445566)
        ->and($movie->collectionName)->toBe('Quintane Saga Collection')
        ->and($movie->genreIds)->toBe([16, 18])
        ->and($movie->productionCompanyIds)->toBe([7711, 7712]);
});

it('rend les certifications brutes, tous pays, sans en élire aucune', function (): void {
    $movie = TmdbMovie::fromArray(TmdbFixture::array('movie-987654'));

    expect($movie->releaseDates)->toHaveCount(4);

    $french = $movie->releaseDatesFor('fr');

    expect($french)->toHaveCount(2)
        ->and($french[0]->certification)->toBe('-16')
        ->and($french[0]->releasedOn)->toBe('1999-02-10')
        ->and($french[0]->countryCode)->toBe('FR')
        ->and($french[1]->certification)->toBe('-12')
        ->and($french[1]->releasedOn)->toBe('2014-05-21')
        ->and($french[1]->hasCertification())->toBeTrue();

    $japanese = $movie->releaseDatesFor('JP');

    expect($japanese[0]->certification)->toBe('')
        ->and($japanese[0]->hasCertification())->toBeFalse();
});

it('rend les titres candidats avec leur provenance, sans en promouvoir un', function (): void {
    $movie = TmdbMovie::fromArray(TmdbFixture::array('movie-987654'));

    $translations = $movie->titlesOfKind(TmdbTitleKind::Translation);
    $alternatives = $movie->titlesOfKind(TmdbTitleKind::Alternative);

    expect($translations)->toHaveCount(2)
        ->and($translations[0]->languageCode)->toBe('en')
        ->and($translations[0]->title)->toBe('The Glass Orchard')
        ->and($translations[1]->languageCode)->toBe('fr')
        ->and($translations[1]->title)->toBe('Le Verger de verre');

    expect($alternatives)->toHaveCount(2)
        ->and($alternatives[0]->title)->toBe('Garasu no Kajuen')
        ->and($alternatives[0]->type)->toBe('Romaji')
        ->and($alternatives[0]->countryCode)->toBe('JP');
});

it('lit une fiche dépouillée sans rien recopier ni rien deviner', function (): void {
    $movie = TmdbMovie::fromArray(TmdbFixture::array('movie-987655-minimal'));

    expect($movie->collectionTmdbId)->toBeNull()
        ->and($movie->collectionName)->toBeNull()
        ->and($movie->releaseDate)->toBeNull()
        ->and($movie->releaseYear())->toBeNull()
        ->and($movie->voteCount)->toBe(0)
        ->and($movie->genreIds)->toBe([])
        ->and($movie->productionCompanyIds)->toBe([])
        ->and($movie->releaseDates)->toBe([])
        ->and($movie->titles)->toBe([]);
});

it('distingue un visuel sans langue d’un visuel qui en porte une', function (): void {
    $images = TmdbImageSet::fromArray(TmdbFixture::array('movie-987654-images'));

    expect($images->backdrops[0]->isLanguageNeutral())->toBeTrue()
        ->and($images->backdrops[0]->filePath)->toBe('/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg')
        ->and($images->backdrops[0]->width)->toBe(1920)
        ->and($images->backdrops[0]->height)->toBe(1080)
        ->and($images->backdrops[1]->isLanguageNeutral())->toBeTrue()
        ->and($images->backdrops[2]->isLanguageNeutral())->toBeFalse()
        ->and($images->backdrops[2]->languageCode)->toBe('en');
});

it('borne la pagination au plafond de TMDB et non au total annoncé', function (): void {
    $page = new TmdbPage(page: 500, totalPages: 900, totalResults: 18_000, results: []);

    expect($page->hasMore())->toBeFalse()
        ->and($page->nextPage())->toBeNull()
        ->and($page->isEmpty())->toBeTrue();
});

it('refuse une charge utile hors contrat plutôt que d’écrire un film vide', function (): void {
    $payload = TmdbFixture::array('movie-987654');
    unset($payload['id']);

    $exception = null;

    try {
        TmdbMovie::fromArray($payload);
    } catch (TmdbException $caught) {
        $exception = $caught;
    }

    expect($exception?->kind)->toBe(TmdbErrorKind::Malformed)
        ->and($exception?->getMessage())->toContain('movie.id');
});

it('refuse un identifiant d’étiquette non entier', function (): void {
    $payload = TmdbFixture::array('movie-987654');
    $payload['genres'] = [['id' => 'seize', 'name' => 'Animation']];

    expect(fn () => TmdbMovie::fromArray($payload))->toThrow(TmdbException::class);
});

it('accepte l’absence complète des append_to_response', function (): void {
    $payload = TmdbFixture::array('movie-987654');
    unset($payload['release_dates'], $payload['translations'], $payload['alternative_titles']);

    $movie = TmdbMovie::fromArray($payload);

    expect($movie->releaseDates)->toBe([])
        ->and($movie->titles)->toBe([]);
});

it('n’expose jamais include_adult comme un réglage', function (): void {
    $parameters = (new TmdbDiscoverQuery)->toQueryParameters();

    expect($parameters['include_adult'])->toBe('false')
        ->and($parameters['sort_by'])->toBe('vote_count.desc')
        ->and($parameters)->not->toHaveKey('page');
});

it('rejette un critère de balayage hors format', function (?string $language, ?int $votes, ?int $year, string $sort): void {
    expect(fn () => new TmdbDiscoverQuery(
        originalLanguage: $language,
        minVoteCount: $votes,
        minReleaseYear: $year,
        sortBy: $sort,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'langue hors ISO 639-1' => ['fr-FR', null, null, TmdbDiscoverQuery::DEFAULT_SORT],
    'notoriété négative' => [null, -1, null, TmdbDiscoverQuery::DEFAULT_SORT],
    'année impossible' => [null, null, 1200, TmdbDiscoverQuery::DEFAULT_SORT],
    'tri hors format' => [null, null, null, 'vote_count'],
]);

it('rejoue le même balayage sur une autre langue d’origine', function (): void {
    $query = new TmdbDiscoverQuery(originalLanguage: 'fr', minVoteCount: 500, minReleaseYear: 1970);
    $japanese = $query->forOriginalLanguage('ja');

    expect($japanese->originalLanguage)->toBe('ja')
        ->and($japanese->minVoteCount)->toBe(500)
        ->and($japanese->minReleaseYear)->toBe(1970)
        ->and($query->originalLanguage)->toBe('fr');
});

it('nomme chaque cas d’échec par une clé du domaine admin', function (TmdbErrorKind $kind): void {
    expect($kind->translationKey())->toBe('admin.tmdb.error.'.$kind->value);
})->with(TmdbErrorKind::cases());
