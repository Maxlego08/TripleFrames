<?php

use App\Avatars\AvatarPresetCatalog;
use App\Avatars\AvatarRef;
use App\Enums\Locale;
use App\Settings\PlatformLimits;
use App\Support\I18n\TranslationDomains;
use Illuminate\Support\Facades\Validator;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Identity\NicknameFormRequest;

/*
|--------------------------------------------------------------------------
| Registre des avatars prédéfinis — spec 40 § 6, contrat C5 (L40-5)
|--------------------------------------------------------------------------
|
| `AvatarPresetCatalog` est le seul registre des clés `preset-NN` : jeton,
| règle de formulaire, fabriques et couverture de traduction le lisent. Les
| fichiers de `public/avatars/` sont des actifs tiers (pack Kenney, CC0),
| tracés par `public/avatars/LICENSE.md`.
|
| 256 px et 20 Ko sont des exigences de FICHIER (C5 § 5), vérifiées ici et
| jamais lues à l'exécution. La garde « jamais moins de prédéfinis que de
| sièges » vit dans `PlatformLimitsTest` (C0, R-04), pas ici.
|
| Ajout de L40-6 : le miroir client du catalogue (`AvatarPresetKey` de
| `types/player.ts`, tables de `lib/game/avatar-keys.ts`) est lu sur la
| source — aucun DOM au jalon 1 (C18 § 2.4) —, `tsc` garantissant le reste.
|
*/

/**
 * Traduction d'une clé dans une locale, SANS repli : une clé absente de
 * cette locale rend la clé elle-même, au lieu du texte de la locale de repli.
 */
function avatarPresetTranslation(string $key, Locale $locale): mixed
{
    return app('translator')->get($key, [], $locale->value, false);
}

it('déclare exactement autant de clés de prédéfini que PlatformLimits::avatarPresets()', function () {
    $keys = AvatarPresetCatalog::keys();

    expect($keys)->toHaveCount(PlatformLimits::avatarPresets())
        ->and(array_unique($keys))->toHaveCount(count($keys));

    // Clés stables, indexées à partir de 1 dans l'ordre du catalogue.
    foreach ($keys as $index => $key) {
        expect($key)->toBe(sprintf(AvatarPresetCatalog::KEY_FORMAT, $index + 1))
            ->and($key)->toMatch('/^preset-\d{2,}$/');
    }

    // Aux valeurs par défaut, les 24 clés de D27 (`preset-01` à `preset-24`).
    expect(PlatformLimits::avatarPresets())->toBe(PlatformLimits::DEFAULT_AVATAR_PRESETS)
        ->and($keys[0])->toBe('preset-01')
        ->and($keys[count($keys) - 1])->toBe('preset-24');

    // Le catalogue suit la limite de plate-forme, jamais un littéral.
    platformLimitsConfigure(['avatar_presets' => PlatformLimits::roomSeats()]);

    expect(AvatarPresetCatalog::keys())->toHaveCount(PlatformLimits::roomSeats())
        ->and(AvatarPresetCatalog::keys())->toBe(array_slice($keys, 0, PlatformLimits::roomSeats()));
});

it("livre chaque prédéfini en WebP de 256 px d'au plus 20 Ko", function () {
    $size = 256;
    $maxBytes = 20 * 1024;
    $delivered = [];

    foreach (AvatarPresetCatalog::options() as $option) {
        // L'URL servie est celle d'`AvatarRef`, et elle désigne un fichier
        // statique de `public/`.
        expect($option['url'])->toBe(AvatarRef::presetUrl($option['key']))
            ->and(AvatarRef::preset($option['key'], 'AB')->url)->toBe($option['url']);

        $path = public_path(ltrim($option['url'], '/'));

        expect($path)->toBeFile();

        $bytes = (string) file_get_contents($path);
        $dimensions = getimagesizefromstring($bytes);

        expect(strlen($bytes))->toBeLessThanOrEqual($maxBytes, "{$option['key']} dépasse 20 Ko")
            ->and(substr($bytes, 0, 4))->toBe('RIFF')
            ->and(substr($bytes, 8, 4))->toBe('WEBP')
            ->and($dimensions)->toBeArray()
            ->and($dimensions[0] ?? null)->toBe($size)
            ->and($dimensions[1] ?? null)->toBe($size)
            ->and($dimensions['mime'] ?? null)->toBe('image/webp');

        $image = new Imagick;
        $image->readImageBlob($bytes);

        // Une seule image, et le fond transparent du pack conservé : le
        // sujet est centré, les coins de la toile sont vides.
        expect($image->getNumberImages())->toBe(1)
            ->and($image->getImageAlphaChannel())->toBeTrue();

        foreach ([[0, 0], [$size - 1, 0], [0, $size - 1], [$size - 1, $size - 1]] as [$x, $y]) {
            expect($image->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_ALPHA))
                ->toBe(0.0, "{$option['key']} : coin ({$x}, {$y}) opaque");
        }

        $image->clear();

        $delivered[] = basename($path);
    }

    // Le répertoire ne porte que les prédéfinis du catalogue et leur
    // licence : aucun fichier orphelin qu'aucune clé ne désigne.
    $present = array_map('basename', glob(public_path('avatars/*')) ?: []);
    $expected = [...$delivered, 'LICENSE.md'];

    sort($present);
    sort($expected);

    expect($present)->toBe($expected);
});

it("livre la licence du pack d'avatars à côté de ses fichiers", function () {
    $path = public_path('avatars/LICENSE.md');

    expect($path)->toBeFile();

    $licence = (string) file_get_contents($path);

    // Nom du pack, auteur, licence CC0 et son texte, URL de récupération,
    // date, empreinte de l'archive, transformations, contrôle de lisibilité
    // à 32 px sur le thème sombre (C5 I5.7, 40 § 6.2).
    expect($licence)->toContain('Kenney', 'CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/')
        ->and($licence)->toContain('Statement of Purpose')
        ->and($licence)->toMatch('#https://kenney\.nl/\S+\.zip#')
        ->and($licence)->toMatch('/\b20\d{2}-\d{2}-\d{2}\b/')
        ->and($licence)->toMatch('/`[0-9a-f]{64}`/')
        ->and($licence)->toContain('256 × 256', 'WebP')
        ->and($licence)->toMatch('/^## Lisibilité à 32 px sur le thème sombre$/m')
        ->and($licence)->toContain('Vérification du porteur');

    // Table clé → fichier d'origine : chaque clé une fois, chacune depuis un
    // fichier distinct de l'archive.
    $sources = [];

    foreach (AvatarPresetCatalog::keys() as $key) {
        preg_match_all('/^\|\s*`'.preg_quote($key, '/').'`\s*\|\s*`([^`]+\.png)`\s*\|/m', $licence, $matches);

        expect($matches[1])->toHaveCount(1, "{$key} : aucune ou plusieurs lignes dans la table clé → fichier d'origine");

        $sources[] = $matches[1][0];
    }

    expect(array_unique($sources))->toHaveCount(count($sources));
});

it('nomme chaque prédéfini dans chaque locale activée', function () {
    foreach (Locale::cases() as $locale) {
        $labels = [];

        foreach (AvatarPresetCatalog::keys() as $key) {
            $labelKey = AvatarPresetCatalog::labelKey($key);
            $label = avatarPresetTranslation($labelKey, $locale);

            expect($labelKey)->toBe(AvatarPresetCatalog::LABEL_KEY_PREFIX.$key)
                ->and($label)->toBeString()
                ->and($label)->not->toBe($labelKey, "{$labelKey} sans libellé en {$locale->value}")
                ->and(trim((string) $label))->not->toBe('');

            $labels[] = $label;
        }

        // Le libellé est le nom accessible d'une option du sélecteur : deux
        // options ne portent jamais le même.
        expect(array_unique($labels))->toHaveCount(count($labels));
    }

    // Les options exposent la même clé de libellé.
    foreach (AvatarPresetCatalog::options() as $option) {
        expect($option['labelKey'])->toBe(AvatarPresetCatalog::labelKey($option['key']));
    }
});

it("suggère l'avatar préféré s'il est libre, sinon le premier libre dans l'ordre du catalogue", function () {
    $keys = AvatarPresetCatalog::keys();

    // 1. Le préféré, s'il est au catalogue et libre.
    expect(AvatarPresetCatalog::suggest($keys[4], []))->toBe($keys[4])
        ->and(AvatarPresetCatalog::suggest($keys[4], [$keys[0], $keys[1]]))->toBe($keys[4]);

    // 2. Sinon le premier libre dans l'ordre de keys(), quel que soit l'ordre
    //    ou les doublons de la liste des avatars pris.
    expect(AvatarPresetCatalog::suggest($keys[4], [$keys[4]]))->toBe($keys[0])
        ->and(AvatarPresetCatalog::suggest($keys[4], [$keys[4], $keys[1], $keys[0], $keys[0]]))->toBe($keys[2])
        ->and(AvatarPresetCatalog::suggest(null, []))->toBe($keys[0])
        ->and(AvatarPresetCatalog::suggest(null, [$keys[0], $keys[1]]))->toBe($keys[2])
        ->and(AvatarPresetCatalog::suggest('inconnu', [$keys[0]]))->toBe($keys[1]);

    // 3. Tout est pris — impossible sous la garde de C0 — : le préféré s'il
    //    est valide, sinon la première clé. Le doublon reste permis.
    expect(AvatarPresetCatalog::suggest($keys[6], $keys))->toBe($keys[6])
        ->and(AvatarPresetCatalog::suggest(null, $keys))->toBe($keys[0])
        ->and(AvatarPresetCatalog::suggest('inconnu', $keys))->toBe($keys[0]);

    // Déterministe : mêmes entrées, même suggestion.
    expect(AvatarPresetCatalog::suggest($keys[4], array_reverse($keys)))
        ->toBe(AvatarPresetCatalog::suggest($keys[4], $keys));
});

it("refuse une clé d'avatar hors du catalogue", function () {
    $keys = AvatarPresetCatalog::keys();

    foreach ($keys as $key) {
        expect(AvatarPresetCatalog::has($key))->toBeTrue();
    }

    $outside = [
        sprintf(AvatarPresetCatalog::KEY_FORMAT, 0),
        sprintf(AvatarPresetCatalog::KEY_FORMAT, PlatformLimits::avatarPresets() + 1),
        'preset-1',
        'preset-001',
        'PRESET-01',
        ' preset-01',
        'preset-01 ',
        'preset-01.webp',
        '/avatars/preset-01.webp',
        '../preset-01',
        'bear',
        '',
    ];

    foreach ($outside as $key) {
        expect(AvatarPresetCatalog::has($key))->toBeFalse("« {$key} » accepté")
            ->and(fn (): string => AvatarPresetCatalog::labelKey($key))->toThrow(InvalidArgumentException::class)
            // Une revendication hors catalogue n'est jamais présélectionnée.
            ->and(AvatarPresetCatalog::suggest($key, []))->toBe($keys[0]);
    }

    // La règle de formulaire (`avatarPresetRules()`, L40-3) lit le même
    // registre : chaque clé passe, rien d'autre — ni tableau, ni chemin.
    $avatarPasses = static fn (mixed $value): bool => Validator::make(
        ['avatar' => $value],
        ['avatar' => (new NicknameFormRequest)->rules()['avatar']],
    )->passes();

    foreach ($keys as $key) {
        expect($avatarPasses($key))->toBeTrue("« {$key} » refusé par le formulaire");
    }

    foreach ([...$outside, null, [$keys[0]], 1] as $value) {
        expect($avatarPasses($value))->toBeFalse('« '.json_encode($value).' » accepté par le formulaire');
    }

    // Une clé sort du catalogue quand la limite de plate-forme baisse.
    $last = $keys[count($keys) - 1];

    platformLimitsConfigure(['avatar_presets' => count($keys) - 1]);

    expect(AvatarPresetCatalog::has($last))->toBeFalse()
        ->and(AvatarPresetCatalog::has($keys[0]))->toBeTrue()
        ->and($avatarPasses($last))->toBeFalse();
});

it("résout chaque clé alt d'AvatarRef dans le domaine common", function () {
    $altKeys = [AvatarRef::ALT_KEY_PRESET, AvatarRef::ALT_KEY_PROVIDER, AvatarRef::ALT_KEY_INITIALS];

    expect(array_unique($altKeys))->toHaveCount(3)
        ->and(TranslationDomains::KNOWN)->toContain(TranslationDomains::BASE);

    foreach ($altKeys as $key) {
        expect($key)->toStartWith(TranslationDomains::BASE.'.avatar.alt.');

        foreach (Locale::cases() as $locale) {
            $text = avatarPresetTranslation($key, $locale);

            expect($text)->toBeString()
                ->and($text)->not->toBe($key, "{$key} sans texte en {$locale->value}")
                ->and(trim((string) $text))->not->toBe('');
        }
    }

    // Chaque branche de la chaîne porte sa clé.
    expect(AvatarRef::preset(AvatarPresetCatalog::keys()[0], 'AB')->altKey)->toBe(AvatarRef::ALT_KEY_PRESET)
        ->and(AvatarRef::provider('copie.webp', 'AB')->altKey)->toBe(AvatarRef::ALT_KEY_PROVIDER)
        ->and(AvatarRef::initials('AB')->altKey)->toBe(AvatarRef::ALT_KEY_INITIALS);
});

it("couvre côté client exactement les clés du catalogue d'avatars", function () {
    // L'union `AvatarPresetKey` de `types/player.ts` (spec 40 § 7.4), lue
    // sur la source sans ses commentaires : c'est elle que `tsc` impose à
    // `AVATAR_PRESET_LABEL_KEYS`, donc elle qui doit égaler le catalogue.
    $types = FrontSource::withoutComments((string) file_get_contents(resource_path('js/types/player.ts')));

    expect(preg_match('/export type AvatarPresetKey\s*=([^;]+);/', $types, $union))->toBe(1);

    preg_match_all("/'(preset-\d{2})'/", $union[1], $literals);

    $client = $literals[1];
    $server = AvatarPresetCatalog::keys();

    sort($client);
    sort($server);

    // Liste triée SANS dédoublonnage : un littéral répété échoue aussi.
    expect($client)->toBe($server);

    // `tsc` garantit une ligne par clé dans la table des libellés, pas que
    // chaque ligne nomme SA clé : `preset-01` ne doit jamais afficher le
    // libellé de `preset-02`. Même lecture, sur `lib/game/avatar-keys.ts`.
    $keys = FrontSource::withoutComments((string) file_get_contents(resource_path('js/lib/game/avatar-keys.ts')));

    preg_match_all("/'(preset-\d{2})':\s*'([^']+)'/", $keys, $pairs, PREG_SET_ORDER);

    $labels = [];

    foreach ($pairs as [, $key, $labelKey]) {
        $labels[$key] = $labelKey;
    }

    expect($pairs)->toHaveCount(count($server))
        ->and(array_keys($labels))->toBe(AvatarPresetCatalog::keys());

    foreach (AvatarPresetCatalog::keys() as $key) {
        expect($labels[$key])->toBe(AvatarPresetCatalog::labelKey($key));
    }

    // La table close des clés d'`alt` est celle d'`AvatarRef`, ni plus ni
    // moins, et le repli est la clé des initiales.
    preg_match('/const AVATAR_ALT_KEYS = \[([^\]]+)\]/', $keys, $altTable);
    preg_match_all("/'([^']+)'/", $altTable[1] ?? '', $altKeys);
    preg_match("/const AVATAR_ALT_FALLBACK_KEY: TranslationKey = '([^']+)'/", $keys, $fallback);

    expect($altKeys[1])->toBe([AvatarRef::ALT_KEY_PRESET, AvatarRef::ALT_KEY_PROVIDER, AvatarRef::ALT_KEY_UPLOAD, AvatarRef::ALT_KEY_INITIALS])
        ->and($fallback[1] ?? null)->toBe(AvatarRef::ALT_KEY_INITIALS);
});
