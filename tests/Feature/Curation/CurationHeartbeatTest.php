<?php

use App\Actions\Curation\RecordCurationHeartbeat;
use App\Enums\ContentAvailability;
use App\Models\Movie;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| Le battement de débit — spec 20 § 10.1, lot L20-17 (décision 10)
|--------------------------------------------------------------------------
|
| La règle serveur : l'instant du dernier battement vit en cache, par
| curateur et par film ; un écart d'au plus `idle_seconds` s'ajoute au temps
| actif, un écart plus long ou un premier battement n'ajoute rien. Combinée à
| la règle client — un battement seulement après une saisie depuis le tick
| précédent, prouvée par Vitest dans `tests/Frontend/admin/
| curation-heartbeat.test.ts` —, elle exclut en entier toute pause plus
| longue que la fenêtre.
|
| Les instants sont posés par `travelTo()` : la route lit l'horloge, jamais
| un instant fourni par le client.
|
*/

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create();
    $this->start = CarbonImmutable::parse('2026-09-25 10:00:00');
});

/**
 * Un battement posté par l'écran, `$offset` secondes après le départ : la
 * route répond 204, sans corps.
 */
function curationHeartbeatPost(Movie $movie, User $curator, CarbonImmutable $start, int $offset): void
{
    test()->travelTo($start->addSeconds($offset));

    test()->actingAs($curator)
        ->post(route('admin.catalog.heartbeat', ['movie' => $movie->id]))
        ->assertNoContent();
}

/**
 * Le temps actif du film, relu en base.
 */
function curationHeartbeatActive(Movie $movie): int
{
    return (int) Movie::query()->whereKey($movie->id)->value('curation_active_seconds');
}

test('un battement dans la fenêtre d\'inactivité ajoute l\'écart au temps actif', function (): void {
    $movie = Movie::factory()->create(['curation_active_seconds' => 0]);
    $idle = Config::integer('catalog.curation.idle_seconds');
    $cadence = Config::integer('catalog.curation.heartbeat_seconds');

    // Premier battement : aucun écart mesurable, rien n'est compté.
    curationHeartbeatPost($movie, $this->curator, $this->start, 0);
    expect(curationHeartbeatActive($movie))->toBe(0);

    // Un tick plus tard, l'écart s'ajoute.
    curationHeartbeatPost($movie, $this->curator, $this->start, $cadence);
    expect(curationHeartbeatActive($movie))->toBe($cadence);

    // Une pause qui tient EXACTEMENT dans la fenêtre compte en entier, à la
    // reprise.
    curationHeartbeatPost($movie, $this->curator, $this->start, $cadence + $idle);
    expect(curationHeartbeatActive($movie))->toBe($cadence + $idle);

    // Le battement n'écrit que le temps actif : ni `updated_at`, ni rien
    // d'autre sur la ligne du film.
    $before = Movie::query()->whereKey($movie->id)->toBase()->value('updated_at');
    curationHeartbeatPost($movie, $this->curator, $this->start, 2 * $cadence + $idle);
    expect(Movie::query()->whereKey($movie->id)->toBase()->value('updated_at'))->toBe($before);
});

test('un dernier battement relu en chaîne numérique, comme le rend Redis, compte l\'écart', function (): void {
    // Le store Redis de la production écrit l'entier sans le sérialiser et le
    // relit en chaîne numérique ; le store `array` des tests garderait
    // l'entier. La clé est donc pré-remplie sous la forme que rend Redis.
    $movie = Movie::factory()->create(['curation_active_seconds' => 0]);
    $cadence = Config::integer('catalog.curation.heartbeat_seconds');

    Cache::put(
        RecordCurationHeartbeat::key($this->curator, $movie),
        (string) $this->start->getTimestamp(),
        2 * Config::integer('catalog.curation.idle_seconds'),
    );

    curationHeartbeatPost($movie, $this->curator, $this->start, $cadence);

    expect(curationHeartbeatActive($movie))->toBe($cadence);
});

test('une pause plus longue que la fenêtre n\'ajoute rien', function (): void {
    $movie = Movie::factory()->create(['curation_active_seconds' => 0]);
    $idle = Config::integer('catalog.curation.idle_seconds');
    $cadence = Config::integer('catalog.curation.heartbeat_seconds');
    $beat = fn (int $offset): int => app(RecordCurationHeartbeat::class)
        ->handle($this->curator, $movie, $this->start->addSeconds($offset));

    expect($beat(0))->toBe(0)
        ->and($beat($cadence))->toBe($cadence);

    // Une seconde de trop : l'écart entier est perdu, jamais sa part sous la
    // fenêtre.
    $resumed = $cadence + $idle + 1;

    expect($beat($resumed))->toBe(0);
    expect(curationHeartbeatActive($movie))->toBe($cadence);

    // Et le compte reprend au battement suivant.
    expect($beat($resumed + $cadence))->toBe($cadence);
    expect(curationHeartbeatActive($movie))->toBe(2 * $cadence);
});

test('une pause de cent secondes n\'ajoute rien au temps actif', function (): void {
    // L'exemple du § 10.1, au réglage par défaut : battement toutes les 15 s,
    // fenêtre de 60 s. Dernière saisie juste avant le tick de t = 0, reprise
    // à t = 100 : les ticks de 15 à 90 ne battent pas (aucune saisie depuis
    // le tick précédent, règle client), et le battement suivant part à
    // t = 105. Écart de 105 s : rien n'est compté.
    expect(Config::integer('catalog.curation.heartbeat_seconds'))->toBe(15)
        ->and(Config::integer('catalog.curation.idle_seconds'))->toBe(60);

    $movie = Movie::factory()->create(['curation_active_seconds' => 0]);

    curationHeartbeatPost($movie, $this->curator, $this->start, -15);
    curationHeartbeatPost($movie, $this->curator, $this->start, 0);

    expect(curationHeartbeatActive($movie))->toBe(15);

    curationHeartbeatPost($movie, $this->curator, $this->start, 105);

    // Les quinze secondes d'avant la pause, et aucune des cent cinq.
    expect(curationHeartbeatActive($movie))->toBe(15);
});

test('deux onglets sur le même film ne doublent pas le temps', function (): void {
    $movie = Movie::factory()->create(['curation_active_seconds' => 0]);

    // Deux onglets du même curateur battent chacun toutes les 15 s, décalés
    // de 7 s : huit battements en 52 s. Ils partagent la clé, si bien que
    // chaque écart se mesure depuis le battement précédent, quel que soit
    // l'onglet — 52 s comptées, là où deux compteurs séparés en auraient
    // compté 90.
    foreach ([0, 7, 15, 22, 30, 37, 45, 52] as $offset) {
        curationHeartbeatPost($movie, $this->curator, $this->start, $offset);
    }

    expect(curationHeartbeatActive($movie))->toBe(52);

    // Un second curateur sur le même film a SA clé : son premier battement
    // n'ajoute rien, et ne s'appuie jamais sur ceux du premier.
    curationHeartbeatPost($movie, User::factory()->curator()->create(), $this->start, 60);

    expect(curationHeartbeatActive($movie))->toBe(52);
});

test('un film de démonstration n\'est jamais compté', function (): void {
    $movie = Movie::factory()->demo()->create(['curation_active_seconds' => 0]);

    foreach ([0, 15, 30] as $offset) {
        curationHeartbeatPost($movie, $this->curator, $this->start, $offset);
    }

    expect(curationHeartbeatActive($movie))->toBe(0);
});

test('un battement sur un film déjà publié ou écarté n\'ajoute rien au temps actif', function (): void {
    $measured = 420;
    $cadence = Config::integer('catalog.curation.heartbeat_seconds');

    $terminated = [
        'publié' => Movie::factory()->published()->create(['curation_active_seconds' => $measured]),
        // Écarté : `unpublished` sans jamais avoir été publié (EN20-1).
        'écarté' => Movie::factory()->create([
            'availability' => ContentAvailability::Unpublished,
            'first_published_at' => null,
            'curation_active_seconds' => $measured,
        ]),
        // Dépublié puis revenu en brouillon (levée d'une suspension, J2) :
        // `first_published_at` reste posé, la passe 1 est close.
        'déjà publié, revenu en brouillon' => Movie::factory()->create([
            'availability' => ContentAvailability::Draft,
            'first_published_at' => $this->start->subDay(),
            'curation_active_seconds' => $measured,
        ]),
    ];

    foreach ($terminated as $label => $movie) {
        foreach ([0, $cadence, 2 * $cadence] as $offset) {
            curationHeartbeatPost($movie, $this->curator, $this->start, $offset);
        }

        expect(curationHeartbeatActive($movie))->toBe($measured, $label);
    }

    // Un film terminé ENTRE deux battements : le battement suivant ne compte
    // plus rien, même dans la fenêtre — la mesure est figée à la terminaison.
    $movie = Movie::factory()->create(['curation_active_seconds' => 0]);
    $beat = fn (int $offset): int => app(RecordCurationHeartbeat::class)
        ->handle($this->curator, $movie, $this->start->addSeconds($offset));

    $beat(0);
    $beat($cadence);

    Movie::query()->whereKey($movie->id)->update([
        'availability' => ContentAvailability::Published->value,
        'first_published_at' => $this->start->addSeconds($cadence + 1),
    ]);

    // L'instance est périmée — elle se croit encore brouillon : c'est
    // l'`UPDATE` conditionnel qui fait foi.
    expect($beat(2 * $cadence))->toBe(0);
    expect(curationHeartbeatActive($movie))->toBe($cadence);
});
