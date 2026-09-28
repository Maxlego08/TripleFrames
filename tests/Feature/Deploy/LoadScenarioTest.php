<?php

use App\Console\Commands\LoadTestForgetCommand;
use App\Enums\SettingPresetKey;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;

/*
|--------------------------------------------------------------------------
| Scénario k6 du test de charge — spec 100 § 16.1, lot L100-12
|--------------------------------------------------------------------------
|
| `tests/Load/game-load.js` n'est joué que depuis le poste du porteur, jamais
| en CI : rien ne le fait tourner ici. Ce test garde ce que la CI PEUT voir de
| lui — ajouté à la livraison, hors des intitulés du lot :
|
| - les constantes qu'aucune prop ne publie, recopiées dans le scénario,
|   restent égales à leur source (préfixe synthétique de `loadtest:forget`,
|   preset du scénario A, sièges d'un salon plein, grâce de frontière,
|   attente d'un job réveillé tôt, nouvelles tentatives d'image du client) :
|   une dérive rendrait la séance fausse sans qu'aucun seuil ne le dise ;
| - la cible vient de `K6_BASE_URL`, jamais d'un hôte écrit dans le fichier.
|
*/

function loadScenarioSource(): string
{
    $source = file_get_contents(base_path('tests/Load/game-load.js'));

    expect($source)->toBeString();

    return (string) $source;
}

/** La valeur littérale de la constante `$name` du scénario. */
function loadScenarioConstant(string $name): string
{
    expect(preg_match('/^const '.preg_quote($name, '/').' = (\'[^\']*\'|\d+);$/m', loadScenarioSource(), $match))
        ->toBe(1, "Constante {$name} introuvable dans tests/Load/game-load.js.");

    return trim($match[1], "'");
}

it('reprend le préfixe synthétique de loadtest:forget et le preset du scénario A', function (): void {
    expect(loadScenarioConstant('SYNTHETIC_PREFIX'))->toBe(LoadTestForgetCommand::SYNTHETIC_NICKNAME_PREFIX)
        ->and(loadScenarioConstant('PRESET_KEY'))->toBe(SettingPresetKey::Classic->value);
});

it('reprend les constantes du serveur et du client qu’aucune prop ne publie', function (): void {
    $frameLoader = (string) file_get_contents(resource_path('js/lib/game/frame-loader.ts'));

    expect((int) loadScenarioConstant('DEFAULT_ROOM_SEATS'))->toBe(PlatformLimits::roomSeats())
        ->and((int) loadScenarioConstant('TIER_GRACE_MS'))->toBe(PlatformLimits::tierGraceMs())
        ->and((int) loadScenarioConstant('DEFAULT_TRANSITION_MAX_WAIT_MS'))->toBe(EngineConstants::transitionMaxWaitMs());

    foreach (['FRAME_RETRY_DELAY_MS', 'FRAME_MAX_ATTEMPTS'] as $name) {
        expect(preg_match('/^export const '.$name.' = (\d+);$/m', $frameLoader, $match))->toBe(1)
            ->and(loadScenarioConstant($name))->toBe($match[1]);
    }
});

it('ne vise aucun hôte écrit dans le fichier, seulement K6_BASE_URL', function (): void {
    $source = loadScenarioSource();

    preg_match_all('#\b(?:https?|wss?)://([^\s\'"`/:,)]+)#u', $source, $matches);

    // Seuls restent les gabarits de la documentation : `<DOMAINE>` et
    // l'ellipsis d'une liste de voisins passée par NEIGHBOUR_URLS.
    expect(array_values(array_diff(array_unique($matches[1]), ['<DOMAINE>', '…'])))->toBe([])
        ->and($source)->toContain('__ENV.K6_BASE_URL');
});
