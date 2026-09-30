<?php

use App\Enums\AnswerKeyKind;
use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\AnswerKey;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\PastePreview;
use App\Support\Catalog\DiscoverCursor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Les deux commandes d'import — aucun appel réseau, jamais
|--------------------------------------------------------------------------
|
| `Http::preventStrayRequests()` fait échouer tout appel non simulé : c'est la
| garantie mécanique que la CI n'a besoin d'aucune clé TMDB et qu'aucun test ne
| sort de la machine. `Sleep::fake()` neutralise à la fois l'étranglement de
| quota et le retrait exponentiel du client.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake();

    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);

    // Le débit ne se teste pas en attendant : `Sleep::fake()` le rend gratuit,
    // et un test qui dormirait vraiment 1/35 de seconde par appel serait une
    // dette de CI, pas une preuve.
    Config::set('catalog.import.requests_per_second', 1000);
});

afterEach(function (): void {
    Sleep::fake(false);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function tmdbFake(array $overrides = []): void
{
    Http::fake(array_merge([
        '*themoviedb.org/3/discover/movie*' => tmdbJson('discover-page-single'),
        '*themoviedb.org/3/movie/987654*' => tmdbJson('movie-987654'),
        '*themoviedb.org/3/movie/987655*' => tmdbJson('movie-987655-minimal'),
        '*themoviedb.org/3/movie/987656*' => tmdbJson('movie-987656'),
    ], $overrides));
}

/**
 * Une réponse simulée nourrie par une fixture committée. Le type de retour est
 * laissé ouvert à dessein : `Http::response()` rend une promesse Guzzle, détail
 * d'implémentation du client simulé qu'aucun test n'a à nommer.
 */
function tmdbJson(string $fixture, int $status = 200): mixed
{
    return Http::response(TmdbFixture::json($fixture), $status, ['Content-Type' => 'application/json']);
}

/*
|--------------------------------------------------------------------------
| Sans clé, rien ne casse — et la CI le prouve
|--------------------------------------------------------------------------
*/

it('refuse proprement de balayer quand aucune clé TMDB n’est posée', function (): void {
    Config::set('services.tmdb.read_access_token', null);
    Config::set('services.tmdb.api_key', null);

    $this->artisan('catalog:import-discover')
        ->expectsOutputToContain('Aucune clé TMDB configurée')
        ->assertFailed();

    Http::assertNothingSent();

    expect(ImportRun::query()->count())->toBe(0);
});

it('refuse proprement de coller quand aucune clé TMDB n’est posée', function (): void {
    // Une clé absente ne casse rien : elle désactive l'import avec un message
    // clair, et la CI tourne sans clé — aucun `import_run` n'est même ouvert.
    Config::set('services.tmdb.read_access_token', null);
    Config::set('services.tmdb.api_key', null);

    $this->artisan('catalog:import-ids', ['ids' => ['987654']])
        ->expectsOutputToContain('Aucune clé TMDB configurée')
        ->assertFailed();

    Http::assertNothingSent();

    expect(ImportRun::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Le balayage
|--------------------------------------------------------------------------
*/

it('balaie, importe et journalise un `import_run` complet', function (): void {
    tmdbFake();

    $this->artisan('catalog:import-discover', ['--pages' => 9])->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    expect($run->run_kind)->toBe(ImportRunKind::Discover)
        ->and($run->status)->toBe(ImportRunStatus::Completed)
        ->and($run->is_widened)->toBeFalse()
        // Le filtre appliqué est FIGÉ sur la ligne : c'est ce qui rend le
        // balayage rejouable, et les trois motifs relisibles des mois plus tard.
        ->and($run->filter_min_vote_count)->toBe(500)
        ->and($run->filter_languages)->toBe('fr,en,ja')
        ->and($run->filter_min_release_year)->toBe(1970)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->last_request_at)->not->toBeNull();

    // Trois langues, trois séries paginées : la même page est rendue trois fois
    // par la simulation, donc chaque film est vu trois fois et importé une.
    expect($run->total_seen)->toBe(9)
        ->and($run->total_imported)->toBe(2)
        ->and($run->total_skipped)->toBe(7)
        ->and(Movie::query()->count())->toBe(2)
        ->and(Movie::query()->where('tmdb_id', 987654)->exists())->toBeTrue()
        ->and(Movie::query()->where('tmdb_id', 987656)->exists())->toBeTrue()
        // 987655 — 0 vote, aucune année — est écarté par le filtre de goût, et
        // n'a jamais coûté d'appel de détail.
        ->and(Movie::query()->where('tmdb_id', 987655)->exists())->toBeFalse();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/movie/987655'));
});

it('relie chaque film importé au balayage qui l’a fait entrer', function (): void {
    tmdbFake();

    $this->artisan('catalog:import-discover', ['--pages' => 9])->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    expect(Movie::query()->where('import_run_id', $run->id)->count())->toBe(2);
});

it('attribue le balayage à un auteur nommé, et se passe d’un auteur inconnu', function (): void {
    tmdbFake();

    $curator = User::factory()->create();

    $this->artisan('catalog:import-discover', ['--pages' => 9, '--actor' => (string) $curator->id])
        ->assertSuccessful();

    expect(ImportRun::query()->sole()->actor_id)->toBe($curator->id);
});

it('suspend le balayage au plafond de pages, et le reprend au même endroit', function (): void {
    // Deux pages réelles, dans l'ordre : sans séquence, la simulation rendrait
    // toujours « page 1 » et la reprise n'aurait rien à prouver.
    tmdbFake(['*themoviedb.org/3/discover/movie*' => Http::sequence()
        ->push(TmdbFixture::json('discover-page-1'), 200, ['Content-Type' => 'application/json'])
        ->push(TmdbFixture::json('discover-page-2'), 200, ['Content-Type' => 'application/json']),
    ]);

    $this->artisan('catalog:import-discover', ['--pages' => 1])->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    // Suspendu, donc `running` : c'est exactement ce que l'index `(status)`
    // sert à retrouver au démarrage du worker suivant.
    expect($run->status)->toBe(ImportRunStatus::Running)
        ->and($run->tmdb_page_cursor)->not->toBeNull();

    $cursor = DiscoverCursor::fromColumn($run->tmdb_page_cursor);

    expect($cursor->languageIndex)->toBe(0)
        ->and($cursor->page)->toBe(2);

    $seen = $run->total_seen;

    $this->artisan('catalog:import-discover', ['--resume' => true, '--pages' => 1])->assertSuccessful();

    $run->refresh();

    // Un seul balayage, pas deux : la reprise n'en ouvre jamais un nouveau.
    expect(ImportRun::query()->count())->toBe(1)
        ->and($run->total_seen)->toBeGreaterThan($seen)
        ->and(DiscoverCursor::fromColumn($run->tmdb_page_cursor)->page)->toBe(3);
});

it('ne prétend pas reprendre un balayage qui n’existe pas', function (): void {
    $this->artisan('catalog:import-discover', ['--resume' => true])
        ->expectsOutputToContain('Aucun balayage')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('rejoue un balayage repris avec le filtre figé sur sa ligne, jamais avec le défaut courant', function (): void {
    tmdbFake(['*themoviedb.org/3/discover/movie*' => tmdbJson('discover-page-1')]);

    $this->artisan('catalog:import-discover', [
        '--pages' => 1,
        '--min-votes' => 10,
        '--languages' => 'en',
        '--min-year' => 1900,
    ])->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    expect($run->is_widened)->toBeTrue()
        ->and($run->filter_languages)->toBe('en');

    // Le défaut du site change ; le balayage repris garde SON filtre.
    Config::set('catalog.import_filter.min_vote_count', 50_000);

    $this->artisan('catalog:import-discover', ['--resume' => true, '--pages' => 1])->assertSuccessful();

    $run->refresh();

    expect($run->filter_min_vote_count)->toBe(10)
        ->and($run->is_widened)->toBeTrue();
});

it('n’écrit rien en simulation, mais journalise ce qui serait entré', function (): void {
    tmdbFake();

    // Une simulation n'écrit rien, donc elle ne dédoublonne contre rien : les
    // deux films retenus sont comptés une fois par langue du filtre. C'est la
    // propriété attendue d'un `--dry-run`, pas un compteur faux. Le compte
    // rendu est celui de la CONSOLE : la simulation n'a pas de ligne
    // `import_run` où l'écrire (spec 20 § 3.3).
    $this->artisan('catalog:import-discover', ['--pages' => 9, '--dry-run' => true])
        ->expectsTable(
            ['Balayage', 'Vus', 'Importés', 'Écartés', 'Refusés (contenu)'],
            [['simulation (discover)', '9', '6', '3', '0']],
        )
        ->assertSuccessful();

    expect(Movie::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);
});

test('une simulation n\'ouvre jamais de ligne import_run', function (): void {
    tmdbFake([
        '*themoviedb.org/3/movie/987661*' => tmdbJson('movie-987661-fr-18'),
    ]);

    // Les deux voies en `--dry-run`, refus de contenu compris…
    $this->artisan('catalog:import-discover', ['--pages' => 9, '--dry-run' => true])->assertSuccessful();
    $this->artisan('catalog:import-ids', ['ids' => ['987654', '987661', '987656'], '--dry-run' => true])
        ->expectsOutputToContain('pas même le balayage')
        ->assertSuccessful();
    $this->artisan('catalog:import-ids', ['ids' => ['987654'], '--resync' => true, '--dry-run' => true])->assertSuccessful();

    // … et l'aperçu à blanc du back-office, sous son jeton et son auteur.
    $author = User::factory()->curator()->create();
    $token = PastePreview::open($author->id, [987654, 987661]);

    $this->artisan('catalog:import-ids', [
        'ids' => ['987654', '987661'],
        '--preview' => $token,
        '--actor' => (string) $author->id,
    ])->assertSuccessful();

    expect(ImportRun::query()->count())->toBe(0)
        ->and(Movie::query()->count())->toBe(0)
        ->and(PastePreview::find($author->id, $token)['status'] ?? null)->toBe(PastePreview::COMPLETED);

    // Une simulation ne reprend rien : reprendre, c'est écrire.
    ImportRun::factory()->paste()->running()->create();

    $this->artisan('catalog:import-ids', ['ids' => ['987654'], '--resume' => true, '--dry-run' => true])
        ->assertFailed();
    $this->artisan('catalog:import-discover', ['--resume' => true, '--dry-run' => true])
        ->assertFailed();

    expect(ImportRun::query()->count())->toBe(1);
});

test('un aperçu ne laisse aucun état à l\'appel suivant de la même instance de commande', function (): void {
    tmdbFake();

    // Une même instance de commande sert tous les appels d'un processus : un
    // worker `queue:work` enchaîne l'aperçu d'un collage puis son import réel
    // (`PreviewCatalogPaste`, puis `RunCatalogImport` en `--resume`). Sans
    // remise à zéro, le jeton de l'aperçu survivait, l'import se croyait
    // simulation et échouait sans rien écrire (répétition du 28/09).
    $author = User::factory()->curator()->create();
    $token = PastePreview::open($author->id, [987654]);

    $this->artisan('catalog:import-ids', [
        'ids' => ['987654'],
        '--preview' => $token,
        '--actor' => (string) $author->id,
    ])->assertSuccessful();

    // Le collage tel que l'ouvre `ImportIdsController` : en cours, jamais démarré.
    $run = ImportRun::factory()->paste()->running()->create([
        'started_at' => null,
        'total_seen' => 0,
        'total_imported' => 0,
        'total_skipped' => 0,
        'total_refused_content' => 0,
    ]);

    $this->artisan('catalog:import-ids', ['ids' => ['987654'], '--resume' => true, '--run' => (string) $run->id])
        ->assertSuccessful();

    expect($run->fresh()?->status)->toBe(ImportRunStatus::Completed)
        ->and($run->fresh()?->total_imported)->toBe(1)
        ->and(Movie::query()->where('tmdb_id', 987654)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| La voie d'exception
|--------------------------------------------------------------------------
*/

it('importe un collage d’identifiants et d’URL, hors filtre de notoriété', function (): void {
    tmdbFake();

    $this->artisan('catalog:import-ids', [
        'ids' => [
            '987655',
            'https://www.themoviedb.org/movie/987656-northbound-nine',
            '# un commentaire',
        ],
    ])->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    expect($run->run_kind)->toBe(ImportRunKind::Paste)
        ->and($run->status)->toBe(ImportRunStatus::Completed)
        // Un collage ne peut pas être « élargi » : il contourne, il n'élargit pas.
        ->and($run->is_widened)->toBeFalse()
        ->and($run->total_imported)->toBe(2);

    // 987655 — 0 vote, aucune année — que le balayage refuse, et qui entre ici.
    $exception = Movie::query()->where('tmdb_id', 987655)->firstOrFail();

    expect($exception->is_import_exception)->toBeTrue()
        ->and($exception->exception_for_vote_count)->toBeTrue();
});

it('lit un collage depuis un fichier, commentaires et lignes vides compris', function (): void {
    tmdbFake();

    $path = tempnam(sys_get_temp_dir(), 'tf-paste-').'.txt';

    file_put_contents($path, implode("\n", [
        '# Canon Disney antérieur à 1970 — première section de la liste d’amorçage',
        '987654',
        '',
        'https://www.themoviedb.org/movie/987656-northbound-nine   # doublon de slug',
        '987654',
    ]));

    $this->artisan('catalog:import-ids', ['--file' => $path])->assertSuccessful();

    unlink($path);

    // Deux identifiants distincts, le doublon retiré au premier passage.
    expect(ImportRun::query()->sole()->total_seen)->toBe(2)
        ->and(Movie::query()->count())->toBe(2);
});

it('refuse clairement un collage vide', function (): void {
    $this->artisan('catalog:import-ids')
        ->expectsOutputToContain('Aucun identifiant lisible')
        ->assertFailed();

    Http::assertNothingSent();
});

it('projette les préfixes d’un titre à sous-titre au passage de l’import', function (): void {
    tmdbFake();

    $this->artisan('catalog:import-ids', ['ids' => ['987656']])->assertSuccessful();

    $movie = Movie::query()->where('tmdb_id', 987656)->firstOrFail();

    /** @var list<string> $prefixes */
    $prefixes = AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->where('key_kind', AnswerKeyKind::Prefix->value)
        ->pluck('normalized')
        ->all();

    // « Neuf vers le nord : la traversée » se coupe au premier séparateur de
    // sous-titre ; l'alias « Neuf au nord », lui, n'en produit jamais (déc. 13).
    expect($prefixes)->toBe(['neuf vers le nord']);
});

it('resynchronise sans jamais dupliquer, et ouvre un balayage de la bonne nature', function (): void {
    tmdbFake();

    $this->artisan('catalog:import-ids', ['ids' => ['987654']])->assertSuccessful();

    $this->artisan('catalog:import-ids', ['ids' => ['987654'], '--resync' => true])->assertSuccessful();

    expect(Movie::query()->where('tmdb_id', 987654)->count())->toBe(1)
        ->and(ImportRun::query()->where('run_kind', ImportRunKind::Resync->value)->count())->toBe(1);
});

it('ne relit pas un film connu quand la voie ne l’autorise pas', function (): void {
    tmdbFake();

    $this->artisan('catalog:import-ids', ['ids' => ['987654']])->assertSuccessful();

    Http::fake();
    Http::preventStrayRequests();

    $this->artisan('catalog:import-ids', ['ids' => ['987654']])->assertSuccessful();

    // Aucun appel de détail : la déduplication en lot a suffi.
    Http::assertNothingSent();

    expect(ImportRun::query()->where('run_kind', ImportRunKind::Paste->value)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Les pannes TMDB — aucune exception nue ne remonte
|--------------------------------------------------------------------------
*/

it('échoue proprement sur une clé refusée, et ne retente pas', function (): void {
    Http::fake(['*themoviedb.org/3/*' => tmdbJson('error-401', 401)]);

    $this->artisan('catalog:import-discover', ['--pages' => 1])->assertFailed();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    // Une clé invalide ne se résout pas en attendant : le balayage ÉCHOUE, il
    // n'est pas suspendu, et personne ne le reprendra en boucle.
    expect($run->status)->toBe(ImportRunStatus::Failed)
        ->and($run->finished_at)->not->toBeNull();
});

it('suspend et garde reprenable un balayage arrêté par le quota', function (): void {
    tmdbFake(['*themoviedb.org/3/movie/987654*' => tmdbJson('error-429', 429)]);

    $this->artisan('catalog:import-discover', ['--pages' => 1])
        // La PHRASE, jamais un préfixe littéral : un préfixe codé en dur devant
        // le message traduit certifierait une sortie cassée — il passerait au
        // vert alors que le corps affiché serait la clé brute.
        ->expectsOutputToContain('le balayage est suspendu et reprenable')
        ->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    expect($run->status)->toBe(ImportRunStatus::Running)
        ->and($run->finished_at)->toBeNull()
        ->and($run->tmdb_page_cursor)->not->toBeNull();

    // La panne est survenue sur un appel de DÉTAIL, donc AU MILIEU de la page :
    // le curseur doit désigner encore cette page-là.
    $cursor = DiscoverCursor::fromColumn($run->tmdb_page_cursor);

    expect($cursor->languageIndex)->toBe(0)
        ->and($cursor->page)->toBe(1);
});

it('ne perd aucun film quand la panne survient au milieu d’une page', function (): void {
    // Page 1 sur 3 : sans garde, le curseur enregistré désignerait déjà la
    // page 2 et --resume ne reverrait JAMAIS les films 2 à n de la page 1.
    tmdbFake([
        '*themoviedb.org/3/discover/movie*' => Http::sequence()
            ->push(TmdbFixture::json('discover-page-1'), 200, ['Content-Type' => 'application/json'])
            ->push(TmdbFixture::json('discover-page-2'), 200, ['Content-Type' => 'application/json']),
        '*themoviedb.org/3/movie/987654*' => tmdbJson('error-429', 429),
    ]);

    $this->artisan('catalog:import-discover', ['--pages' => 2])->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    $cursor = DiscoverCursor::fromColumn($run->tmdb_page_cursor);

    expect($run->status)->toBe(ImportRunStatus::Running)
        ->and($cursor->languageIndex)->toBe(0)
        ->and($cursor->page)->toBe(1)
        // Rien n'est entré : le premier appel de détail de la page a échoué.
        ->and(Movie::query()->count())->toBe(0);
});

it('suspend un balayage sur une panne de transport, sans exception nue', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('Délai de connexion dépassé.'));

    $this->artisan('catalog:import-discover', ['--pages' => 1])->assertSuccessful();

    expect(ImportRun::query()->sole()->status)->toBe(ImportRunStatus::Running);
});

it('traite un identifiant inconnu de TMDB comme une donnée ordinaire', function (): void {
    Http::fake(['*themoviedb.org/3/movie/424242*' => tmdbJson('error-404', 404)]);

    $this->artisan('catalog:import-ids', ['ids' => ['424242']])->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    expect($run->status)->toBe(ImportRunStatus::Completed)
        ->and($run->total_seen)->toBe(1)
        ->and($run->total_imported)->toBe(0)
        ->and($run->total_skipped)->toBe(1)
        ->and(Movie::query()->count())->toBe(0);
});

it('compte à part les refus du filtre de contenu, devant une mise en demeure', function (): void {
    Http::fake(['*themoviedb.org/3/movie/987661*' => tmdbJson('movie-987661-fr-18')]);

    $this->artisan('catalog:import-ids', ['ids' => ['987661']])->assertSuccessful();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    expect($run->total_refused_content)->toBe(1)
        ->and($run->total_skipped)->toBe(0)
        ->and($run->total_imported)->toBe(0)
        ->and(Movie::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Le back-office est français par construction, la console comprise
|--------------------------------------------------------------------------
|
| `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`, et `lang/en/admin.php` n'existe
| pas — volontairement (décision 9). Une commande ne traverse aucun
| middleware : sans locale explicite, le curateur lit la CLÉ BRUTE, et la
| substitution `:status` est perdue avec elle. Ces trois tests assertent la
| PHRASE rendue ; un préfixe littéral ne les satisferait pas.
|
*/

it('nomme la panne en français, substitutions comprises, sur une clé refusée', function (): void {
    Http::fake(['*themoviedb.org/3/*' => tmdbJson('error-401', 401)]);

    // Une seule attente par écriture : `PendingCommand` apparie chaque
    // sous-chaîne à un appel d'écriture distinct. Celle-ci porte à la fois la
    // phrase française et la substitution, qu'une clé brute perdrait.
    $this->artisan('catalog:import-discover', ['--pages' => 1])
        ->expectsOutputToContain('authentification (statut 401)')
        ->assertFailed();
});

it('nomme le motif de refus de contenu en français, devant une mise en demeure', function (): void {
    Http::fake(['*themoviedb.org/3/movie/987661*' => tmdbJson('movie-987661-fr-18')]);

    $this->artisan('catalog:import-ids', ['ids' => ['987661'], '--verbose' => true])
        ->expectsOutputToContain('classification')
        ->assertSuccessful();
});

it('nomme un auteur inconnu par une clé, jamais par une chaîne en dur', function (): void {
    tmdbFake();

    $this->artisan('catalog:import-discover', ['--pages' => 1, '--actor' => 'inconnu@example.test'])
        ->expectsOutputToContain('sera enregistré sans auteur')
        ->assertSuccessful();

    expect(ImportRun::query()->sole()->actor_id)->toBeNull();
});
