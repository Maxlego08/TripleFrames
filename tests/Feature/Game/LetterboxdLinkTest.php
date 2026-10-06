<?php

use App\Models\Movie;
use App\Support\Game\RevealMovieBuilder;

/*
 * Le lien Letterboxd du film révélé (D58 du 06/10) : composé par le seul
 * `RevealMovieBuilder`, donc identique à la révélation, à la resynchronisation
 * et au récapitulatif du podium ; aucun appel réseau.
 */

it('compose la fiche Letterboxd du film par son identifiant TMDB', function (): void {
    $movie = Movie::factory()->create(['tmdb_id' => 129]);

    expect(RevealMovieBuilder::build($movie)['letterboxdUrl'])->toBe('https://letterboxd.com/tmdb/129/');
});

it('ne compose aucun lien pour un film sans identifiant TMDB', function (): void {
    $movie = Movie::factory()->demo()->create();

    expect(RevealMovieBuilder::build($movie)['letterboxdUrl'])->toBeNull();
});
