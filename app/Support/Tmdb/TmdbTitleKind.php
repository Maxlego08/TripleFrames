<?php

namespace App\Support\Tmdb;

/**
 * D'où vient un titre secondaire renvoyé par TMDB.
 *
 * Les deux n'ont ni la même clé ni la même destination en base, et les
 * confondre ferait entrer un titre de travail dans une colonne d'affichage :
 *
 * - `Translation` — `append_to_response=translations`, porté par une LANGUE.
 *   C'est le candidat de `movie_title` (au plus un par couple film/locale).
 * - `Alternative` — `append_to_response=alternative_titles`, porté par un PAYS
 *   et un `type` libre (`Romaji`, `working title`, titre de ressortie). C'est
 *   le candidat d'`alias`, table de VALIDATION pure jamais affichée, et la
 *   seule source possible de `movie.title_original_latin` (arbitrage A4).
 *
 * Le tri entre les deux destinations appartient au service d'import : ce client
 * rend les titres tels que TMDB les nomme, sans jamais en promouvoir un.
 */
enum TmdbTitleKind: string
{
    case Translation = 'translation';

    case Alternative = 'alternative';
}
