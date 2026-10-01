<?php

use App\Models\PerfSample;
use App\Models\PerfSlowQuery;
use App\Support\Perf\PerfRecorder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Mesure des requêtes et des jobs — spec 100 § 10.11, D47 du 01/10
|--------------------------------------------------------------------------
|
| Une ligne `perf_sample` par requête et par job, sous le nom de la route et
| jamais l'URL ; les requêtes lentes par leur SQL à paramètres, jamais leurs
| valeurs liées. Jamais bloquant, coupable. `phpunit.xml` coupe la mesure :
| chaque test l'active.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Config::set('perf.enabled', true);
    Config::set('perf.sample_rate', 1.0);
    app(PerfRecorder::class)->reset();
});

it('écrit l\'échantillon d\'une requête sous le nom de sa route, jamais son URL', function (): void {
    $this->get('/?room=ABC123&token=secret')->assertOk();

    $sample = PerfSample::query()->sole();

    expect($sample->kind)->toBe(PerfSample::KIND_REQUEST)
        ->and($sample->name)->toBe('home')
        ->and($sample->method)->toBe('GET')
        ->and($sample->status)->toBe('200')
        ->and($sample->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and(json_encode($sample->getAttributes()))->not->toContain('ABC123')
        ->and(json_encode($sample->getAttributes()))->not->toContain('secret');
});

it('retient une requête lente par son SQL à paramètres, jamais ses valeurs liées', function (): void {
    Config::set('perf.slow_query_ms', 0);
    $recorder = app(PerfRecorder::class);

    $recorder->begin(PerfSample::KIND_JOB, null, 'default');
    DB::select('select ? as value', ['valeur-privée']);
    $sample = $recorder->finish('Témoin', PerfSample::STATUS_PROCESSED);

    $slow = PerfSlowQuery::query()->sole();

    expect($sample)->not->toBeNull()
        ->and($sample?->query_count)->toBe(1)
        ->and($slow->perf_sample_id)->toBe($sample?->id)
        ->and($slow->sql_text)->toBe('select ? as value')
        ->and($slow->sql_hash)->toBe(sha1('select ? as value'))
        ->and(PerfSlowQuery::query()->where('sql_text', 'like', '%valeur-privée%')->exists())->toBeFalse();
});

it('écrit l\'échantillon d\'un job avec sa file et son issue', function (): void {
    dispatch(static function (): void {
        DB::select('select 1');
    });

    $sample = PerfSample::query()->where('kind', PerfSample::KIND_JOB)->sole();

    expect($sample->status)->toBe(PerfSample::STATUS_PROCESSED)
        ->and($sample->query_count)->toBeGreaterThanOrEqual(1)
        ->and($sample->queue)->not->toBeNull();
});

it('n\'écrit rien quand la mesure est coupée', function (): void {
    Config::set('perf.enabled', false);

    $this->get('/')->assertOk();

    expect(PerfSample::query()->count())->toBe(0);
});

it('ne casse jamais une requête quand l\'écriture de la mesure échoue', function (): void {
    Schema::drop('perf_slow_query');
    Schema::drop('perf_sample');

    $this->get('/')->assertOk();
});

it('n\'échantillonne rien à un taux nul', function (): void {
    Config::set('perf.sample_rate', 0.0);

    $this->get('/')->assertOk();

    expect(PerfSample::query()->count())->toBe(0);
});
