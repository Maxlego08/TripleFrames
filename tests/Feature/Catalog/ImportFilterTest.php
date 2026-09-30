<?php

use App\Enums\CertificationCountry;
use App\Models\ImportRun;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\DiscoverCursor;
use App\Support\Catalog\RestrictiveCertifications;
use App\Support\Catalog\TmdbIdentifierList;
use App\Support\Catalog\TmdbQuotaLimiter;
use App\Support\Tmdb\TmdbPage;
use App\ValueObjects\Catalog\ImportFilter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| Le filtre de goût, la reprise et la lecture d'un collage
|--------------------------------------------------------------------------
|
| Aucune de ces classes ne touche au réseau ni à TMDB : ce sont les décisions
| que le service d'import prend AVANT d'écrire quoi que ce soit.
|
*/

it('lit ses trois défauts en configuration, jamais en littéral', function (): void {
    $filter = ImportFilter::default();

    expect($filter->minVoteCount)->toBe(500)
        ->and($filter->languages)->toBe(['fr', 'en', 'ja'])
        ->and($filter->minReleaseYear)->toBe(1970)
        ->and($filter->isWiderThanDefault())->toBeFalse();

    Config::set('catalog.import_filter.min_vote_count', 1_000);

    expect(ImportFilter::default()->minVoteCount)->toBe(1_000);
});

it('reconnaît un élargissement sur n’importe lequel des trois axes', function (): void {
    expect(ImportFilter::make(minVoteCount: 100)->isWiderThanDefault())->toBeTrue()
        ->and(ImportFilter::make(minReleaseYear: 1930)->isWiderThanDefault())->toBeTrue()
        ->and(ImportFilter::make(languages: ['fr', 'en', 'ja', 'ko'])->isWiderThanDefault())->toBeTrue()
        // Rétrécir n'est pas élargir : personne n'entre par exception.
        ->and(ImportFilter::make(minVoteCount: 5_000)->isWiderThanDefault())->toBeFalse()
        ->and(ImportFilter::make(languages: ['fr'])->isWiderThanDefault())->toBeFalse();

    // Rétrécir un axe tout en élargissant un autre RESTE un élargissement : un
    // balayage qui va chercher du coréen à 5 000 votes fait bien entrer des
    // films que le filtre par défaut n'aurait jamais vus.
    expect(ImportFilter::make(minVoteCount: 5_000, languages: ['ko'])->isWiderThanDefault())->toBeTrue();
});

it('pose les trois motifs, et les cumule', function (): void {
    $filter = ImportFilter::default();

    expect($filter->exceptionMotivesFor('en', 900, 1994)->any())->toBeFalse();

    $disney = $filter->exceptionMotivesFor('en', 120, 1937);

    expect($disney->forLanguage)->toBeFalse()
        ->and($disney->forVoteCount)->toBeTrue()
        ->and($disney->forReleaseYear)->toBeTrue()
        ->and($disney->any())->toBeTrue()
        ->and($disney->toAttributes())->toBe([
            'exception_for_language' => false,
            'exception_for_vote_count' => true,
            'exception_for_release_year' => true,
        ]);

    $parasite = $filter->exceptionMotivesFor('ko', 17_000, 2019);

    expect($parasite->forLanguage)->toBeTrue()
        ->and($parasite->forVoteCount)->toBeFalse()
        ->and($parasite->forReleaseYear)->toBeFalse();
});

it('se relit depuis les trois colonnes d’un balayage, et s’y réécrit à l’identique', function (): void {
    $run = ImportRun::factory()->create([
        'filter_min_vote_count' => 250,
        'filter_languages' => 'fr,ko',
        'filter_min_release_year' => 1950,
    ]);

    $filter = ImportFilter::fromColumns(
        $run->filter_min_vote_count,
        $run->filter_languages,
        $run->filter_min_release_year,
    );

    expect($filter->minVoteCount)->toBe(250)
        ->and($filter->languages)->toBe(['fr', 'ko'])
        ->and($filter->minReleaseYear)->toBe(1950)
        ->and($filter->toColumns())->toBe([
            'filter_min_vote_count' => 250,
            'filter_languages' => 'fr,ko',
            'filter_min_release_year' => 1950,
        ]);
});

it('refuse de balayer une langue absente du filtre', function (): void {
    ImportFilter::default()->discoverQueryFor('ko');
})->throws(InvalidArgumentException::class);

it('n’envoie qu’une langue par requête de balayage', function (): void {
    // `with_original_language` n'accepte qu'UNE langue : trois langues, trois
    // séries d'appels — et c'est la raison d'être du curseur composite.
    $parameters = ImportFilter::default()->discoverQueryFor('ja')->toQueryParameters();

    expect($parameters['with_original_language'] ?? null)->toBe('ja')
        ->and($parameters)->not->toHaveKey('page');
});

/*
|--------------------------------------------------------------------------
| Le curseur composite — la reprise dans un seul entier
|--------------------------------------------------------------------------
*/

it('encode et relit une position de balayage sans perte', function (): void {
    $cursor = new DiscoverCursor(languageIndex: 2, page: 137);

    $round = DiscoverCursor::fromColumn($cursor->toColumn());

    expect($round->languageIndex)->toBe(2)
        ->and($round->page)->toBe(137)
        // Trois langues et 500 pages tiennent très largement dans un
        // `unsignedInteger`, la seule colonne de reprise que le schéma offre.
        ->and($cursor->toColumn())->toBeLessThan(2_000);
});

it('démarre au début quand la colonne de reprise est nulle', function (): void {
    $start = DiscoverCursor::fromColumn(null);

    expect($start->languageIndex)->toBe(0)
        ->and($start->page)->toBe(1)
        ->and($start->toColumn())->toBe(0);
});

it('passe à la langue suivante quand la série est épuisée, puis s’arrête', function (): void {
    $cursor = new DiscoverCursor(languageIndex: 0, page: 3);

    $lastPage = new TmdbPage(page: 3, totalPages: 3, totalResults: 60, results: []);

    expect($cursor->next($lastPage))->toBeNull();

    $nextLanguage = $cursor->nextLanguage(3);

    expect($nextLanguage?->languageIndex)->toBe(1)
        ->and($nextLanguage?->page)->toBe(1)
        ->and((new DiscoverCursor(2, 1))->nextLanguage(3))->toBeNull();
});

it('refuse une page au-delà du plafond dur de `discover`', function (): void {
    new DiscoverCursor(languageIndex: 0, page: TmdbPage::MAX_PAGE + 1);
})->throws(InvalidArgumentException::class);

/*
|--------------------------------------------------------------------------
| La lecture d'un collage
|--------------------------------------------------------------------------
*/

it('lit un collage d’identifiants nus, d’URL et de commentaires', function (): void {
    $identifiers = TmdbIdentifierList::parse([
        '# Canon Disney antérieur à 1970',
        '408 1234',
        'https://www.themoviedb.org/movie/9876-blanche-neige',
        'https://themoviedb.org/fr/movie/5555',
        '',
        '408   # doublon, l’ordre du collage est conservé',
        'pas un identifiant',
        '-12',
    ]);

    expect($identifiers)->toBe([408, 1234, 9876, 5555]);
});

/*
|--------------------------------------------------------------------------
| Le filtre de contenu, et le normaliseur unique
|--------------------------------------------------------------------------
*/

it('nomme les seules certifications restrictives, et jamais les -16', function (): void {
    $france = CertificationCountry::France;
    $us = CertificationCountry::UnitedStates;

    expect(RestrictiveCertifications::isRestrictive($france, '-18'))->toBeTrue()
        ->and(RestrictiveCertifications::isRestrictive($france, '18'))->toBeTrue()
        ->and(RestrictiveCertifications::isRestrictive($france, 'X'))->toBeTrue()
        ->and(RestrictiveCertifications::isRestrictive($france, '-16'))->toBeFalse()
        ->and(RestrictiveCertifications::isRestrictive($france, 'U'))->toBeFalse()
        ->and(RestrictiveCertifications::isRestrictive($us, 'NC-17'))->toBeTrue()
        ->and(RestrictiveCertifications::isRestrictive($us, 'nc - 17'))->toBeTrue()
        ->and(RestrictiveCertifications::isRestrictive($us, 'R'))->toBeFalse()
        // Une classification inconnue n'est jamais un refus : c'est
        // `unrated_pending`, donc la coche d'un curateur.
        ->and(RestrictiveCertifications::isRestrictive($us, ''))->toBeFalse()
        // Le classement -18 français ne vaut pas aux États-Unis, et
        // réciproquement : la grille est PAR PAYS.
        ->and(RestrictiveCertifications::isRestrictive($us, '-18'))->toBeFalse();
});

it('réduit une chaîne à l’alphabet que les deux moteurs jugent pareil', function (): void {
    expect(AnswerKeyNormalizer::normalize('Le Fabuleux Destin d’Amélie Poulain'))
        ->toBe('fabuleux destin d amelie poulain')
        ->and(AnswerKeyNormalizer::normalize('WALL·E'))->toBe('wall e')
        ->and(AnswerKeyNormalizer::normalize('The Thing'))->toBe('thing')
        // Jamais le dernier mot restant : « Le » seul reste « le ».
        ->and(AnswerKeyNormalizer::normalize('Le'))->toBe('le')
        // Un titre entièrement non latin est translittéré, jamais vidé
        // (spec 70 § 5.2) : la saisie dans l'écriture d'origine s'apparie à
        // `title_original`, la translittération usuelle à `title_latin` (A4).
        ->and(AnswerKeyNormalizer::normalize('ガラスの果樹園'))->toBe('garasunoguo shu yuan');
});

it('découpe un préfixe au premier séparateur configuré, et pas ailleurs', function (): void {
    expect(AnswerKeyNormalizer::prefixOf('Le Seigneur des Anneaux : La Communauté de l’Anneau'))
        ->toBe('seigneur des anneaux')
        ->and(AnswerKeyNormalizer::prefixOf('Alien'))->toBeNull();

    // Les deux réglages vivent en configuration, et tout changement est un
    // déploiement suivi de `catalog:reproject` (§ 3.5).
    Config::set('catalog.min_prefix_length', 40);

    expect(AnswerKeyNormalizer::prefixOf('Le Seigneur des Anneaux : La Communauté de l’Anneau'))->toBeNull();

    Config::set('catalog.min_prefix_length', 4);
    Config::set('catalog.subtitle_separators', [' // ']);

    expect(AnswerKeyNormalizer::prefixOf('Le Seigneur des Anneaux : La Communauté de l’Anneau'))->toBeNull()
        ->and(AnswerKeyNormalizer::prefixOf('Northbound Nine // Le retour'))->toBe('northbound nine');
});

/*
|--------------------------------------------------------------------------
| L'étranglement de quota
|--------------------------------------------------------------------------
*/

it('espace les appels TMDB, et reprend l’espacement après un redémarrage', function (): void {
    Sleep::fake();

    Config::set('catalog.import.requests_per_second', 10);

    $limiter = new TmdbQuotaLimiter;
    $run = ImportRun::factory()->create(['last_request_at' => null]);

    // Le premier appel n'attend jamais : il n'a rien à espacer.
    $limiter->throttle($run);

    expect($run->last_request_at)->not->toBeNull();

    Sleep::assertNeverSlept();

    $limiter->throttle($run);

    // 10 appels par seconde : 100 000 microsecondes entre deux.
    Sleep::assertSlept(fn (CarbonInterval $interval): bool => $interval->totalMicroseconds > 0);

    // Un worker redémarré relit `last_request_at` et ne repart pas en rafale.
    $redemarre = new TmdbQuotaLimiter;
    $run->last_request_at = CarbonImmutable::now();
    $redemarre->resumeFrom($run);
    $redemarre->throttle($run);

    Sleep::fake(false);
});

it('ne dort jamais quand l’étranglement est désactivé', function (): void {
    Sleep::fake();

    Config::set('catalog.import.requests_per_second', 0);

    $limiter = new TmdbQuotaLimiter;
    $run = ImportRun::factory()->create();

    $limiter->throttle($run);
    $limiter->throttle($run);

    Sleep::assertNeverSlept();

    Sleep::fake(false);
});
