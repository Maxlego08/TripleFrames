<?php

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\AnswerKeyProjector;

/*
|--------------------------------------------------------------------------
| Projecteur d'answer_key : sous-titre dérivé — spec 70 § 4.1, § 5.7, lot L70-2
|--------------------------------------------------------------------------
|
| D23 du 23/09 : la partie APRÈS le premier séparateur d'un titre est une
| clé dérivée, `subtitle`, soumise à la même règle de collision que le
| préfixe — acceptée sauf si un autre film `published` porte la même forme,
| mesurée sur le catalogue publié entier. Elle ne naît que des titres
| (`title_original`, `title_original_latin`, `movie_title` des locales
| activées), jamais d'un alias, et cède devant toute nature exacte puis
| devant le préfixe.
|
*/

/**
 * Les clés d'un film, nature par forme normalisée, triées par forme.
 *
 * @return array<string, AnswerKeyKind>
 */
function projectorKinds(Movie $movie): array
{
    /** @var array<string, AnswerKeyKind> $kinds */
    $kinds = AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->get()
        ->mapWithKeys(static fn (AnswerKey $key): array => [$key->normalized => $key->key_kind])
        ->all();

    ksort($kinds);

    return $kinds;
}

/**
 * Les formes d'une nature donnée pour un film, triées.
 *
 * @return list<string>
 */
function projectorFormsOf(Movie $movie, AnswerKeyKind $kind): array
{
    /** @var list<string> $forms */
    $forms = AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->where('key_kind', $kind->value)
        ->orderBy('normalized')
        ->pluck('normalized')
        ->all();

    return $forms;
}

function projectorKey(Movie $movie, string $normalized): AnswerKey
{
    return AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->where('normalized', $normalized)
        ->sole();
}

it('dérive le sous-titre des titres et jamais des alias', function (): void {
    $alias = 'Port en fête - Nuit blanche';
    $foreign = 'Hafenlichter: Die lange Nacht';

    $movie = Movie::factory()->playable(
        titles: [
            'en' => 'Harbour Lights: The Long Night',
            'fr' => 'Les Feux du port : La Longue Nuit',
            // Locale non activée : un titre affichable, jamais une chaîne
            // acceptée, donc jamais la source d'une clé dérivée.
            'de' => $foreign,
        ],
        aliases: ['fr' => [$alias]],
    )->create([
        'title_original' => '港の灯り',
        'title_original_latin' => 'Minato no Akari: Nagai Yoru',
    ]);

    // Les trois sources de titre, chacune avec son séparateur : le titre
    // original latin, le titre EN (« : » collé) et le titre FR (« : » espacé).
    expect(projectorFormsOf($movie, AnswerKeyKind::Subtitle))->toBe(['long night', 'longue nuit', 'nagai yoru'])
        ->and(projectorFormsOf($movie, AnswerKeyKind::Prefix))->toBe(['feux du port', 'harbour lights', 'minato no akari']);

    // Traçabilité seule, comme pour le préfixe : une même forme peut naître de
    // deux titres de locales différentes. Aucun autre film ne la porte.
    foreach (projectorFormsOf($movie, AnswerKeyKind::Subtitle) as $subtitle) {
        $key = projectorKey($movie, $subtitle);

        expect($key->source_locale)->toBeNull()
            ->and($key->is_ambiguous)->toBeFalse();
    }

    // L'alias a bien un séparateur — le test n'est pas vacant —, mais ni son
    // préfixe ni son sous-titre n'entrent dans `answer_key` : seul l'alias
    // entier y est, de nature exacte.
    $aliasPrefix = AnswerKeyNormalizer::prefixOf($alias);
    $aliasSubtitle = AnswerKeyNormalizer::subtitleOf($alias);

    expect($aliasPrefix)->toBe('port en fete')
        ->and($aliasSubtitle)->toBe('nuit blanche')
        ->and(projectorKinds($movie))->not->toHaveKey($aliasPrefix)
        ->and(projectorKinds($movie))->not->toHaveKey($aliasSubtitle)
        ->and(projectorKey($movie, AnswerKeyNormalizer::normalize($alias))->key_kind)->toBe(AnswerKeyKind::Alias);

    // Le titre d'une locale non activée ne dérive rien non plus.
    $foreignSubtitle = AnswerKeyNormalizer::subtitleOf($foreign);

    expect($foreignSubtitle)->toBe('die lange nacht')
        ->and(projectorKinds($movie))->not->toHaveKey($foreignSubtitle)
        ->and(projectorKinds($movie))->not->toHaveKey(AnswerKeyNormalizer::normalize($foreign));
});

it("recompte l'ambiguïté des sous-titres comme celle des préfixes", function (): void {
    $projector = new AnswerKeyProjector;

    $first = Movie::factory()->playable(
        titles: ['en' => 'Ember Road: Second Dawn', 'fr' => 'La Route des braises'],
        aliases: ['fr' => []],
    )->create(['title_original' => 'Ember Road: Second Dawn']);

    expect(projectorKey($first, 'second dawn')->key_kind)->toBe(AnswerKeyKind::Subtitle)
        ->and(projectorKey($first, 'second dawn')->is_ambiguous)->toBeFalse()
        ->and(projectorKey($first, 'ember road')->key_kind)->toBe(AnswerKeyKind::Prefix)
        ->and(projectorKey($first, 'ember road')->is_ambiguous)->toBeFalse();

    // Un brouillon qui porte le même sous-titre ne compte pas : l'ambiguïté se
    // mesure sur le catalogue PUBLIÉ entier, jamais sur ce qui s'y prépare. Le
    // drapeau se lit clé par clé (10 § 3.5) : la clé du brouillon, elle, est
    // ambiguë, parce qu'un AUTRE film publié porte sa forme.
    $draft = Movie::factory()->playable(
        titles: ['en' => 'Frost Line: Second Dawn', 'fr' => 'La Ligne de givre'],
        aliases: ['fr' => []],
    )->create([
        'title_original' => 'Frost Line: Second Dawn',
        'availability' => ContentAvailability::Draft,
        'availability_changed_at' => null,
    ]);

    expect(projectorKey($draft, 'second dawn')->key_kind)->toBe(AnswerKeyKind::Subtitle)
        ->and(projectorKey($first, 'second dawn')->is_ambiguous)->toBeFalse()
        ->and(projectorKey($draft, 'second dawn')->is_ambiguous)->toBeTrue()
        ->and(projectorKey($draft, 'frost line')->is_ambiguous)->toBeFalse();

    // Le même mécanisme, côté préfixe : un second film publié le partage.
    $third = Movie::factory()->playable(
        titles: ['en' => 'Ember Road: Ashfall', 'fr' => 'Cendres sur la route'],
        aliases: ['fr' => []],
    )->create(['title_original' => 'Ember Road: Ashfall']);

    expect(projectorKey($first, 'ember road')->is_ambiguous)->toBeTrue()
        ->and(projectorKey($third, 'ember road')->is_ambiguous)->toBeTrue();

    // Le brouillon est publié : le sous-titre devient ambigu sur les DEUX
    // films, exactement comme le préfixe, par le même recompte synchrone.
    $draft->availability = ContentAvailability::Published;
    $draft->save();
    $projector->recomputeAmbiguity(['second dawn', 'frost line']);

    expect(projectorKey($first, 'second dawn')->is_ambiguous)->toBeTrue()
        ->and(projectorKey($draft, 'second dawn')->is_ambiguous)->toBeTrue()
        ->and(projectorKey($draft, 'frost line')->is_ambiguous)->toBeFalse();

    // Dépublié, il cesse de peser : le drapeau retombe sur le film publié, et
    // ses clés sont conservées pour qu'une republication soit un simple
    // basculement. La sienne reste ambiguë : un film dépublié en pleine manche
    // garde une manche jugeable (spec 70 § 6.1), et la forme exacte y serait
    // refusée par la garde (c) — la tolérance (d) ne doit pas l'accepter.
    $draft->availability = ContentAvailability::Unpublished;
    $draft->save();
    $projector->recomputeAmbiguity(['second dawn']);

    expect(projectorKey($first, 'second dawn')->is_ambiguous)->toBeFalse()
        ->and(projectorKey($draft, 'second dawn')->is_ambiguous)->toBeTrue();

    // L'homonymie se mesure sous QUELQUE NATURE que ce soit : un film publié
    // dont le titre entier est « Second Dawn » rend le sous-titre ambigu, et sa
    // propre clé, exacte, ne l'est jamais.
    $fourth = Movie::factory()->playable(
        titles: ['en' => 'Second Dawn', 'fr' => 'La Seconde Aube'],
        aliases: ['fr' => []],
    )->create(['title_original' => 'Second Dawn']);

    expect(projectorKey($first, 'second dawn')->is_ambiguous)->toBeTrue()
        ->and(projectorKey($draft, 'second dawn')->is_ambiguous)->toBeTrue()
        ->and(projectorKey($fourth, 'second dawn')->key_kind)->toBe(AnswerKeyKind::TitleOriginal)
        ->and(projectorKey($fourth, 'second dawn')->is_ambiguous)->toBeFalse();
});

it("une nature exacte l'emporte sur prefix, qui l'emporte sur subtitle", function (): void {
    $original = 'Crimson Lantern: Silver Tide';
    $french = 'Silver Tide : La Lanterne';

    $movie = Movie::factory()->playable(
        titles: ['en' => $original, 'fr' => $french],
        aliases: ['fr' => ['Crimson Lantern', 'La Lanterne']],
    )->create(['title_original' => $original]);

    // Chaque forme ci-dessous est bien dérivable : sans la précédence, elle
    // serait une clé dérivée — et `silver tide` naît d'abord comme sous-titre
    // du titre original, projeté avant le titre FR dont elle est le préfixe.
    expect(AnswerKeyNormalizer::prefixOf($original))->toBe('crimson lantern')
        ->and(AnswerKeyNormalizer::subtitleOf($original))->toBe('silver tide')
        ->and(AnswerKeyNormalizer::prefixOf($french))->toBe('silver tide')
        ->and(AnswerKeyNormalizer::subtitleOf($french))->toBe('lanterne');

    expect(projectorKinds($movie))->toBe([
        // Alias > prefix.
        'crimson lantern' => AnswerKeyKind::Alias,
        'crimson lantern silver tide' => AnswerKeyKind::TitleOriginal,
        // Alias > subtitle.
        'lanterne' => AnswerKeyKind::Alias,
        // Prefix > subtitle, quel que soit l'ordre des titres.
        'silver tide' => AnswerKeyKind::Prefix,
        'silver tide la lanterne' => AnswerKeyKind::Title,
    ]);

    // Un autre film publié porte « Silver Tide » en titre entier : le préfixe
    // devient ambigu.
    Movie::factory()->playable(
        titles: ['en' => 'Silver Tide', 'fr' => 'La Marée d’argent'],
        aliases: ['fr' => []],
    )->create(['title_original' => 'Silver Tide']);

    $silverTide = projectorKey($movie, 'silver tide');
    $crimsonLantern = projectorKey($movie, 'crimson lantern');

    expect($silverTide->is_ambiguous)->toBeTrue();

    // Requalification par différence, identifiants conservés : le titre FR
    // retiré, `silver tide` n'est plus que le sous-titre du titre original, et
    // reste ambigu ; l'alias retiré, `crimson lantern` redevient un préfixe.
    MovieTitle::query()->where('movie_id', $movie->id)->where('locale', 'fr')->delete();
    Alias::query()->where('movie_id', $movie->id)->where('alias', 'Crimson Lantern')->delete();

    (new AnswerKeyProjector)->project($movie);

    expect(projectorKinds($movie))->toBe([
        'crimson lantern' => AnswerKeyKind::Prefix,
        'crimson lantern silver tide' => AnswerKeyKind::TitleOriginal,
        'lanterne' => AnswerKeyKind::Alias,
        'silver tide' => AnswerKeyKind::Subtitle,
    ])
        ->and(projectorKey($movie, 'silver tide')->id)->toBe($silverTide->id)
        ->and(projectorKey($movie, 'silver tide')->is_ambiguous)->toBeTrue()
        ->and(projectorKey($movie, 'crimson lantern')->id)->toBe($crimsonLantern->id)
        ->and(projectorKey($movie, 'crimson lantern')->is_ambiguous)->toBeFalse();

    // Un alias curé reprend le sous-titre mot pour mot : la nature exacte
    // l'emporte, l'identifiant survit, et le drapeau d'ambiguïté tombe — un
    // titre complet ou un alias est toujours accepté, homonyme publié ou non.
    Alias::factory()->create([
        'movie_id' => $movie->id,
        'locale' => 'en',
        'alias' => 'Silver Tide',
    ]);

    (new AnswerKeyProjector)->project($movie);

    expect(projectorKey($movie, 'silver tide')->key_kind)->toBe(AnswerKeyKind::Alias)
        ->and(projectorKey($movie, 'silver tide')->id)->toBe($silverTide->id)
        ->and(projectorKey($movie, 'silver tide')->is_ambiguous)->toBeFalse();
});
