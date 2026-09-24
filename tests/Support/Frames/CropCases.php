<?php

namespace Tests\Support\Frames;

use App\Support\Frames\CropRect;
use App\Support\Frames\CropViolation;
use RuntimeException;

/**
 * Lecture du jeu de cas partagé `tests/Fixtures/Frames/crop-cases.json`
 * (contrat C9, spec 20 § 5.1 et § 5.2).
 *
 * Le même fichier est lu par Vitest (`tests/Frontend/admin/frame-geometry.test.ts`)
 * contre le miroir client : chaque verdict, écrit à la main, est donc vérifié
 * des deux côtés, et c'est ce qui prouve que le miroir refuse les mêmes cadres
 * que `FrameGeometry`.
 *
 * La lecture est STRICTE : une règle inconnue, une clé manquante ou un verdict
 * hors de `CropViolation` lève. Chaque cas de `violations` appartient à une
 * règle de {@see self::RULES}, que consomme un test nommé de
 * `CropRectangleTest` : aucun cas n'est vérifié par Vitest seul.
 */
final class CropCases
{
    /** Chemin du jeu, relatif à la racine du dépôt. */
    public const string FIXTURE = 'tests/Fixtures/Frames/crop-cases.json';

    /** Règles de `violations`, liste fermée : une par test nommé de `CropRectangleTest`. */
    public const array RULES = ['aspect', 'width_cap', 'height_cap', 'min_width', 'bounds'];

    /**
     * Les cas d'une règle, étiquetés.
     *
     * @return array<string, array{masterHeight: int, limits: array{frameCropMaxWidthPercent: int, frameCropMinWidthPx: int}, crop: CropRect, expected: CropViolation|null}>
     */
    public static function violations(string $rule): array
    {
        if (! in_array($rule, self::RULES, true)) {
            throw new RuntimeException(sprintf('Règle de cadre inconnue : [%s].', $rule));
        }

        $cases = [];

        foreach (self::list(self::fixture(), 'violations') as $case) {
            $caseRule = self::string($case, 'rule');

            if (! in_array($caseRule, self::RULES, true)) {
                throw new RuntimeException(sprintf('Cas de cadre sous une règle inconnue : [%s].', $caseRule));
            }

            if ($caseRule !== $rule) {
                continue;
            }

            $expected = $case['expected'] ?? null;

            $cases[self::label($case, $cases)] = [
                'masterHeight' => self::integer($case, 'masterHeight'),
                'limits' => self::limits($case),
                'crop' => CropRect::fromArray(self::map($case, 'crop')),
                'expected' => $expected === null ? null : CropViolation::from(self::string($case, 'expected')),
            ];
        }

        if ($cases === []) {
            throw new RuntimeException(sprintf('Aucun cas de cadre pour la règle [%s].', $rule));
        }

        return $cases;
    }

    /**
     * Les cadres par défaut attendus, étiquetés ; `expected` nul quand aucun
     * cadre n'est admis sur le master.
     *
     * @return array<string, array{masterHeight: int, limits: array{frameCropMaxWidthPercent: int, frameCropMinWidthPx: int}, expected: CropRect|null}>
     */
    public static function defaults(): array
    {
        $cases = [];

        foreach (self::list(self::fixture(), 'defaults') as $case) {
            $expected = $case['expected'] ?? null;

            $cases[self::label($case, $cases)] = [
                'masterHeight' => self::integer($case, 'masterHeight'),
                'limits' => self::limits($case),
                'expected' => $expected === null ? null : CropRect::fromArray(self::map($case, 'expected')),
            ];
        }

        return $cases;
    }

    /**
     * Les hauteurs de master attendues, étiquetées.
     *
     * @return array<string, array{width: int, height: int, expected: int}>
     */
    public static function masterHeights(): array
    {
        $cases = [];

        foreach (self::list(self::fixture(), 'masterHeights') as $case) {
            $source = self::map($case, 'source');

            $cases[self::label($case, $cases)] = [
                'width' => self::integer($source, 'width'),
                'height' => self::integer($source, 'height'),
                'expected' => self::integer($case, 'expected'),
            ];
        }

        return $cases;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function fixture(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/'.self::FIXTURE),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($decoded)) {
            throw new RuntimeException('Jeu de cadres illisible.');
        }

        return $decoded;
    }

    /**
     * Étiquette d'un cas, unique dans sa section : un doublon écraserait un cas
     * en silence.
     *
     * @param  array<array-key, mixed>  $case
     * @param  array<string, mixed>  $seen
     */
    private static function label(array $case, array $seen): string
    {
        $label = self::string($case, 'label');

        if (array_key_exists($label, $seen)) {
            throw new RuntimeException(sprintf('Jeu de cadres : étiquette en double [%s].', $label));
        }

        return $label;
    }

    /**
     * @param  array<array-key, mixed>  $case
     * @return array{frameCropMaxWidthPercent: int, frameCropMinWidthPx: int}
     */
    private static function limits(array $case): array
    {
        $limits = self::map($case, 'limits');

        return [
            'frameCropMaxWidthPercent' => self::integer($limits, 'frameCropMaxWidthPercent'),
            'frameCropMinWidthPx' => self::integer($limits, 'frameCropMinWidthPx'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    private static function list(array $data, string $key): array
    {
        $list = $data[$key] ?? null;

        if (! is_array($list) || ! array_is_list($list)) {
            throw new RuntimeException(sprintf('Jeu de cadres : [%s] n\'est pas une liste.', $key));
        }

        return array_map(static function (mixed $item) use ($key): array {
            if (! is_array($item)) {
                throw new RuntimeException(sprintf('Jeu de cadres : un élément de [%s] n\'est pas un objet.', $key));
            }

            return $item;
        }, $list);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new RuntimeException(sprintf('Jeu de cadres : [%s] n\'est pas un objet.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Jeu de cadres : [%s] n\'est pas une chaîne.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new RuntimeException(sprintf('Jeu de cadres : [%s] n\'est pas un entier.', $key));
        }

        return $value;
    }
}
