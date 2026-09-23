<?php

use App\Enums\AnswerKeyKind;
use App\Enums\CertificationCountry;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ContentOrigin;
use App\Enums\ImportRunKind;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Enums\MovieDifficulty;
use App\Enums\TmdbTagKind;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Frame;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\User;
use App\Support\Catalog\ImportDecision;
use App\Support\Catalog\MovieImporter;
use App\Support\Tmdb\TmdbMovie;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Le service d'import — aucun réseau, jamais
|--------------------------------------------------------------------------
|
| Ce fichier n'instancie même pas le client TMDB : il construit les DTO
| directement depuis les fixtures committées. `Http::preventStrayRequests()`
| est là pour le prouver, pas pour être utilisé — un jour où une écriture de
| catalogue rappellerait TMDB, ce test tomberait, et c'est exactement la
| règle 6 qu'on veut voir tomber bruyamment.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** Une fiche TMDB complète, lue depuis une fixture anonymisée. */
function tmdbMovie(string $fixture): TmdbMovie
{
    return TmdbMovie::fromArray(TmdbFixture::array($fixture));
}

function importerRun(ImportRunKind $kind, bool $widened = false): ImportRun
{
    // Compteurs remis à zéro : la factory en tire d'aléatoires pour peupler un
    // back-office, et le journal de ce fichier compte des incréments.
    return ImportRun::factory()->create([
        'run_kind' => $kind,
        'is_widened' => $widened,
        'total_seen' => 0,
        'total_imported' => 0,
        'total_skipped' => 0,
        'total_refused_content' => 0,
    ]);
}

function importer(): MovieImporter
{
    return app(MovieImporter::class);
}

/*
|--------------------------------------------------------------------------
| L'asymétrie des deux voies — le cœur du § 9.2
|--------------------------------------------------------------------------
*/

it('importe par balayage un film qui satisfait le filtre de notoriété', function (): void {
    $run = importerRun(ImportRunKind::Discover);

    $outcome = importer()->import(tmdbMovie('movie-987654'), $run, ImportFilter::default());

    expect($outcome->decision)->toBe(ImportDecision::Imported);

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    expect($movie->import_source)->toBe(ImportSource::Discover)
        ->and($movie->import_run_id)->toBe($run->id)
        ->and($movie->title_original)->toBe('ガラスの果樹園')
        ->and($movie->original_language)->toBe('ja')
        ->and($movie->release_year)->toBe(1998)
        ->and($movie->vote_count)->toBe(2410)
        ->and($movie->adult)->toBeFalse()
        // Le filtre par défaut est satisfait sur les trois axes : ni exception,
        // ni motif. C'est la ligne que la voie de collage contredit juste après.
        ->and($movie->is_import_exception)->toBeFalse()
        ->and($movie->exception_for_language)->toBeFalse()
        ->and($movie->exception_for_vote_count)->toBeFalse()
        ->and($movie->exception_for_release_year)->toBeFalse()
        // L'import ne publie jamais : la publication est un geste de curation.
        ->and($movie->availability)->toBe(ContentAvailability::Draft);
});

it('rejette par balayage le film que le filtre de notoriété écarte, et l’accepte par la voie d’exception', function (): void {
    $faible = tmdbMovie('movie-987655-minimal');

    // Voie 1 — le balayage applique le filtre de goût.
    $discover = importer()->import($faible, importerRun(ImportRunKind::Discover), ImportFilter::default());

    expect($discover->decision)->toBe(ImportDecision::SkippedByFilter)
        ->and(Movie::query()->where('tmdb_id', 987655)->exists())->toBeFalse();

    // Voie 2 — le collage l'ignore ENTIÈREMENT. C'est elle qui fait entrer
    // Parasite, Le Labyrinthe de Pan et l'âge d'or Disney d'avant 1970.
    $paste = importer()->import($faible, importerRun(ImportRunKind::Paste), ImportFilter::default());

    expect($paste->decision)->toBe(ImportDecision::Imported);

    $movie = Movie::query()->where('tmdb_id', 987655)->firstOrFail();

    expect($movie->import_source)->toBe(ImportSource::Paste)
        ->and($movie->is_import_exception)->toBeTrue()
        // Les motifs sont CUMULABLES : ce film échoue la notoriété ET l'année.
        ->and($movie->exception_for_vote_count)->toBeTrue()
        ->and($movie->exception_for_release_year)->toBeTrue()
        // `fr` est dans le filtre : ce motif-là ne se pose pas.
        ->and($movie->exception_for_language)->toBeFalse();
});

it('marque `is_import_exception` même quand le film collé satisfait tout le filtre', function (): void {
    // `is_import_exception` n'est JAMAIS dérivable des trois motifs : c'est la
    // VOIE qui est tracée, pas le verdict — d'où la quatrième colonne (§ 9.2).
    $outcome = importer()->import(
        tmdbMovie('movie-987654'),
        importerRun(ImportRunKind::Paste),
        ImportFilter::default(),
    );

    $movie = $outcome->movie;

    expect($movie?->is_import_exception)->toBeTrue()
        ->and($movie?->exception_for_language)->toBeFalse()
        ->and($movie?->exception_for_vote_count)->toBeFalse()
        ->and($movie?->exception_for_release_year)->toBeFalse();
});

it('marque exception tout film entré par un balayage élargi', function (): void {
    $widened = ImportFilter::make(minVoteCount: 10, minReleaseYear: 1900);

    expect($widened->isWiderThanDefault())->toBeTrue();

    $outcome = importer()->import(
        tmdbMovie('movie-987663-reclassified'),
        importerRun(ImportRunKind::Discover, widened: true),
        $widened,
    );

    expect($outcome->decision)->toBe(ImportDecision::Imported)
        ->and($outcome->movie?->is_import_exception)->toBeTrue()
        // Le film satisfait pourtant le filtre PAR DÉFAUT sur les trois axes :
        // aucun motif n'est posé. C'est la preuve que `is_import_exception`
        // n'est pas dérivable des trois motifs, et qu'une quatrième colonne est
        // nécessaire (§ 9.2).
        ->and($outcome->movie?->exception_for_language)->toBeFalse()
        ->and($outcome->movie?->exception_for_vote_count)->toBeFalse()
        ->and($outcome->movie?->exception_for_release_year)->toBeFalse();
});

it('pose le motif d’année sur un film dont TMDB ignore la date de sortie', function (): void {
    // Un blindtest ne tire pas un film dont personne ne sait quand il est sorti :
    // l'axe historique échoue, même élargi à 1900. La voie d'exception, elle, le
    // récupère — avec le motif, jamais en silence.
    $undated = tmdbMovie('movie-987655-minimal');

    expect(ImportFilter::make(minReleaseYear: 1900)->accepts('fr', 5_000, $undated->releaseYear()))->toBeFalse();

    $outcome = importer()->import($undated, importerRun(ImportRunKind::Paste), ImportFilter::default());

    expect($outcome->movie?->release_year)->toBeNull()
        ->and($outcome->movie?->exception_for_release_year)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Le filtre de contenu — jamais contournable, par aucune voie
|--------------------------------------------------------------------------
*/

it('refuse un film `adult` par les deux voies', function (string $kind): void {
    $outcome = importer()->import(
        tmdbMovie('movie-987660-adult'),
        importerRun(ImportRunKind::from($kind)),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::RefusedContent)
        ->and($outcome->reasonKey)->toBe('admin.catalog.import.refused.adult')
        ->and(Movie::query()->where('tmdb_id', 987660)->exists())->toBeFalse();
})->with(['discover', 'paste']);

it('refuse un film classé -18 en France par les deux voies', function (string $kind): void {
    // Le film satisfait pourtant tout le filtre de goût : 5 000 votes, anglais,
    // 2005. Seul le contenu le refuse, et aucune voie ne le contourne.
    $outcome = importer()->import(
        tmdbMovie('movie-987661-fr-18'),
        importerRun(ImportRunKind::from($kind)),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::RefusedContent)
        ->and($outcome->reasonKey)->toBe('admin.catalog.import.refused.certification')
        ->and($outcome->reasonReplacements['country'] ?? null)->toBe('FR')
        ->and($outcome->reasonReplacements['certification'] ?? null)->toBe('-18')
        ->and(Movie::query()->where('tmdb_id', 987661)->exists())->toBeFalse();
})->with(['discover', 'paste']);

it('refuse un film classé NC-17 aux États-Unis par les deux voies', function (string $kind): void {
    $outcome = importer()->import(
        tmdbMovie('movie-987662-nc-17'),
        importerRun(ImportRunKind::from($kind)),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::RefusedContent)
        ->and($outcome->reasonReplacements['certification'] ?? null)->toBe('NC-17')
        ->and(Movie::query()->where('tmdb_id', 987662)->exists())->toBeFalse();
})->with(['discover', 'paste']);

it('laisse entrer les -16, qui sont exactement le corpus d’un blindtest', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    expect($movie->content_flag)->toBe(ContentFlag::Clear);
});

/*
|--------------------------------------------------------------------------
| Les certifications — « la plus récente fait foi », en PHP et jamais en SQL
|--------------------------------------------------------------------------
*/

it('retient par pays la certification la plus récente, et elle seule', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    /** @var MovieCertification $france */
    $france = MovieCertification::query()
        ->where('movie_id', $movie->id)
        ->where('country', CertificationCountry::France->value)
        ->firstOrFail();

    // La fixture porte -16 en 1999 PUIS -12 en 2014 : c'est la seconde qui fait
    // foi, et une seule ligne par pays existe (`movie_cert_movie_country_uq`).
    expect($france->certification)->toBe('-12')
        ->and($france->is_restrictive)->toBeFalse()
        ->and($france->released_on?->format('Y-m-d'))->toBe('2014-05-21')
        ->and($france->read_at)->not->toBeNull()
        ->and(MovieCertification::query()->where('movie_id', $movie->id)->count())->toBe(2);
});

it('n’exclut pas un film classé X à sa sortie puis reclassé', function (): void {
    // Le cas Orange mécanique / Macadam Cowboy : prendre la PREMIÈRE entrée du
    // tableau au lieu de la plus récente les exclurait tous les deux à tort.
    $outcome = importer()->import(
        tmdbMovie('movie-987663-reclassified'),
        importerRun(ImportRunKind::Discover),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::Imported);

    $movie = Movie::query()->where('tmdb_id', 987663)->firstOrFail();

    /** @var MovieCertification $france */
    $france = MovieCertification::query()->where('movie_id', $movie->id)->firstOrFail();

    expect($france->certification)->toBe('-16')
        ->and($france->is_restrictive)->toBeFalse()
        ->and($movie->content_flag)->toBe(ContentFlag::Clear);
});

it('laisse un film sans aucune certification en `unrated_pending`, donc non publiable', function (): void {
    importer()->import(
        tmdbMovie('movie-987655-minimal'),
        importerRun(ImportRunKind::Paste),
        ImportFilter::default(),
    );

    $movie = Movie::query()->where('tmdb_id', 987655)->firstOrFail();

    expect($movie->content_flag)->toBe(ContentFlag::UnratedPending)
        ->and($movie->content_verified_by_id)->toBeNull()
        ->and(MovieCertification::query()->where('movie_id', $movie->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Ce que l'import écrit autour du film
|--------------------------------------------------------------------------
*/

it('écrit `movie_projection` dans la même transaction, à la version de masque courante', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    /** @var MovieProjection $projection */
    $projection = MovieProjection::query()->findOrFail($movie->id);

    expect($projection->title_mask_version)->toBe(Locale::MASK_VERSION)
        ->and($projection->recomputed_at)->not->toBeNull()
        // Deux titres activés — `en` et `fr` — donc les deux bits du masque.
        ->and($projection->title_locale_mask)->toBe(Locale::English->maskBit() | Locale::French->maskBit())
        // L'import n'écrit AUCUNE frame : la curation est un acte humain.
        ->and($projection->levels_count)->toBe(0)
        ->and($projection->variants_total)->toBe(0)
        ->and(Frame::query()->where('movie_id', $movie->id)->count())->toBe(0);
});

it('écrit les titres des seules locales activées, et jamais un titre vide', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    /** @var list<string> $locales */
    $locales = MovieTitle::query()->where('movie_id', $movie->id)->pluck('locale')->all();

    sort($locales);

    // La fixture porte en, fr et de ; `de` n'est pas activée et le titre
    // allemand est vide — l'absence d'une ligne EST l'information (§ 3.4).
    expect($locales)->toBe(['en', 'fr']);

    /** @var MovieTitle $french */
    $french = MovieTitle::query()->where('movie_id', $movie->id)->where('locale', 'fr')->firstOrFail();

    expect($french->title)->toBe('Le Verger de verre')
        ->and($french->origin)->toBe(ContentOrigin::Tmdb);
});

it('range la translittération latine sur `movie`, et jamais en alias', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    expect($movie->title_original_latin)->toBe('Garasu no Kajuen');

    /** @var list<string> $aliases */
    $aliases = Alias::query()->where('movie_id', $movie->id)->pluck('alias')->all();

    // Le Romaji ne descend jamais en alias (A4) ; le titre alternatif FR, si.
    expect($aliases)->toBe(['Le Verger']);
});

it('projette les clés de réponse au périmètre du § 3.5', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    /** @var array<string, AnswerKeyKind> $keys */
    $keys = AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->pluck('key_kind', 'normalized')
        ->all();

    // Le titre original japonais ne survit à aucune normalisation latine : c'est
    // exactement pourquoi `title_original_latin` existe, et pourquoi il entre
    // INCONDITIONNELLEMENT, sans filtre de locale.
    expect($keys)->toHaveKey('garasu no kajuen')
        ->and($keys['garasu no kajuen'])->toBe(AnswerKeyKind::TitleLatin)
        ->and($keys)->toHaveKey('glass orchard')
        ->and($keys)->toHaveKey('verger de verre')
        ->and($keys)->toHaveKey('verger')
        ->and($keys['verger'])->toBe(AnswerKeyKind::Alias);
});

it('écrit les étiquettes TMDB brutes, genres et sociétés', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    /** @var list<int> $genres */
    $genres = MovieTmdbTag::query()
        ->where('movie_id', $movie->id)
        ->where('tag_kind', TmdbTagKind::Genre->value)
        ->pluck('tmdb_tag_id')
        ->all();

    /** @var list<int> $companies */
    $companies = MovieTmdbTag::query()
        ->where('movie_id', $movie->id)
        ->where('tag_kind', TmdbTagKind::Company->value)
        ->pluck('tmdb_tag_id')
        ->all();

    sort($genres);
    sort($companies);

    expect($genres)->toBe([16, 18])
        ->and($companies)->toBe([7711, 7712]);
});

it('rattache la saga TMDB sans jamais la confondre avec le groupe manuel', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    expect($movie->collection_id)->not->toBeNull()
        ->and($movie->collection?->tmdb_id)->toBe(445566)
        ->and($movie->group_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Déduplication et blocage de réimport
|--------------------------------------------------------------------------
*/

it('ne duplique jamais un film déjà importé', function (): void {
    $tmdb = tmdbMovie('movie-987654');

    importer()->import($tmdb, importerRun(ImportRunKind::Discover), ImportFilter::default());

    $second = importer()->import($tmdb, importerRun(ImportRunKind::Discover), ImportFilter::default());

    expect($second->decision)->toBe(ImportDecision::Duplicate)
        ->and(Movie::query()->where('tmdb_id', 987654)->count())->toBe(1)
        ->and(MovieTitle::query()->count())->toBe(2)
        ->and(MovieTmdbTag::query()->count())->toBe(4);
});

it('refuse de réimporter un film retiré, sans table de bannissement', function (): void {
    $movie = Movie::factory()->withdrawn('Retrait prononcé sur mise en demeure.')->create(['tmdb_id' => 987654]);

    $outcome = importer()->import(
        tmdbMovie('movie-987654'),
        importerRun(ImportRunKind::Paste),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::RefusedWithdrawn)
        ->and($outcome->reasonReplacements['reason'] ?? null)->toBe('Retrait prononcé sur mise en demeure.');

    $movie->refresh();

    expect($movie->availability)->toBe(ContentAvailability::Withdrawn);
});

it('exclut définitivement un film de démonstration de la resynchronisation', function (): void {
    Movie::factory()->demo()->create(['tmdb_id' => null]);

    $demo = Movie::query()->firstOrFail();
    $demo->forceFill(['tmdb_id' => 987654])->save();

    $outcome = importer()->import(
        tmdbMovie('movie-987654-resynced'),
        importerRun(ImportRunKind::Resync),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::Duplicate);

    $demo->refresh();

    expect($demo->title_original)->not->toBe('ガラスの果樹園 リマスター');
});

it('lit les films déjà connus en lot, par l’unique `movie_tmdb_uq`', function (): void {
    Movie::factory()->create(['tmdb_id' => 111]);
    Movie::factory()->create(['tmdb_id' => 222]);

    $found = importer()->existingByTmdbId([111, 222, 333, 111]);

    expect(array_keys($found))->toBe([111, 222]);
});

/*
|--------------------------------------------------------------------------
| Resynchronisation — la liste close du § 9.3
|--------------------------------------------------------------------------
*/

it('resynchronise les métadonnées TMDB sans toucher à la liste close', function (): void {
    $curator = User::factory()->create();
    $group = MovieGroup::factory()->create();

    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    // L'état de curation que la resynchronisation ne doit JAMAIS écraser.
    $movie->forceFill([
        'movie_difficulty_override' => MovieDifficulty::Hard,
        'movie_difficulty' => MovieDifficulty::Hard,
        'group_id' => $group->id,
        'availability' => ContentAvailability::Published,
        'availability_reason' => 'Publié après curation.',
        'content_verified_by_id' => $curator->id,
        'content_verified_at' => now(),
        'curated_by_id' => $curator->id,
        'curation_active_seconds' => 742,
        'is_import_exception' => true,
        'exception_for_language' => true,
    ])->save();

    // Une correction de titre : c'est la tâche quotidienne du curateur, et le
    // défaut que la colonne `origin` existe pour empêcher.
    MovieTitle::query()
        ->where('movie_id', $movie->id)
        ->where('locale', 'en')
        ->update(['title' => 'Titre corrigé à la main', 'origin' => ContentOrigin::Curator->value]);

    Alias::factory()->create([
        'movie_id' => $movie->id,
        'locale' => 'fr',
        'alias' => 'Alias curé à conserver',
        'origin' => ContentOrigin::Curator,
    ]);

    $outcome = importer()->import(
        tmdbMovie('movie-987654-resynced'),
        importerRun(ImportRunKind::Resync),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::Resynchronized);

    $movie->refresh();

    // Écrasable — métadonnées TMDB pures.
    expect($movie->title_original)->toBe('ガラスの果樹園 リマスター')
        ->and($movie->title_original_latin)->toBe('Garasu no Kajuen Rimasuta')
        ->and($movie->vote_count)->toBe(9999);

    // Jamais touché.
    expect($movie->movie_difficulty_override)->toBe(MovieDifficulty::Hard)
        ->and($movie->group_id)->toBe($group->id)
        ->and($movie->availability)->toBe(ContentAvailability::Published)
        ->and($movie->availability_reason)->toBe('Publié après curation.')
        ->and($movie->content_verified_by_id)->toBe($curator->id)
        ->and($movie->curated_by_id)->toBe($curator->id)
        ->and($movie->curation_active_seconds)->toBe(742)
        ->and($movie->is_import_exception)->toBeTrue()
        ->and($movie->exception_for_language)->toBeTrue()
        ->and($movie->import_source)->toBe(ImportSource::Discover);

    // La correction de titre survit ; le titre `tmdb` a bien été relu.
    /** @var MovieTitle $english */
    $english = MovieTitle::query()->where('movie_id', $movie->id)->where('locale', 'en')->firstOrFail();

    /** @var MovieTitle $french */
    $french = MovieTitle::query()->where('movie_id', $movie->id)->where('locale', 'fr')->firstOrFail();

    expect($english->title)->toBe('Titre corrigé à la main')
        ->and($english->origin)->toBe(ContentOrigin::Curator)
        ->and($french->title)->toBe('Le Verger de verre remasterisé');

    // L'alias curé survit ; l'alias TMDB a été remplacé.
    /** @var list<string> $aliases */
    $aliases = Alias::query()->where('movie_id', $movie->id)->pluck('alias')->all();

    sort($aliases);

    expect($aliases)->toBe(['Alias curé à conserver', 'Le Verger remasterisé']);

    // Les étiquettes et les certifications sont des métadonnées pures.
    /** @var list<int> $genres */
    $genres = MovieTmdbTag::query()
        ->where('movie_id', $movie->id)
        ->where('tag_kind', TmdbTagKind::Genre->value)
        ->pluck('tmdb_tag_id')
        ->all();

    sort($genres);

    expect($genres)->toBe([16, 10751]);

    /** @var MovieCertification $france */
    $france = MovieCertification::query()->where('movie_id', $movie->id)->firstOrFail();

    expect($france->certification)->toBe('U')
        ->and(MovieCertification::query()->where('movie_id', $movie->id)->count())->toBe(1);
});

it('bascule `content_flag` en `blocked` sans jamais dépublier ni détruire', function (): void {
    importer()->import(tmdbMovie('movie-987654'), importerRun(ImportRunKind::Discover), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();
    $movie->forceFill([
        'availability' => ContentAvailability::Published,
        'first_published_at' => now(),
    ])->save();

    $outcome = importer()->import(
        tmdbMovie('movie-987654-resynced-blocked'),
        importerRun(ImportRunKind::Resync),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::Resynchronized);

    $movie->refresh();

    // Elle PROPOSE une dépublication ; elle ne la prononce pas, et ne détruit
    // aucun fichier. Seul un retrait juridique supprime des octets.
    expect($movie->content_flag)->toBe(ContentFlag::Blocked)
        ->and($movie->availability)->toBe(ContentAvailability::Published)
        ->and($movie->availability_reason)->toBeNull();
});

it('ne réapplique jamais le filtre d’import à une resynchronisation', function (): void {
    // Un film entré par exception — 0 vote, aucune année — reste au catalogue :
    // il n'est jamais proposé au retrait pour sa langue, ses votes ou sa date.
    importer()->import(
        tmdbMovie('movie-987655-minimal'),
        importerRun(ImportRunKind::Paste),
        ImportFilter::default(),
    );

    $outcome = importer()->import(
        tmdbMovie('movie-987655-minimal'),
        importerRun(ImportRunKind::Resync),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::Resynchronized)
        ->and(Movie::query()->where('tmdb_id', 987655)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Simulation et journal
|--------------------------------------------------------------------------
*/

it('n’écrit rien en simulation', function (): void {
    $outcome = importer()->import(
        tmdbMovie('movie-987654'),
        importerRun(ImportRunKind::Discover),
        ImportFilter::default(),
        dryRun: true,
    );

    expect($outcome->decision)->toBe(ImportDecision::Simulated)
        ->and(Movie::query()->count())->toBe(0);
});

it('accumule les quatre compteurs de `import_run` sans requête par film', function (): void {
    $run = importerRun(ImportRunKind::Discover);

    importer()->journal($run, importer()->import(tmdbMovie('movie-987654'), $run, ImportFilter::default()));
    importer()->journal($run, importer()->import(tmdbMovie('movie-987655-minimal'), $run, ImportFilter::default()));
    importer()->journal($run, importer()->import(tmdbMovie('movie-987660-adult'), $run, ImportFilter::default()));

    expect($run->total_seen)->toBe(3)
        ->and($run->total_imported)->toBe(1)
        ->and($run->total_skipped)->toBe(1)
        // Le chiffre qu'il faut pouvoir produire devant une mise en demeure.
        ->and($run->total_refused_content)->toBe(1);
});
