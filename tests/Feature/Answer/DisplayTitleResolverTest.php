<?php

use App\Enums\Locale;
use App\Enums\OriginalTitleForm;
use App\Models\Alias;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Support\I18n\DisplayTitleResolver;
use App\ValueObjects\I18n\ResolvedTitle;

/*
|--------------------------------------------------------------------------
| Chaîne de repli d'affichage d'un titre — spec 70 § 10.5, spec 05, L70-7
|--------------------------------------------------------------------------
|
| `DisplayTitleResolver` est l'UNIQUE implémentation de la chaîne de 05 :
| `movie_title` de la locale demandée (rang 1), d'une autre locale activée
| par rang de repli (rang 2), puis le titre original ou sa translittération
| (rang 3). Jamais un alias, jamais un titre d'une locale non activée.
|
| Tous les titres sont inventés : aucune donnée de catalogue réelle.
|
*/

/**
 * Un film aux titres, alias et titre original voulus, relu sans relation
 * chargée : le résolveur lit `titles` comme en production.
 *
 * @param  array<string, string>  $titles  locale de catalogue => titre
 * @param  array<string, string>  $aliases  locale de catalogue => alias
 */
function titleResolverMovie(
    array $titles,
    string $original = 'Nebelturm Der Erfundenen Stadt',
    ?string $latin = null,
    array $aliases = [],
): Movie {
    $movie = Movie::factory()->create([
        'title_original' => $original,
        'title_original_latin' => $latin,
    ]);

    foreach ($titles as $locale => $title) {
        MovieTitle::factory()->for($movie)->forLocale($locale)->titled($title)->create();
    }

    foreach ($aliases as $locale => $alias) {
        Alias::factory()->for($movie)->create(['locale' => $locale, 'alias' => $alias]);
    }

    return Movie::query()->findOrFail($movie->id);
}

/**
 * Un film en mémoire, jamais écrit : la forme du titre original ne lit que
 * deux colonnes de `movie`.
 */
function titleResolverForm(string $original, ?string $latin = null): OriginalTitleForm
{
    return OriginalTitleForm::of(Movie::factory()->make([
        'title_original' => $original,
        'title_original_latin' => $latin,
    ]));
}

it('suit la locale du joueur, puis les autres locales activées par rang de repli, puis le titre original', function () {
    $resolver = app(DisplayTitleResolver::class);

    $both = titleResolverMovie([
        Locale::English->value => 'The Lighthouse Of Invented Mists',
        Locale::French->value => 'Le Phare Des Brumes Inventées',
    ]);

    foreach ([
        Locale::French->value => 'Le Phare Des Brumes Inventées',
        Locale::English->value => 'The Lighthouse Of Invented Mists',
    ] as $value => $text) {
        $locale = Locale::from($value);

        expect($resolver->resolve($both, $locale))
            ->toEqual(new ResolvedTitle($text, $locale, ResolvedTitle::RANK_REQUESTED_LOCALE));
    }

    // Un titre manque : la chaîne descend à l'autre locale ACTIVÉE, et le
    // rang 2 porte la locale atteinte (attribut `lang` du fragment, 05).
    $frenchOnly = titleResolverMovie([Locale::French->value => 'La Cloche Du Canal Muet']);
    $englishOnly = titleResolverMovie([Locale::English->value => 'The Mute Canal Bell']);

    expect($resolver->resolve($frenchOnly, Locale::English))
        ->toEqual(new ResolvedTitle('La Cloche Du Canal Muet', Locale::French, ResolvedTitle::RANK_OTHER_LOCALE))
        ->and($resolver->resolve($frenchOnly, Locale::French))
        ->toEqual(new ResolvedTitle('La Cloche Du Canal Muet', Locale::French, ResolvedTitle::RANK_REQUESTED_LOCALE))
        ->and($resolver->resolve($englishOnly, Locale::French))
        ->toEqual(new ResolvedTitle('The Mute Canal Bell', Locale::English, ResolvedTitle::RANK_OTHER_LOCALE));

    // Pour chaque locale activée, l'autre locale de repli est parcourue dans
    // l'ordre de `fallbackRank()` : la première qui porte un titre gagne.
    foreach (Locale::cases() as $requested) {
        $others = array_values(array_filter(Locale::cases(), static fn (Locale $locale): bool => $locale !== $requested));
        usort($others, static fn (Locale $a, Locale $b): int => $a->fallbackRank() <=> $b->fallbackRank());

        $titles = [];

        foreach ($others as $position => $other) {
            $titles[$other->value] = "Invented Fallback {$position} {$other->value}";
        }

        $movie = titleResolverMovie($titles);

        expect($resolver->resolve($movie, $requested))
            ->toEqual(new ResolvedTitle($titles[$others[0]->value], $others[0], ResolvedTitle::RANK_OTHER_LOCALE));
    }

    // Seules des locales de catalogue NON activées : ni `ja` ni `es` ne sont
    // jamais rendues, c'est le titre original qui répond, sans locale.
    $catalogueOnly = titleResolverMovie([
        'ja' => '霧の町の発明',
        'es' => 'La Torre De Niebla Inventada',
    ]);

    foreach (Locale::cases() as $locale) {
        expect($resolver->resolve($catalogueOnly, $locale))
            ->toEqual(new ResolvedTitle('Nebelturm Der Erfundenen Stadt', null, ResolvedTitle::RANK_ORIGINAL));
    }

    // Le rang et la locale vont ensemble : un titre original n'a pas de
    // locale, un titre de `movie_title` en a toujours une.
    expect(fn () => new ResolvedTitle('x', Locale::French, ResolvedTitle::RANK_ORIGINAL))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ResolvedTitle('x', null, ResolvedTitle::RANK_OTHER_LOCALE))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ResolvedTitle('x', Locale::French, ResolvedTitle::RANK_ORIGINAL + 1))
        ->toThrow(InvalidArgumentException::class);
});

it("rend la translittération d'un titre original non latin quand elle existe", function () {
    $resolver = app(DisplayTitleResolver::class);

    $transliterated = titleResolverMovie([], '霧の灯台の猫', 'Kiri No Todai No Neko');
    $native = titleResolverMovie([], '霧の灯台の犬');
    $cyrillic = titleResolverMovie([], 'Туманный Маяк Кота', 'Tumannyy Mayak Kota');
    $blankLatin = titleResolverMovie([], '霧の灯台の鳥', '   ');
    // Une translittération renseignée sur un titre DÉJÀ latin ne sert pas.
    $latinWithLatin = titleResolverMovie([], 'Le Phare Du Chat Inventé', 'Something Else Entirely');

    expect($resolver->original($transliterated))->toBe('Kiri No Todai No Neko')
        ->and($resolver->original($cyrillic))->toBe('Tumannyy Mayak Kota')
        ->and($resolver->original($native))->toBe('霧の灯台の犬')
        ->and($resolver->original($blankLatin))->toBe('霧の灯台の鳥')
        ->and($resolver->original($latinWithLatin))->toBe('Le Phare Du Chat Inventé');

    foreach (Locale::cases() as $locale) {
        expect($resolver->resolve($transliterated, $locale))
            ->toEqual(new ResolvedTitle('Kiri No Todai No Neko', null, ResolvedTitle::RANK_ORIGINAL))
            ->and($resolver->resolve($native, $locale))
            ->toEqual(new ResolvedTitle('霧の灯台の犬', null, ResolvedTitle::RANK_ORIGINAL));
    }

    // La translittération n'est que le dernier maillon : un titre dans une
    // locale activée passe devant elle.
    $translated = titleResolverMovie(
        [Locale::French->value => 'Le Chat Du Phare Brumeux'],
        '霧の灯台の魚',
        'Kiri No Todai No Sakana',
    );

    expect($resolver->resolve($translated, Locale::English))
        ->toEqual(new ResolvedTitle('Le Chat Du Phare Brumeux', Locale::French, ResolvedTitle::RANK_OTHER_LOCALE));
});

it('ne rend jamais un alias', function () {
    $resolver = app(DisplayTitleResolver::class);

    // Des alias dans les deux locales activées, aucun titre : c'est le titre
    // original qui répond, jamais un alias.
    $aliasOnly = titleResolverMovie([], 'Die Erfundene Nebelbrücke', null, [
        Locale::English->value => 'Mist Bridge',
        Locale::French->value => 'Pont Des Brumes',
    ]);

    // Un alias dans la locale demandée ne passe jamais devant le titre d'une
    // autre locale activée.
    $aliasInRequested = titleResolverMovie(
        [Locale::French->value => 'Le Pont Des Brumes Inventé'],
        'Die Erfundene Nebelbrücke Zwei',
        null,
        [Locale::English->value => 'The Invented Mist Bridge'],
    );

    // Un alias latin ne remplace jamais une translittération absente.
    $nativeWithAlias = titleResolverMovie([], '霧の橋の発明', null, [
        Locale::English->value => 'Kiri No Hashi',
    ]);

    foreach (Locale::cases() as $locale) {
        expect($resolver->resolve($aliasOnly, $locale))
            ->toEqual(new ResolvedTitle('Die Erfundene Nebelbrücke', null, ResolvedTitle::RANK_ORIGINAL))
            ->and($resolver->resolve($nativeWithAlias, $locale)->text)->toBe('霧の橋の発明');
    }

    expect($resolver->resolve($aliasInRequested, Locale::English))
        ->toEqual(new ResolvedTitle('Le Pont Des Brumes Inventé', Locale::French, ResolvedTitle::RANK_OTHER_LOCALE))
        ->and($resolver->original($nativeWithAlias))->toBe('霧の橋の発明');

    // Témoin : les alias existent bien en base.
    expect(Alias::query()->whereIn('movie_id', [$aliasOnly->id, $aliasInRequested->id, $nativeWithAlias->id])->count())
        ->toBe(4);
});

it('classe la forme du titre original en latin, translittéré ou natif sans ext-intl', function () {
    expect(array_map(static fn (OriginalTitleForm $form): string => $form->value, OriginalTitleForm::cases()))
        ->toBe(['latin', 'transliterated', 'native']);

    foreach ([
        // Latin : écritures Latin, Common (chiffres, ponctuation, espaces) et
        // Inherited (diacritiques combinants), pleine chasse latine comprise.
        'accents' => ['Le Phare Inventé', null, OriginalTitleForm::Latin],
        'ligatures' => ['Æther & Ødegård : Deuxième Partie', null, OriginalTitleForm::Latin],
        'chiffres et ponctuation' => ['2049 — Brumes, Encore ? (II)', null, OriginalTitleForm::Latin],
        'pleine chasse latine' => ['Ｂｒｕｍｅｓ', null, OriginalTitleForm::Latin],
        'diacritique combinant' => ["Cafe\u{0301} Des Brumes", null, OriginalTitleForm::Latin],
        'latin muni d’une translittération' => ['Le Phare Du Nord', 'Autre Chose', OriginalTitleForm::Latin],
        // Non latin, avec translittération.
        'kanji translittéré' => ['霧の灯台', 'Kiri No Todai', OriginalTitleForm::Transliterated],
        'cyrillique translittéré' => ['Туманный Маяк', 'Tumannyy Mayak', OriginalTitleForm::Transliterated],
        'mixte translittéré' => ['Brumes 霧', 'Brumes Kiri', OriginalTitleForm::Transliterated],
        // Non latin, sans translittération (ou translittération blanche).
        'kanji natif' => ['霧の灯台', null, OriginalTitleForm::Native],
        'hangul natif' => ['안개 등대', null, OriginalTitleForm::Native],
        'grec natif' => ['Ομίχλη Φάρου', null, OriginalTitleForm::Native],
        'mixte natif' => ['Brumes 霧', null, OriginalTitleForm::Native],
        'translittération blanche' => ['霧の灯台', " \u{00A0} ", OriginalTitleForm::Native],
        // Une chaîne invalide en UTF-8 n'est jamais tenue pour latine.
        'utf-8 invalide' => ["Brumes \xC3\x28", null, OriginalTitleForm::Native],
    ] as $label => [$original, $latin, $expected]) {
        expect(titleResolverForm($original, $latin))->toBe($expected, $label);
    }

    // Sans ext-intl : ni l'enum ni le résolveur n'emploient une API d'`intl`
    // (le classement est en PCRE, la translittération n'est jamais calculée).
    // `Locale` y désigne `App\Enums\Locale`, jamais la classe globale d'`intl`.
    $intlClasses = ['normalizer', 'transliterator', 'intlchar', 'collator', 'numberformatter', 'spoofchecker', 'resourcebundle'];

    foreach ([OriginalTitleForm::class, DisplayTitleResolver::class] as $class) {
        $file = (new ReflectionClass($class))->getFileName();

        expect($file)->toBeString();

        $source = (string) file_get_contents((string) $file);

        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $name = strtolower($token->text);
            $bare = ltrim($name, '\\');

            expect(in_array($bare, $intlClasses, true)
                || preg_match('/^(grapheme_|normalizer_|transliterator_|intl|idn_)/', $bare) === 1
                || $name === '\\locale')
                ->toBeFalse("{$class} : {$token->text}");
        }

        expect(preg_match('/^use\s+Locale\s*;/mi', $source))->toBe(0, $class);
    }
});
