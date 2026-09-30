<?php

namespace App\Support\Game;

use App\Enums\Locale;
use App\Enums\OriginalTitleForm;
use App\Models\Movie;
use App\Support\I18n\DisplayTitleResolver;
use App\Support\Scoring\Scoreboard;

/**
 * `RevealMovie` — le paquet de titres d'un film (spec 60 § 9.5 et § 11.5,
 * contrat C7 § 3, R-24). **Seul constructeur** de la forme, appelé par
 * `RevealRound` (`round.revealed`), par `GameStateBuilder` (`round.reveal`,
 * L60-12) et par {@see Scoreboard::podium()} (paquet de titres du
 * récapitulatif, `TitlePacket = RevealMovie`, L80-5) : une seule composition,
 * pour que la révélation et le récapitulatif ne divergent jamais. Miroir de
 * `RevealMovie` dans `resources/js/types/game-wire.ts`, clés dans l'ordre du
 * type.
 *
 * | Champ                | Provenance                                                            |
 * |----------------------|-----------------------------------------------------------------------|
 * | `titles`             | pour **chaque** {@see Locale::cases()}, {@see DisplayTitleResolver::resolve()} |
 * | `originalTitle`      | `movie.title_original`                                                |
 * | `originalTitleLatin` | `movie.title_original_latin`                                          |
 * | `originalLanguage`   | `movie.original_language`                                             |
 * | `year`               | `movie.release_year` — discriminant des homonymes et des remakes      |
 *
 * **`lang` de chaque titre** (05 § Attribut `lang`, R-24) : `Locale::bcp47()`
 * de la locale **atteinte** par la chaîne de repli (rangs 1 et 2) ; au rang 3,
 * la langue originale du film, suffixée `-Latn` quand la translittération est
 * servie ({@see OriginalTitleForm::Transliterated}, la règle même de
 * {@see DisplayTitleResolver::original()}). Un titre rendu par repli n'est
 * donc jamais annoncé dans la langue du joueur.
 *
 * Le paquet porte les titres de **toutes** les locales activées : une
 * révélation déjà affichée ne se recompose pas au changement de langue (05),
 * chaque client choisit le sien (`reveal-titles.ts`, L60-9). **Jamais** un
 * alias (un alias est une clé de validation, jamais un rendu, 10 § A4), ni le
 * genre, le studio ou la durée du film (principe 2), ni aucun identifiant
 * (§ 11.7).
 *
 * **Ne décide pas QUAND il part** : le paquet ne quitte le serveur qu'à
 * `ended_at + tier_grace_ms` (`RevealRound`), jamais avant (règle 3) —
 * chaque appelant tient sa garde, cette classe compose.
 *
 * @phpstan-type RevealTitlePayload array{text: string, lang: string}
 * @phpstan-type RevealMoviePayload array{titles: array<string, RevealTitlePayload>, originalTitle: string, originalTitleLatin: string|null, originalLanguage: string, year: int|null}
 */
final class RevealMovieBuilder
{
    /** Sous-étiquette d'écriture BCP 47 d'une translittération latine. */
    private const string LATIN_SCRIPT_SUBTAG = '-Latn';

    /**
     * Le paquet de titres du film. Les titres de catalogue sont lus par la
     * relation `titles`, chargée ici si l'appelant ne l'a pas fait.
     *
     * @return RevealMoviePayload
     */
    public static function build(Movie $movie): array
    {
        $movie->loadMissing('titles');

        $resolver = app(DisplayTitleResolver::class);
        $titles = [];

        foreach (Locale::cases() as $locale) {
            $resolved = $resolver->resolve($movie, $locale);

            $titles[$locale->value] = [
                'text' => $resolved->text,
                'lang' => $resolved->locale?->bcp47() ?? self::originalLang($movie),
            ];
        }

        return [
            'titles' => $titles,
            'originalTitle' => $movie->title_original,
            'originalTitleLatin' => $movie->title_original_latin,
            'originalLanguage' => $movie->original_language,
            'year' => $movie->release_year,
        ];
    }

    /**
     * `lang` d'un titre rendu au rang 3 : la langue originale du film,
     * suffixée `-Latn` quand c'est sa translittération qui est servie.
     */
    private static function originalLang(Movie $movie): string
    {
        return OriginalTitleForm::of($movie) === OriginalTitleForm::Transliterated
            ? $movie->original_language.self::LATIN_SCRIPT_SUBTAG
            : $movie->original_language;
    }
}
