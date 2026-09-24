<?php

use App\Settings\PlatformLimits;
use App\Support\Frames\CropViolation;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Tests\Support\Frames\CropCases;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| Géométrie de la frame servable — contrat C9 (spec 20 § 5.1, D5 du 23/09)
|--------------------------------------------------------------------------
|
| Une seule source de format, `FrameGeometry`, déclinée en quatre lieux : ses
| constantes, les plafonds de `FrameStoragePrefix`, le miroir client
| `resources/js/lib/frame-geometry.ts` et le jeton `--aspect-frame`. Ce
| fichier prouve qu'ils disent la même chose, et que la liste client des
| causes de refus comme le jeu de cas partagé couvrent tout `CropViolation`.
|
| L'assertion sur la prop partagée `frameFormat` est ajoutée au dernier test
| par L90-6a, qui crée la prop (spec 90 § 9.1, dépendance inversée).
|
*/

/**
 * Valeurs numériques littérales du CODE d'un fichier PHP, commentaires exclus.
 *
 * @return list<int>
 */
function frameGeometryIntegerLiterals(string $path): array
{
    $literals = [];

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && $token[0] === T_LNUMBER) {
            $literals[] = (int) str_replace('_', '', $token[1]);
        }
    }

    return $literals;
}

it('le ratio de jeu est 16:9 et le dérivé mesure 1280 × 720', function (): void {
    expect(FrameGeometry::ASPECT_WIDTH)->toBe(16);
    expect(FrameGeometry::ASPECT_HEIGHT)->toBe(9);
    expect(FrameGeometry::GAME_WIDTH)->toBe(1280);
    expect(FrameGeometry::GAME_HEIGHT)->toBe(720);
    expect(FrameGeometry::MASTER_WIDTH)->toBe(1920);

    // Le dérivé et le master sont au ratio de jeu, et leurs largeurs tombent
    // sur le pas du cadre.
    expect(FrameGeometry::GAME_WIDTH * FrameGeometry::ASPECT_HEIGHT)
        ->toBe(FrameGeometry::GAME_HEIGHT * FrameGeometry::ASPECT_WIDTH);
    expect(FrameGeometry::GAME_WIDTH % FrameGeometry::ASPECT_WIDTH)->toBe(0);
    expect(FrameGeometry::MASTER_WIDTH % FrameGeometry::ASPECT_WIDTH)->toBe(0);

    // Une source 16:9 donne un master 16:9, quelle que soit sa définition.
    $masterHeight = intdiv(FrameGeometry::MASTER_WIDTH * FrameGeometry::ASPECT_HEIGHT, FrameGeometry::ASPECT_WIDTH);

    expect(FrameGeometry::masterHeightFor(FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT))->toBe($masterHeight);
    expect(FrameGeometry::masterHeightFor(FrameGeometry::MASTER_WIDTH, $masterHeight))->toBe($masterHeight);

    // Le pas de largeur que garde `PlatformLimits` est celui du ratio : un
    // multiple de 16 garde une hauteur entière.
    expect(PlatformLimits::FRAME_CROP_WIDTH_MULTIPLE_PX)->toBe(FrameGeometry::ASPECT_WIDTH);
});

it('le plafond d\'encodage est le plus grand multiple de 8 192 sous 153 600', function (): void {
    expect(FrameGeometry::GAME_MAX_BYTES)->toBe(153_600);
    expect(FrameGeometry::GAME_PAD_BYTES)->toBe(8_192);

    $ceiling = FrameGeometry::gameEncodeCeilingBytes();

    expect($ceiling)->toBe(147_456);
    expect($ceiling % FrameGeometry::GAME_PAD_BYTES)->toBe(0);
    expect($ceiling)->toBeLessThanOrEqual(FrameGeometry::GAME_MAX_BYTES);
    expect($ceiling + FrameGeometry::GAME_PAD_BYTES)->toBeGreaterThan(FrameGeometry::GAME_MAX_BYTES);

    // Longueur paddée : multiple supérieur ou égal, jamais au-delà du besoin.
    expect(FrameGeometry::paddedLength(0))->toBe(0);
    expect(FrameGeometry::paddedLength(1))->toBe(FrameGeometry::GAME_PAD_BYTES);
    expect(FrameGeometry::paddedLength(FrameGeometry::GAME_PAD_BYTES))->toBe(FrameGeometry::GAME_PAD_BYTES);
    expect(FrameGeometry::paddedLength(FrameGeometry::GAME_PAD_BYTES + 1))->toBe(2 * FrameGeometry::GAME_PAD_BYTES);
    expect(FrameGeometry::paddedLength($ceiling - 1))->toBe($ceiling);
    expect(FrameGeometry::paddedLength($ceiling))->toBe($ceiling);

    // Tout encodage sous le plafond reste sous 150 Ko une fois paddé ; un
    // octet de plus le ferait déborder.
    expect(FrameGeometry::paddedLength($ceiling + 1))->toBeGreaterThan(FrameGeometry::GAME_MAX_BYTES);

    expect(fn (): int => FrameGeometry::paddedLength(-1))->toThrow(InvalidArgumentException::class);
});

it('masterHeightFor est entier et déterministe', function (): void {
    foreach (CropCases::masterHeights() as $label => $case) {
        $first = FrameGeometry::masterHeightFor($case['width'], $case['height']);

        expect($first)->toBe($case['expected'], $label);
        expect(FrameGeometry::masterHeightFor($case['width'], $case['height']))->toBe($first, $label);
    }

    // Arrondi au plus proche, demi vers le haut, en arithmétique entière :
    // identique à l'arrondi flottant sur toute une grille de sources en
    // paysage, et jamais plus haut que large.
    $mismatches = [];

    for ($width = FrameGeometry::GAME_WIDTH; $width <= 2 * FrameGeometry::MASTER_WIDTH; $width += 37) {
        for ($height = 1; $height <= $width; $height += 29) {
            $masterHeight = FrameGeometry::masterHeightFor($width, $height);

            if ($masterHeight !== (int) round($height * FrameGeometry::MASTER_WIDTH / $width)
                || $masterHeight > FrameGeometry::MASTER_WIDTH) {
                $mismatches[] = "{$width} × {$height} → {$masterHeight}";
            }
        }
    }

    expect($mismatches)->toBe([]);

    expect(fn (): int => FrameGeometry::masterHeightFor(0, FrameGeometry::GAME_HEIGHT))->toThrow(InvalidArgumentException::class);
    expect(fn (): int => FrameGeometry::masterHeightFor(FrameGeometry::GAME_WIDTH, 0))->toThrow(InvalidArgumentException::class);
});

it('FrameStoragePrefix délègue ses plafonds à FrameGeometry', function (): void {
    expect(FrameStoragePrefix::Game->maxPixels())->toBe(FrameGeometry::GAME_WIDTH);
    expect(FrameStoragePrefix::Master->maxPixels())->toBe(FrameGeometry::MASTER_WIDTH);
    expect(FrameStoragePrefix::Game->maxKilobytes() * 1024)->toBe(FrameGeometry::GAME_MAX_BYTES);
    expect(FrameStoragePrefix::Master->maxKilobytes())->toBeNull();

    // Délégation, pas coïncidence : aucune constante de format n'est réécrite
    // en littéral dans le code de `FrameStoragePrefix`.
    $literals = frameGeometryIntegerLiterals(app_path('Support/Frames/FrameStoragePrefix.php'));

    foreach ([
        FrameGeometry::GAME_WIDTH,
        FrameGeometry::GAME_HEIGHT,
        FrameGeometry::MASTER_WIDTH,
        FrameGeometry::GAME_MAX_BYTES,
        intdiv(FrameGeometry::GAME_MAX_BYTES, 1024),
    ] as $value) {
        expect($literals)->not->toContain($value);
    }
});

it('frame-geometry.ts, le jeton --aspect-frame et la prop frameFormat reflètent FrameGeometry', function (): void {
    // Miroir client : l'objet `FRAME_GEOMETRY`, clé pour clé, rien de plus.
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path('js/lib/frame-geometry.ts')));

    expect(preg_match('/export\s+const\s+FRAME_GEOMETRY\s*=\s*\{(?<body>[^}]*)\}\s*as\s+const\s*;/', $source, $match))->toBe(1);

    preg_match_all('/(?<key>\w+)\s*:\s*(?<value>\d+)\s*,?/', $match['body'], $pairs, PREG_SET_ORDER);

    $mirror = [];

    foreach ($pairs as $pair) {
        $mirror[$pair['key']] = (int) $pair['value'];
    }

    expect($mirror)->toBe([
        'aspectWidth' => FrameGeometry::ASPECT_WIDTH,
        'aspectHeight' => FrameGeometry::ASPECT_HEIGHT,
        'gameWidth' => FrameGeometry::GAME_WIDTH,
        'gameHeight' => FrameGeometry::GAME_HEIGHT,
        'masterWidth' => FrameGeometry::MASTER_WIDTH,
    ]);

    // Causes de refus : `CROP_VIOLATIONS` reprend `CropViolation::cases()`,
    // valeur pour valeur et dans l'ordre d'évaluation. Un cas ajouté côté PHP
    // sans son miroir échoue ici.
    $causes = array_map(static fn (CropViolation $violation): string => $violation->value, CropViolation::cases());

    expect(preg_match('/export\s+const\s+CROP_VIOLATIONS\s*=\s*\[(?<body>[^\]]*)\]\s*as\s+const\s*;/', $source, $list))->toBe(1);
    preg_match_all("/'(?<value>[a-z_]+)'/", $list['body'], $values);
    expect($values['value'])->toBe($causes);

    // Et le jeu partagé exerce chacune d'elles : sans cela, Vitest comparerait
    // le miroir sur un sous-ensemble et laisserait passer un cadre que le
    // serveur refuse pour une cause que le jeu ignore.
    $exercised = [];

    foreach (CropCases::RULES as $rule) {
        foreach (CropCases::violations($rule) as $case) {
            if ($case['expected'] !== null) {
                $exercised[$case['expected']->value] = true;
            }
        }
    }

    expect(array_keys($exercised))->toEqualCanonicalizing($causes);

    // Jeton de thème : une seule déclaration, dans le bloc `@theme`.
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect(preg_match_all('/--aspect-frame\s*:/', $css))->toBe(1);
    expect(preg_match('/@theme\b[^{]*\{(?<body>[^}]*)\}/', $css, $theme))->toBe(1);
    expect(preg_match('/--aspect-frame\s*:\s*(?<width>\d+)\s*\/\s*(?<height>\d+)\s*;/', $theme['body'], $token))->toBe(1);
    expect((int) $token['width'])->toBe(FrameGeometry::ASPECT_WIDTH);
    expect((int) $token['height'])->toBe(FrameGeometry::ASPECT_HEIGHT);
});
