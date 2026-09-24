<?php

/*
|--------------------------------------------------------------------------
| Un outil de non-technicien — spec 20 § 13.1, L20-2
|--------------------------------------------------------------------------
|
| Décision 9 : aucune commande artisan sur le chemin du curateur. La consigne
| technique part au journal et aux sondes de `100` ; le curateur reçoit ce
| qu'il peut faire, jamais « lancez tel worker » ni « renseignez telle
| variable ». Le balayage lit le FICHIER `lang/fr/admin.php` — le contenu
| versionné, pas ce qu'un repli de locale rattraperait — hors
| `admin.console.*`, seule branche que lit la console et jamais un écran.
|
*/

/**
 * Le dictionnaire `admin`, aplati en `chemin.clé => texte`.
 *
 * @param  array<array-key, mixed>  $lines
 * @return array<string, string>
 */
function curatorMessagesFlatten(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat = array_merge($flat, curatorMessagesFlatten($value, $path));
        } elseif (is_string($value)) {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

/**
 * Ce qu'un texte affiché à un curateur ne nomme jamais, motif par motif.
 *
 * - la console, un worker, un terminal ou un shell ;
 * - une commande : le mot lui-même (« la commande d’import », « en ligne de
 *   commande »), un exécutable (`php`, `artisan`, `composer`, `npm`…), un nom
 *   de commande à deux-points (`queue:listen`, `catalog:import-ids`) ou une
 *   option (`--resync`, `--resume`) ;
 * - une variable d'environnement : un nom en capitales à tiret bas
 *   (`TMDB_API_KEY`), le fichier `.env`, ou les mots eux-mêmes.
 *
 * Un nom de commande exige un mot collé de part et d'autre du deux-points : la
 * ponctuation française (« Motif : langue »), les substitutions (`:max`) et
 * les URL (`https://`) n'y ressemblent pas.
 *
 * @return array<string, string> libellé → motif
 */
function curatorMessagesForbiddenPatterns(): array
{
    return [
        'la console' => '/\bconsoles?\b/iu',
        'un worker' => '/\bworkers?\b/iu',
        'un terminal ou un shell' => '/\b(?:terminal|shell|ssh)\b/iu',
        'le mot « commande »' => '/\b(?:lignes? de )?commandes?\b/iu',
        'un exécutable' => '/\b(?:php|artisan|composer|npm|npx|tinker|pnpm)\b/iu',
        'un nom de commande' => '/\b[a-z][a-z0-9-]*:[a-z][a-z0-9-]*/u',
        'une option de commande' => '/(?:^|[\s«"(])--[a-z]/u',
        'une variable d’environnement' => '/\b[A-Z][A-Z0-9]*_[A-Z0-9_]+\b/u',
        'le fichier .env' => '/(?:^|\s)\.env\b/u',
        'les mots « variable d’environnement »' => '/variables? d[\'’]environnement/iu',
    ];
}

/**
 * Les motifs que viole un texte.
 *
 * @return list<string>
 */
function curatorMessagesViolations(string $text): array
{
    $violations = [];

    foreach (curatorMessagesForbiddenPatterns() as $label => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $violations[] = $label;
        }
    }

    return $violations;
}

test('aucun texte du domaine admin affiché à un curateur ne nomme la console, un worker, une commande ni une variable d\'environnement', function (): void {
    // Le détecteur reconnaît les formes que les textes d'avant L20-2 portaient
    // réellement, et la commande nommée en toutes lettres : un balayage qui ne
    // verrait rien serait vert pour de mauvaises raisons.
    $formerTexts = [
        'Lancez « php artisan queue:listen --timeout=900 », ou « composer dev » qui le fait pour vous.',
        'Renseignez TMDB_API_READ_ACCESS_TOKEN ou TMDB_API_KEY.',
        'Employez --resync pour le remettre à jour.',
        'le balayage est suspendu et reprenable avec --resume.',
        'La console, elle, n’a pas cette borne.',
        'L’envoi ouvre un balayage et le confie à un worker.',
        'Ajoutez-la au fichier .env du serveur.',
        'Relancez la commande d’import depuis le serveur.',
        'Importez-les par la ligne de commande.',
    ];

    foreach ($formerTexts as $text) {
        expect(curatorMessagesViolations($text))->not->toBeEmpty($text);
    }

    // Et il laisse passer la ponctuation, les substitutions et les URL.
    foreach ([
        'Motif : langue originale',
        ':from à :to sur :total',
        "550\nhttps://www.themoviedb.org/movie/27205",
    ] as $text) {
        expect(curatorMessagesViolations($text))->toBe([], $text);
    }

    $lines = require lang_path('fr/admin.php');

    expect($lines)->toBeArray();

    $texts = array_filter(
        curatorMessagesFlatten($lines),
        static fn (string $key): bool => ! str_starts_with($key, 'console.'),
        ARRAY_FILTER_USE_KEY,
    );

    // Les messages réécrits du § 13.1 sont bien dans le balayage.
    expect($texts)->toHaveKeys([
        'import.runs.worker_missing',
        'import.disabled',
        'error.tmdb_disabled',
        'tmdb.error.not_configured',
        'tmdb.error.rate_limited',
        'tmdb.error.server_error',
        'tmdb.error.transport',
        'catalog.import.skipped.duplicate',
        'validation.ids.max',
        'import.ids.list.hint',
        'import.deferred_notice',
        'import.toast.queued',
        'import.runs.resume_unavailable_queued',
    ]);

    $offending = [];

    foreach ($texts as $key => $text) {
        foreach (curatorMessagesViolations($text) as $label) {
            $offending[] = "admin.{$key} nomme {$label} : {$text}";
        }
    }

    expect($offending)->toBe([]);
});
