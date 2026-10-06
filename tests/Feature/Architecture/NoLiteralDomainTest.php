<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Aucun domaine littéral — spec 100 § 7.2
|--------------------------------------------------------------------------
|
| Le domaine n'est pas choisi (décision 5) et se fige simultanément dans une
| dizaine d'endroits. Tant qu'il ne l'est pas, ET APRÈS : sa valeur ne vit
| que dans l'environnement (`.env` de production, réglages Plesk), jamais
| dans le dépôt. Le dépôt écrit `<DOMAINE>`, toujours. La garde est donc
| PERMANENTE et ne bascule pas le jour de l'achat.
|
| Périmètre : `docs/specs/**`, `config/**`, `.env.example` et les gabarits
| d'exploitation `ops/**` (qui sont de la configuration).
|
| Un hôte est reconnu sous trois formes :
|
| 1. l'AUTORITÉ d'une URL de schéma réseau (http, https, ws, wss, ftp),
|    retenue seulement si elle a la forme d'un nom DNS. Un flux PHP
|    (`php://stderr`), un pseudo-schéma (`tls://`) et une autorité bâtie par
|    concaténation (`'tls://'.env(...)`, vide) ne sont pas des hôtes. Un
|    littéral IPv4 de bouclage (127.0.0.0/8) non plus ; tout autre littéral
|    IPv4 en est un (l'adresse d'une machine n'a pas plus sa place ici que
|    son nom), et un littéral IPv6 (`[::1]`) n'a pas la forme d'un nom DNS ;
| 2. le DOMAINE d'une adresse électronique, retenu seulement s'il contient
|    un point : `ProbeController@show` n'est pas une adresse ;
| 3. un NOM NU `étiquette(.étiquette)*.tld` dont le tld appartient à une
|    liste close, restreinte aux suffixes qui ne sont pas des extensions de
|    fichier du dépôt.
|
| Une étiquette de premier niveau entièrement numérique n'est jamais un nom
| DNS (RFC 3696 § 2) : `pao@1.0.6` est une version, pas une adresse.
|
*/

/**
 * Liste d'autorisation commentée (n° 78) : motif de chaque entrée. `*.x`
 * désigne les sous-domaines de `x`, jamais `x` lui-même.
 *
 * Toute nouvelle entrée (hôte OAuth de Google ou de Discord au J2, par
 * exemple) s'ajoute dans le commit qui l'introduit, avec son motif.
 *
 * @return array<string, string>
 */
function literalDomainAllowlist(): array
{
    $reserved = 'nom réservé (RFC 2606, RFC 6761)';

    return [
        // Noms réservés : ils ne désignent jamais une machine réelle.
        'localhost' => $reserved,
        '*.localhost' => $reserved,
        '*.test' => $reserved.' ; `tripleframes.test` des specs en relève',
        '*.example' => $reserved,
        '*.invalid' => $reserved,
        'example.com' => $reserved,
        '*.example.com' => $reserved,
        'example.net' => $reserved,
        '*.example.net' => $reserved,
        'example.org' => $reserved,
        '*.example.org' => $reserved,

        // Tiers nommé : flux sortant d'import seulement (config/services.php, règle 6).
        'api.themoviedb.org' => 'API TMDB, import et curation seulement (règle 6)',
        'image.tmdb.org' => 'images TMDB, import et curation seulement (règle 6)',
        'letterboxd.com' => 'lien sortant vers la fiche Letterboxd du film révélé, aucun appel serveur (D58 du 06/10)',

        // Documentation citée par les commentaires de configuration du framework.
        'inertiajs.com' => 'documentation, commentaire de config/inertia.php',
        'developer.mozilla.org' => 'documentation, commentaire de config/session.php',
        'docs.guzzlephp.org' => 'documentation, commentaires de config/broadcasting.php '
            .'(publié par config:publish broadcasting, L60-1) ; entrée posée dès L100-2',

        // Valeur par défaut inerte d'un pilote inutilisé du framework.
        'sqs.us-east-1.amazonaws.com' => 'valeur par défaut inerte de la connexion SQS (config/queue.php)',
    ];
}

/**
 * Chaînes qui ont la forme d'un nom nu sans en être un, PAR FICHIER : une
 * exception ne vaut que là où elle est écrite, pour ne jamais masquer un vrai
 * hôte ailleurs. Une exception qui n'apparaît plus dans son fichier fait
 * échouer le test : cette liste ne garde que ce qui sert.
 *
 * @return array<string, array<string, string>> fichier → chaîne → motif
 */
function literalDomainNonHosts(): array
{
    return [
        'docs/specs/90-ecrans-etats-et-structure.md' => [
            'legal.notice.fr' => 'nom de vue Blade cité par 90 § 4.1 pour expliquer FileViewFinder, pas un hôte',
        ],
    ];
}

/**
 * Suffixes de premier niveau reconnus dans un nom nu : liste close de la
 * spec, sans extension de fichier du dépôt.
 *
 * @return list<string>
 */
function literalDomainBareTlds(): array
{
    return ['com', 'net', 'org', 'fr', 'eu', 'io', 'co', 'me', 'info', 'dev', 'app', 'xyz', 'be', 'ch', 'de'];
}

/**
 * Motif d'une étiquette DNS ; `<DOMAINE>` compte pour une étiquette, pour que
 * `dev.<DOMAINE>` soit lu comme un seul nom.
 */
function literalDomainLabelPattern(): string
{
    return '(?:<DOMAINE>|[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)';
}

/**
 * Vrai si la chaîne a la forme d'un nom DNS : étiquettes séparées par des
 * points, dernière étiquette non entièrement numérique.
 */
function literalDomainIsDnsName(string $name): bool
{
    $label = literalDomainLabelPattern();

    return preg_match("/^{$label}(?:\\.{$label})*$/", $name) === 1
        && preg_match('/^\d+$/', Str::afterLast($name, '.')) !== 1;
}

/**
 * Hôtes littéraux reconnus dans un texte, en minuscules, sans doublon, dans
 * l'ordre d'apparition — y compris ceux que la liste d'autorisation admet.
 *
 * @return list<string>
 */
function literalDomainHosts(string $text): array
{
    $label = literalDomainLabelPattern();
    $name = "{$label}(?:\\.{$label})*";
    $hosts = [];

    // 1. Autorité d'une URL de schéma réseau : le plus long préfixe en forme
    //    de nom, suivi d'une fin d'autorité (`/`, `:`, guillemet, blanc) ou
    //    d'une ponctuation de texte, point final de phrase compris. Suivi
    //    d'autre chose (`$`, `{`, `_`…), ou d'un point qui ferme la chaîne
    //    avant une concaténation (`'https://s3.'.env(...)`), le préfixe n'est
    //    qu'un morceau d'une autorité construite : ignoré.
    preg_match_all(
        "#(?<![A-Za-z0-9])(?:https?|wss?|ftp)://({$name})(?=$|[/:?\\#\\s'\"`)\\]>,;*!|]|[.](?!['\"`]\\s*[.]))#im",
        $text,
        $matches,
    );

    foreach ($matches[1] as $authority) {
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $authority) === 1) {
            if (! str_starts_with($authority, '127.')) {
                $hosts[] = $authority;
            }

            continue;
        }

        if (literalDomainIsDnsName($authority)) {
            $hosts[] = $authority;
        }
    }

    // 2. Domaine d'une adresse électronique : au moins un point.
    preg_match_all("/[A-Za-z0-9._%+-]@({$label}(?:\\.{$label})+)(?![A-Za-z0-9-]|\\.(?:<|[A-Za-z0-9]))/", $text, $matches);

    foreach ($matches[1] as $domain) {
        if (literalDomainIsDnsName($domain)) {
            $hosts[] = $domain;
        }
    }

    // 3. Nom nu dont le suffixe appartient à la liste close. Jamais le
    //    début d'un nom plus long (`notice.fr.blade.php` n'est pas
    //    `notice.fr`), ni sa queue (`fr.blade` dans `x.fr.blade`), ni la
    //    suite d'une adresse électronique. Un point de TÊTE, lui, est admis :
    //    domaine de cookie (`.domaine.fr`), joker (`*.domaine.fr`) et suffixe
    //    concaténé (`'.pusher.com'` de config/broadcasting.php) sont des hôtes.
    $tlds = implode('|', literalDomainBareTlds());

    preg_match_all(
        "/(?<![A-Za-z0-9_@-])(?<![A-Za-z0-9_-][.])((?:{$label}\\.)+(?:{$tlds}|<DOMAINE>))(?![A-Za-z0-9_-]|\\.(?:<|[A-Za-z0-9]))/i",
        $text,
        $matches,
    );

    foreach ($matches[1] as $bare) {
        $hosts[] = $bare;
    }

    return array_values(array_unique(array_map(
        static fn (string $host): string => str_replace('<domaine>', '<DOMAINE>', strtolower($host)),
        $hosts,
    )));
}

/**
 * Vrai si l'hôte est le domaine du projet écrit en symbole, ou s'il figure
 * dans la liste d'autorisation.
 */
function literalDomainIsAllowed(string $host): bool
{
    if ($host === '<DOMAINE>' || str_ends_with($host, '.<DOMAINE>')) {
        return true;
    }

    foreach (array_keys(literalDomainAllowlist()) as $entry) {
        $allowed = str_starts_with($entry, '*.')
            ? str_ends_with($host, substr($entry, 1))
            : $host === $entry;

        if ($allowed) {
            return true;
        }
    }

    return false;
}

/**
 * Fichiers du périmètre, chemins relatifs (séparateur `/`), triés. Un
 * répertoire absent n'est pas une erreur : `ops/` naît avec L100-6.
 *
 * @return list<string>
 */
function literalDomainPerimeter(): array
{
    $files = ['.env.example'];

    foreach (['docs/specs', 'config', 'ops'] as $directory) {
        if (! is_dir(base_path($directory))) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));
            }
        }
    }

    sort($files);

    return $files;
}

it('écrit <DOMAINE> au lieu de tout hôte littéral dans les specs, la configuration et .env.example', function () {
    $files = literalDomainPerimeter();
    $nonHosts = literalDomainNonHosts();
    $violations = [];
    $recognised = [];

    foreach ($files as $file) {
        $lines = preg_split('/\R/', (string) file_get_contents(base_path($file))) ?: [];
        $exceptions = array_keys($nonHosts[$file] ?? []);

        foreach ($lines as $index => $line) {
            foreach (literalDomainHosts($line) as $host) {
                $recognised[$host] = true;

                if (literalDomainIsAllowed($host) || in_array($host, $exceptions, true)) {
                    continue;
                }

                $violations[] = sprintf('%s:%d : hôte littéral « %s » — écrire <DOMAINE>, ou l\'ajouter '
                    .'à la liste d\'autorisation avec son motif', $file, $index + 1, $host);
            }
        }
    }

    foreach ($nonHosts as $file => $exceptions) {
        $content = is_file(base_path($file)) ? (string) file_get_contents(base_path($file)) : '';

        foreach (array_keys($exceptions) as $exception) {
            if (! str_contains($content, $exception)) {
                $violations[] = "{$file} : exception périmée « {$exception} », à retirer de literalDomainNonHosts()";
            }
        }
    }

    expect($violations)->toBe([]);

    // Le balayage voit bien le périmètre et les hôtes qui s'y trouvent : un
    // détecteur muet serait vert pour de mauvaises raisons.
    expect($files)->toContain('.env.example', 'config/services.php', 'docs/specs/100-qualite-tests-et-ci.md')
        ->and(array_keys($recognised))->toContain('api.themoviedb.org', 'localhost', 'tripleframes.test', '<DOMAINE>');
});

it('reconnaît un hôte dans une URL, une adresse électronique et un nom nu, et ignore un flux php://stderr, une autorité construite par concaténation et une référence Classe@méthode', function () {
    // Reconnus, sous chacune des trois formes.
    expect(literalDomainHosts("'url' => 'https://api.tiers-inconnu.net/v3/movie',"))->toBe(['api.tiers-inconnu.net'])
        ->and(literalDomainHosts('Voir [la documentation](https://Docs.Tiers-Inconnu.IO).'))->toBe(['docs.tiers-inconnu.io'])
        ->and(literalDomainHosts('wss://temps-reel.tiers-inconnu.fr:443/app'))->toBe(['temps-reel.tiers-inconnu.fr'])
        ->and(literalDomainHosts('MAIL_FROM_ADDRESS="contact@studio-inconnu.fr"'))->toBe(['studio-inconnu.fr'])
        ->and(literalDomainHosts('héberger le jeu sur tripleframes-inconnu.app avant la semaine 4'))->toBe(['tripleframes-inconnu.app'])
        ->and(literalDomainHosts("'url' => 'http://192.0.2.10:8080',"))->toBe(['192.0.2.10'])
        // Nom nu à point de tête : domaine de cookie, joker, suffixe concaténé.
        ->and(literalDomainHosts('SESSION_DOMAIN=.tiers-inconnu.fr'))->toBe(['tiers-inconnu.fr'])
        ->and(literalDomainHosts('server_name *.tiers-inconnu.fr;'))->toBe(['tiers-inconnu.fr'])
        ->and(literalDomainHosts("'host' => 'api-'.env('X').'.tiers-inconnu.com',"))->toBe(['tiers-inconnu.com'])
        // Point final de phrase après une URL.
        ->and(literalDomainHosts('voir https://tiers-inconnu.fr.'))->toBe(['tiers-inconnu.fr'])
        // Autorité construite : son morceau `s3` n'est pas un hôte, le suffixe
        // concaténé en est un.
        ->and(literalDomainHosts("'endpoint' => 'https://s3.'.env('AWS_DEFAULT_REGION').'.tiers-inconnu.com',"))->toBe(['tiers-inconnu.com']);

    // Ignorés : flux PHP, autorité construite, référence Classe@méthode.
    expect(literalDomainHosts("'stream' => 'php://stderr',"))->toBe([])
        ->and(literalDomainHosts("'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),"))->toBe([])
        ->and(literalDomainHosts("'endpoint' => 'https://'.env('BACKUP_HOST').'/depot',"))->toBe([])
        ->and(literalDomainHosts("'url' => 'https://api.'.env('X').'/v1',"))->toBe([])
        ->and(literalDomainHosts('"https://{$host}/api" et "https://${HOST}/"'))->toBe([])
        ->and(literalDomainHosts('la route appelle ProbeController@show, puis Deploy@run.'))->toBe([])
        ->and(literalDomainHosts('`laravel/pao@1.0.6` et `vite-plus@0.3.0`'))->toBe([])
        ->and(literalDomainHosts('la vue legal/notice.fr.blade.php et config/app.php'))->toBe([])
        ->and(literalDomainHosts("'url' => 'http://127.0.0.1:13714', 'hote' => 'http://[::1]:8000'"))->toBe([]);

    // Le domaine du projet écrit en symbole est reconnu, puis admis ; un nom
    // réservé aussi ; un tiers inconnu, jamais.
    $symbol = literalDomainHosts('https://<DOMAINE>/app, wss://dev.<DOMAINE>, legal@<DOMAINE>, preprod.<DOMAINE>');

    expect($symbol)->toBe(['<DOMAINE>', 'dev.<DOMAINE>', 'preprod.<DOMAINE>'])
        ->and(array_filter($symbol, literalDomainIsAllowed(...)))->toBe($symbol)
        ->and(literalDomainIsAllowed('tripleframes.test'))->toBeTrue()
        ->and(literalDomainIsAllowed('mail.example.com'))->toBeTrue()
        ->and(literalDomainIsAllowed('example.com.tiers-inconnu.net'))->toBeFalse()
        ->and(literalDomainIsAllowed('test'))->toBeFalse()
        ->and(literalDomainIsAllowed('tiers-inconnu.net'))->toBeFalse();
});
