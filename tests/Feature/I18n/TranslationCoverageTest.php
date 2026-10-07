<?php

use App\Avatars\AvatarPresetCatalog;
use App\Avatars\AvatarRef;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ContentReportReason;
use App\Enums\ErrorPageStatus;
use App\Enums\FrameProcessingFailure;
use App\Enums\InputDifficulty;
use App\Enums\JoinRefusal;
use App\Enums\LegalPage;
use App\Enums\Locale;
use App\Enums\PoolFault;
use App\Enums\PoolRemedyKind;
use App\Enums\RoomRefusal;
use App\Enums\SettingPresetKey;
use App\Http\Controllers\Room\LaunchController;
use App\Rules\ValidNickname;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsEditor;
use App\Support\Frames\CropViolation;
use App\Support\I18n\TranslationDomains;
use App\Support\Tmdb\TmdbErrorKind;
use Illuminate\Support\Facades\App;

/**
 * Les trois vérifications de couverture que la spec 05 exige en CI, plus le
 * contrôle de dérive de `translations.d.ts`.
 *
 * Portée : le **domaine joueur** seulement — `mail` compris, gabarits de
 * réponse aux demandes de retrait inclus, puisqu'ils partent vers un tiers
 * extérieur dont la langue n'est pas celle du curateur. Seul `admin` en est
 * exclu : il est français par construction et `lang/en/admin.php` n'existe
 * pas.
 *
 * Ces vérifications lisent les **fichiers**, jamais le `Translator` : c'est le
 * contenu versionné que la CI doit refuser, pas ce qu'un repli de locale
 * rattrape à l'exécution. Les chemins sont dérivés de `__DIR__` et non de
 * `lang_path()`, parce qu'un jeu de données Pest est résolu avant que
 * l'application ne soit construite.
 */
function i18nBasePath(string $path): string
{
    return dirname(__DIR__, 3).'/'.$path;
}

/**
 * @param  array<array-key, mixed>  $lines
 * @return array<string, string>
 */
function i18nFlatten(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat = array_merge($flat, i18nFlatten($value, $path));
        } elseif (is_string($value)) {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

/**
 * @return array<string, string>
 */
function i18nFile(string $locale, string $domain): array
{
    $path = i18nBasePath('lang/'.$locale.'/'.$domain.'.php');

    if (! is_file($path)) {
        return [];
    }

    $lines = require $path;

    return is_array($lines) ? i18nFlatten($lines) : [];
}

/**
 * @return array<string, string>
 */
function i18nJson(string $locale): array
{
    $decoded = json_decode((string) file_get_contents(i18nBasePath('lang/'.$locale.'.json')), true);

    return is_array($decoded) ? i18nFlatten($decoded) : [];
}

/**
 * Jeu de `:placeholder` d'une ligne, trié : c'est lui qui doit être identique
 * d'une langue à l'autre, pas l'ordre des mots.
 *
 * @return list<string>
 */
function i18nPlaceholders(string $line): array
{
    preg_match_all('/:(?!:)([A-Za-z][A-Za-z0-9_]*)/', $line, $matches);

    $found = array_values(array_unique($matches[1]));
    sort($found);

    return $found;
}

/**
 * Domaines soumis à la symétrie : tout fichier de `lang/en`, framework
 * compris, sauf le domaine du back-office.
 *
 * @return list<string>
 */
function i18nCheckedDomains(): array
{
    $domains = [];

    foreach (glob(i18nBasePath('lang/en/*.php')) ?: [] as $file) {
        $domain = basename($file, '.php');

        if ($domain !== TranslationDomains::ADMIN) {
            $domains[] = $domain;
        }
    }

    sort($domains);

    return $domains;
}

/**
 * Littéraux passés à `t()` / `tChoice()` dans le front, fichier par fichier.
 *
 * Un balayage par expression régulière suffit parce que `t()` est maison et
 * que ses clés sont des littéraux. L'appel est précédé d'un caractère qui
 * n'est ni un mot ni un point : `format(`, `object.t(` et `assert(` ne
 * ressemblent donc pas à un appel de traduction.
 *
 * @return array<string, list<string>>
 */
function i18nFrontCalls(): array
{
    $ignored = [
        '/resources/js/routes/',
        '/resources/js/actions/',
        '/resources/js/wayfinder/',
        '/resources/js/components/ui/',
    ];

    $calls = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(i18nBasePath('resources/js'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        foreach ($ignored as $prefix) {
            if (str_contains($path, $prefix)) {
                continue 2;
            }
        }

        preg_match_all(
            '/(?<![\w$.])t(?:Choice)?\(\s*[\'"]([^\'"]+)[\'"]/',
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        if ($matches[1] !== []) {
            $calls[$path] = array_values(array_unique($matches[1]));
        }
    }

    return $calls;
}

/**
 * Littéraux passés à `__()` / `trans()` dans `app/`, fichier par fichier.
 *
 * Symétrique de {@see i18nFrontCalls()}, et il manquait : c'est le SERVEUR qui
 * produit les messages de validation, les e-mails et les motifs de refus,
 * c'est-à-dire tout ce que la règle 4 nomme explicitement. Sans lui, une clé
 * appelée par du code livré et absente de tout dictionnaire laisse la suite
 * entièrement verte et s'affiche brute à l'utilisateur.
 *
 * Le filtre `domaine.chemin` écarte d'office les clés littérales de la famille
 * Fortify (`__('Profile updated.')`), qui vivent dans `lang/*.json`. Le littéral
 * doit **terminer** l'argument : `trans('validation.attributes.'.$field)` est
 * un préfixe concaténé, pas une clé, et les constructeurs de clés de ce genre
 * sont couverts un par un plus bas.
 *
 * @return array<string, list<string>>
 */
function i18nServerCalls(): array
{
    $calls = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(i18nBasePath('app'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all(
            '/(?<![\w$>])(?:__|trans)\(\s*\'([a-z][a-z0-9_]*\.[^\']+)\'\s*[,)]/',
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        if ($matches[1] !== []) {
            $calls[str_replace('\\', '/', $file->getPathname())] = array_values(array_unique($matches[1]));
        }
    }

    return $calls;
}

/**
 * La locale de référence d'un domaine : `en` partout, `fr` pour le back-office,
 * français par construction (décision 9).
 */
function i18nReferenceLocale(string $domain): string
{
    return $domain === TranslationDomains::ADMIN ? 'fr' : 'en';
}

/**
 * Vrai si la clé pointée existe dans le dictionnaire de référence de son
 * domaine. Lit les **fichiers**, comme le reste de ce fichier : c'est le
 * contenu versionné que la CI doit refuser, pas ce qu'un repli rattrape.
 */
function i18nKeyExists(string $key): bool
{
    [$domain, $path] = array_pad(explode('.', $key, 2), 2, '');

    if ($path === '') {
        return false;
    }

    return array_key_exists($path, i18nFile(i18nReferenceLocale($domain), $domain));
}

/**
 * Les quinze clés `validation.room_settings.*` : les douze suffixes de bornes
 * croisées émis par `App\Settings\RoomSettings`, dont le préfixe est concaténé
 * et donc invisible au balayage, et les trois refus de l'écriture des réglages
 * (`RoomSettingsEditor`, garde de capacité), listés ici avec eux.
 *
 * @return list<string>
 */
function i18nRoomSettingsKeys(): array
{
    return array_map(
        static fn (string $suffix): string => 'validation.room_settings.'.$suffix,
        [
            'between', 'boolean', 'capacity_below_headcount', 'duration_mismatch',
            'enum', 'integer', 'integer_list', 'list_size', 'not_editable',
            'round_duration', 'sum_between', 'theme_ids', 'theme_keys',
            'tier_duration', 'unknown_field',
        ],
    );
}

/**
 * Les valeurs des constantes de `RoomSettings` d'un préfixe donné — codes du
 * rapport de changements (`CHANGE_`) ou des avertissements (`WARNING_`) —,
 * lues par réflexion : un code ajouté sans sa clé échoue ici.
 *
 * @return list<string>
 */
function i18nRoomSettingsCodes(string $prefix): array
{
    $codes = [];

    foreach ((new ReflectionClass(RoomSettings::class))->getConstants() as $name => $value) {
        if (str_starts_with($name, $prefix) && is_string($value)) {
            $codes[] = $value;
        }
    }

    return $codes;
}

/**
 * Les six motifs portés par `ImportOutcome::$reasonKey`, construits par
 * concaténation eux aussi.
 *
 * @return list<string>
 */
function i18nImportReasonKeys(): array
{
    return [
        'admin.catalog.import.actor_unknown',
        'admin.catalog.import.refused.adult',
        'admin.catalog.import.refused.certification',
        'admin.catalog.import.refused.withdrawn',
        'admin.catalog.import.skipped.duplicate',
        'admin.catalog.import.skipped.filter',
        'admin.catalog.import.skipped.not_found',
    ];
}

it('keeps the same keys in fr and en for every checked domain', function (string $domain) {
    $en = i18nFile('en', $domain);
    $fr = i18nFile('fr', $domain);

    expect(array_diff(array_keys($en), array_keys($fr)))
        ->toBe([], "Clés présentes en `en` et absentes en `fr` dans le domaine [{$domain}].");

    expect(array_diff(array_keys($fr), array_keys($en)))
        ->toBe([], "Clés présentes en `fr` et absentes en `en` dans le domaine [{$domain}].");
})->with(i18nCheckedDomains());

it('keeps the same placeholders in both translations of a key', function (string $domain) {
    $en = i18nFile('en', $domain);
    $fr = i18nFile('fr', $domain);

    $mismatched = [];

    foreach (array_intersect_key($en, $fr) as $key => $line) {
        if (i18nPlaceholders($line) !== i18nPlaceholders($fr[$key])) {
            $mismatched[] = $domain.'.'.$key;
        }
    }

    expect($mismatched)->toBe(
        [],
        'Un :placeholder oublié ne se voit pas à la relecture et produit une phrase fausse en production.',
    );
})->with(i18nCheckedDomains());

it('keeps the literal dictionaries of fortify and the framework symmetrical', function () {
    $en = i18nJson('en');
    $fr = i18nJson('fr');

    expect($en)->not->toBeEmpty();
    expect(array_diff(array_keys($en), array_keys($fr)))->toBe([]);
    expect(array_diff(array_keys($fr), array_keys($en)))->toBe([]);

    $mismatched = [];

    foreach (array_intersect_key($en, $fr) as $key => $line) {
        if (i18nPlaceholders($line) !== i18nPlaceholders($fr[$key])) {
            $mismatched[] = $key;
        }
    }

    expect($mismatched)->toBe([]);
});

it('carries the two literal keys the repository already emits', function () {
    // Le dépôt n'en compte que deux et la spec 05 les nomme : ce sont des
    // chaînes de la famille Fortify, pas des clés TripleFrames. Toute clé
    // écrite pour le projet est de la forme `domaine.chemin.clé`.
    expect(i18nJson('fr'))->toHaveKeys(['Profile updated.', 'Password updated.']);
});

it('only calls translation keys that exist in a dictionary', function () {
    $known = [];

    foreach ([...i18nCheckedDomains(), TranslationDomains::ADMIN] as $domain) {
        foreach (['en', 'fr'] as $locale) {
            foreach (array_keys(i18nFile($locale, $domain)) as $key) {
                $known[$domain.'.'.$key] = true;
            }
        }
    }

    $unknown = [];

    foreach (i18nFrontCalls() as $path => $keys) {
        foreach ($keys as $key) {
            if (! isset($known[$key])) {
                $unknown[] = $key.' ('.$path.')';
            }
        }
    }

    expect($unknown)->toBe([], 'Clé appelée par le front et absente de tout dictionnaire.');
});

it('keeps the generated translation types in sync with the dictionaries', function () {
    $this->artisan('lang:types', ['--check' => true])->assertSuccessful();
});

it('only calls translation keys that exist in a dictionary, from the server too', function () {
    $unknown = [];

    foreach (i18nServerCalls() as $path => $keys) {
        foreach ($keys as $key) {
            if (! i18nKeyExists($key)) {
                $unknown[] = $key.' ('.$path.')';
            }
        }
    }

    expect($unknown)->toBe([], 'Clé appelée par `app/` et absente de tout dictionnaire.');
});

it('carries every key built by an enumerable key constructor', function () {
    // Ces familles sont construites par concaténation : aucun balayage
    // de littéraux ne les verra jamais, et ce sont elles qui manquaient.
    $expected = [
        ...array_map(
            static fn (TmdbErrorKind $kind): string => $kind->translationKey(),
            TmdbErrorKind::cases(),
        ),
        ...array_merge(...array_map(
            static fn (SettingPresetKey $key): array => [$key->labelKey(), $key->descriptionKey()],
            SettingPresetKey::cases(),
        )),
        ...i18nRoomSettingsKeys(),
        ...i18nImportReasonKeys(),
        // Titre de chaque page publique (spec 90 § 6.7, L90-4).
        ...array_map(
            static fn (LegalPage $page): string => $page->titleKey(),
            LegalPage::cases(),
        ),
        // Les six erreurs HTTP rendues en page (spec 90 § 4.8, L90-5) : page
        // `error` joueur, clés construites par l'enum, et page `admin/error`
        // du back-office, que le même gestionnaire choisit pour les mêmes
        // statuts.
        ...array_merge(...array_map(
            static fn (ErrorPageStatus $status): array => [
                $status->titleKey(),
                $status->descriptionKey(),
                "admin.error.http.{$status->value}.title",
                "admin.error.http.{$status->value}.description",
            ],
            ErrorPageStatus::cases(),
        )),
        // Libellés du journal `admin_action` et de ses sujets (20 § 2.7, L20-1).
        ...array_map(
            static fn (AdminActionType $action): string => $action->labelKey(),
            AdminActionType::cases(),
        ),
        ...array_map(
            static fn (AdminActionSubject $subject): string => $subject->labelKey(),
            AdminActionSubject::cases(),
        ),
        // Motifs d'un signalement de contenu, côté joueur et côté
        // back-office (D63 du 07/10).
        ...array_merge(...array_map(
            static fn (ContentReportReason $reason): array => [$reason->labelKey(), $reason->adminLabelKey()],
            ContentReportReason::cases(),
        )),
        // Refus d'un cadre de recadrage (20 § 5.2, L20-4).
        ...array_map(
            static fn (CropViolation $violation): string => $violation->translationKey(),
            CropViolation::cases(),
        ),
        // Échec du traitement d'une image : la valeur EST la clé (20 § 5.6, L20-5).
        ...array_map(
            static fn (FrameProcessingFailure $failure): string => $failure->value,
            FrameProcessingFailure::cases(),
        ),
        // Libellé de chaque avatar prédéfini, bâti sur la clé du catalogue,
        // et les trois clés `alt` d'`AvatarRef` (40 § 6.5 et § 6.7, L40-5).
        ...array_map(
            static fn (string $key): string => AvatarPresetCatalog::labelKey($key),
            AvatarPresetCatalog::keys(),
        ),
        AvatarRef::ALT_KEY_PRESET,
        AvatarRef::ALT_KEY_PROVIDER,
        AvatarRef::ALT_KEY_INITIALS,
        // Les sept messages du pseudo, `taken` de `50` compris (40 § 5.9,
        // L40-3) : la règle les émet par constante, jamais par littéral.
        ...ValidNickname::MESSAGE_KEYS,
        // Refus d'une prise de siège (50 § 7.3, L50-3b) : `room.join.<valeur>`,
        // sauf le salon archivé, que la page « salon expiré » dit seule.
        ...array_values(array_filter(array_map(
            static fn (JoinRefusal $refusal): ?string => $refusal->messageKey(),
            JoinRefusal::cases(),
        ))),
        // Refus d'un geste de salon (50 § 12.5, L50-7a) : `room.refusal.<valeur>`,
        // sauf le drainage, dont la clé unique est `common.maintenance.launch_blocked`
        // (R-09) ; et l'échec technique du lancement, rendu par sa constante.
        ...array_map(
            static fn (RoomRefusal $refusal): string => $refusal->messageKey(),
            RoomRefusal::cases(),
        ),
        LaunchController::KEY_LAUNCH_FAILED,
        // Le vivier au lobby (50 § 9.2, L50-4) : le réglage fautif nommé, une
        // clé par cas de `PoolFault`, et le geste proposé, une clé par cas de
        // `PoolRemedyKind` — construites côté client par une table.
        ...array_map(
            static fn (PoolFault $fault): string => "room.pool.cause.{$fault->value}",
            PoolFault::cases(),
        ),
        ...array_map(
            static fn (PoolRemedyKind $kind): string => "room.pool.remedy.{$kind->value}",
            PoolRemedyKind::cases(),
        ),
        // Les réglages du lobby (50 § 20.1 et § 20.2, L50-5), construits
        // côté client par des tables : le rapport de changements, une clé par
        // code `CHANGE_*` ; les avertissements, une clé par code `WARNING_*` ;
        // un libellé et une aide par clé postable de l'un ou l'autre onglet
        // (le libellé nomme aussi le champ dans le rapport) ; une option par
        // difficulté de saisie.
        ...array_map(
            static fn (string $code): string => "room.settings.change.{$code}",
            i18nRoomSettingsCodes('CHANGE_'),
        ),
        ...array_map(
            static fn (string $code): string => "room.warnings.{$code}",
            i18nRoomSettingsCodes('WARNING_'),
        ),
        ...array_merge(...array_map(
            static fn (string $field): array => ["room.settings.{$field}.label", "room.settings.{$field}.help"],
            array_values(array_unique([...RoomSettingsEditor::SIMPLE_KEYS, ...RoomSettingsEditor::ADVANCED_KEYS])),
        )),
        ...array_map(
            static fn (InputDifficulty $difficulty): string => "room.settings.inputDifficulty.option.{$difficulty->value}",
            InputDifficulty::cases(),
        ),
    ];

    $missing = array_values(array_filter(
        $expected,
        static fn (string $key): bool => ! i18nKeyExists($key),
    ));

    expect($missing)->toBe([], 'Clé construite par du code livré et absente de son dictionnaire.');
});

it('renders the admin dictionary in french whatever the ambient locale', function (TmdbErrorKind $kind) {
    // Le défaut d'instance est `en` et `lang/en/admin.php` n'existe pas : une
    // console, un job ou un envoi hors requête qui s'en remettrait à la locale
    // ambiante afficherait la CLÉ BRUTE, substitutions perdues. Le back-office
    // résout donc toujours avec une locale explicite.
    App::setLocale(Locale::English->value);

    $rendered = __($kind->translationKey(), ['status' => 401], Locale::French->value);

    expect($rendered)->toBeString()
        ->and($rendered)->not->toBe($kind->translationKey())
        ->and($rendered)->not->toContain(':status');
})->with(TmdbErrorKind::cases());

it('keeps every room preset nameable in both languages', function (SettingPresetKey $key) {
    // `setting_preset` n'a AUCUNE colonne de libellé (spec 10 § 6.3) : laisser
    // `room.php` vide reviendrait à dire que ces presets n'ont pas de nom, et
    // le sélecteur afficherait `room.presets.classic.label` à l'hôte.
    foreach ([Locale::English, Locale::French] as $locale) {
        foreach ([$key->labelKey(), $key->descriptionKey()] as $translationKey) {
            expect(__($translationKey, [], $locale->value))
                ->toBeString()
                ->not->toBe($translationKey);
        }
    }
})->with(SettingPresetKey::cases());
