<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Promotion en un geste — spec 100 § 18 (L100-15, n° 80)
|--------------------------------------------------------------------------
|
| `main` publie ses artefacts sur `deploy-preprod`, que la préproduction tire ;
| la production ne tire que `deploy`, que SEUL le workflow manuel
| `promote.yml` avance, par `ops/deploy/promote.sh`, en avance rapide sur un
| commit exact de `deploy-preprod`. Deux preuves : la lecture des workflows
| (ce que la CI fait vraiment), et le script joué contre un vrai dépôt git
| jetable (ce qu'il refuse vraiment).
|
*/

/** @return array<string, mixed> */
function promoteWorkflow(string $name): array
{
    $parsed = Yaml::parseFile(base_path(".github/workflows/{$name}"));

    expect($parsed)->toBeArray();

    /** @var array<string, mixed> $parsed */
    return $parsed;
}

/**
 * Le texte des étapes `run` d'un job, concaténé.
 *
 * @param  array<string, mixed>  $workflow
 */
function promoteJobScript(array $workflow, string $job): string
{
    $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];
    $definition = is_array($jobs[$job] ?? null) ? $jobs[$job] : [];
    $steps = is_array($definition['steps'] ?? null) ? $definition['steps'] : [];
    $runs = [];

    foreach ($steps as $step) {
        if (is_array($step) && is_string($step['run'] ?? null)) {
            $runs[] = $step['run'];
        }
    }

    return implode("\n", $runs);
}

/**
 * Le bash qui joue le script : celui du système, ou sous Windows celui de Git
 * for Windows, voisin de `git.exe` (jamais le `bash` de WSL, qui ne verrait pas
 * le même système de fichiers).
 */
function promoteBash(): ?string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return 'bash';
    }

    $git = Process::run(['where', 'git'])->output();

    foreach (preg_split('/\R/', trim($git)) ?: [] as $candidate) {
        $bash = dirname($candidate, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'bash.exe';

        if (is_file($bash)) {
            return $bash;
        }
    }

    return null;
}

it('publie les artefacts de main sur deploy-preprod et jamais sur deploy', function (): void {
    $script = promoteJobScript(promoteWorkflow('tests.yml'), 'artifacts');

    expect($script)->toContain('git push origin "$commit:refs/heads/deploy-preprod"')
        ->and(substr_count($script, 'git push'))->toBe(1)
        // La chaîne de deploy-preprod prolonge deploy : la promotion reste
        // toujours une avance rapide.
        ->and($script)->toContain('parent_branch=deploy')
        ->and($script)->not->toMatch('/git push[^\n]*(?:--force|\s-f\b)/');
});

it('promote n\'avance deploy qu\'en avance rapide sur le commit de deploy-preprod', function (): void {
    $workflow = promoteWorkflow('promote.yml');

    // Déclenchement manuel seulement, deux entrées obligatoires : le commit
    // joué et la confirmation des parcours Playwright.
    $on = $workflow['on'] ?? $workflow[true] ?? null;

    expect($on)->toBeArray()
        ->and(array_keys($on))->toBe(['workflow_dispatch']);

    $inputs = $on['workflow_dispatch']['inputs'] ?? [];

    expect($inputs['preprod_commit']['required'] ?? null)->toBeTrue()
        ->and($inputs['preprod_commit']['type'] ?? null)->toBe('string')
        ->and($inputs['playwright_journeys_played']['required'] ?? null)->toBeTrue()
        ->and($inputs['playwright_journeys_played']['type'] ?? null)->toBe('boolean')
        ->and($inputs['playwright_journeys_played']['default'] ?? null)->toBeFalse();

    // Lecture seule par défaut, écriture limitée au seul job.
    expect($workflow['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and(array_keys($workflow['jobs'] ?? []))->toBe(['promote'])
        ->and($workflow['jobs']['promote']['permissions'] ?? null)->toBe(['contents' => 'write'])
        ->and($workflow['jobs']['promote']['if'] ?? null)->toBe("github.ref == 'refs/heads/main'");

    $script = promoteJobScript($workflow, 'promote');

    // La confirmation est vérifiée AVANT le checkout qui garde l'identifiant.
    $steps = $workflow['jobs']['promote']['steps'];
    $names = array_map(static fn (array $step): string => (string) ($step['name'] ?? $step['uses'] ?? ''), $steps);

    expect($names)->toBe(['Check Confirmation', 'Checkout code', 'Promote'])
        ->and($steps[0]['run'])->toContain('if [ "$JOURNEYS_PLAYED" != "true" ]; then')
        ->and($steps[2]['run'])->toBe('bash ops/deploy/promote.sh origin "$PREPROD_COMMIT"');

    // Aucune entrée n'est interpolée dans un script : elles passent par env.
    expect($script)->not->toContain('${{');

    // Le script lui-même : avance rapide vérifiée, jamais de poussée forcée,
    // une seule poussée, vers deploy.
    $promote = (string) file_get_contents(base_path('ops/deploy/promote.sh'));

    expect($promote)->not->toContain("\r")
        ->and($promote)->toStartWith("#!/usr/bin/env bash\n")
        ->and($promote)->toContain("\nset -euo pipefail\n")
        ->and($promote)->toContain('git merge-base --is-ancestor "$CANDIDATE" refs/promote/deploy-preprod')
        ->and($promote)->toContain('git merge-base --is-ancestor "$current" "$CANDIDATE"')
        ->and(preg_match_all('/^\s*git push /m', $promote))->toBe(1)
        ->and($promote)->toContain('git push --quiet "$REMOTE" "${CANDIDATE}:refs/heads/deploy"')
        ->and($promote)->not->toContain('--force')
        ->and($promote)->not->toMatch('/git push[^\n]*\s-f\b/')
        ->and($promote)->not->toMatch('/"\+\$\{?CANDIDATE/');
});

it('refuse toute promotion qui ne serait pas une avance rapide sur un commit de deploy-preprod', function (): void {
    $bash = promoteBash();

    if ($bash === null) {
        $this->markTestSkipped('Aucun bash de Git for Windows voisin de git.exe : le script ne peut pas être joué ici (la CI Linux le joue).');
    }

    $root = storage_path('framework/testing/promote-'.Str::random(8));
    $remote = $root.'/remote.git';
    $work = $root.'/work';
    $clone = $root.'/clone';
    $script = base_path('ops/deploy/promote.sh');

    $git = static function (string $cwd, string ...$arguments): string {
        $result = Process::path($cwd)->env([
            'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.invalid',
            'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.invalid',
        ])->run(['git', ...$arguments]);

        expect($result->successful())->toBeTrue($result->errorOutput());

        return trim($result->output());
    };

    $commit = static function (string $message) use ($git, $work): string {
        file_put_contents($work.'/file.txt', $message);
        $git($work, 'add', 'file.txt');
        $git($work, 'commit', '--quiet', '-m', $message);

        return $git($work, 'rev-parse', 'HEAD');
    };

    $promote = static fn (string $candidate) => Process::path($clone)->run([$bash, $script, 'origin', $candidate]);

    try {
        mkdir($work, 0777, true);
        $git($root, 'init', '--quiet', '--bare', $remote);
        $git($work, 'init', '--quiet');
        $git($work, 'remote', 'add', 'origin', $remote);

        // deploy = A ; deploy-preprod = A ← B ← C (chaîne linéaire).
        $a = $commit('A');
        $git($work, 'push', '--quiet', 'origin', "{$a}:refs/heads/deploy");
        $b = $commit('B');
        $c = $commit('C');
        $git($work, 'push', '--quiet', 'origin', "{$c}:refs/heads/deploy-preprod");

        // Un commit hors de deploy-preprod, sur une autre branche.
        $git($work, 'checkout', '--quiet', '-b', 'other', $a);
        $stray = $commit('X');
        $git($work, 'push', '--quiet', 'origin', "{$stray}:refs/heads/other");

        $git($root, 'clone', '--quiet', $remote, $clone);

        // Un nom de branche n'est jamais promu : seul un commit exact l'est.
        expect($promote('deploy-preprod')->exitCode())->toBe(2)
            ->and($git($clone, 'ls-remote', 'origin', 'refs/heads/deploy'))->toStartWith($a);

        // Un commit étranger à deploy-preprod est refusé.
        expect($promote($stray)->exitCode())->toBe(3)
            ->and($git($clone, 'ls-remote', 'origin', 'refs/heads/deploy'))->toStartWith($a);

        // Le commit joué, B (pas la pointe C) : deploy avance exactement sur B.
        $result = $promote($b);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($git($clone, 'ls-remote', 'origin', 'refs/heads/deploy'))->toStartWith($b);

        // Rejouer est inoffensif.
        expect($promote($b)->exitCode())->toBe(0);

        // Revenir en arrière (A, ancêtre de deploy) n'est pas une avance rapide.
        expect($promote($a)->exitCode())->toBe(4)
            ->and($git($clone, 'ls-remote', 'origin', 'refs/heads/deploy'))->toStartWith($b);

        // Puis C : avance rapide de B à C.
        expect($promote($c)->successful())->toBeTrue()
            ->and($git($clone, 'ls-remote', 'origin', 'refs/heads/deploy'))->toStartWith($c);
    } finally {
        Process::run(PHP_OS_FAMILY === 'Windows'
            ? ['cmd', '/c', 'rmdir', '/s', '/q', $root]
            : ['rm', '-rf', '--', $root]);
    }
});
