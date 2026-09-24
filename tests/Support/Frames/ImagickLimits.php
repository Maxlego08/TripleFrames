<?php

namespace Tests\Support\Frames;

use Imagick;

/**
 * Les limites de ressources d'Imagick valent pour TOUT le processus de test :
 * celles que la chaîne pose (`catalog.curation.imagick.*`), ou qu'un test
 * resserre pour provoquer un échec, atteindraient sinon les fixtures des tests
 * suivants.
 *
 * {@see self::capture()} relève les valeurs d'avant le premier traitement du
 * processus, {@see self::restore()} les repose. Une limite n'est jamais
 * « relâchée » à `PHP_INT_MAX` : c'est mesuré, un plafond de mémoire, de
 * projection et de surface tous trois à cette valeur rend la génération d'une
 * fixture cinquante fois plus lente. Seule la limite de temps l'est, comme le
 * fait la chaîne elle-même après chaque image.
 */
final class ImagickLimits
{
    /** Les types restaurés à leur valeur relevée. */
    private const array CAPTURED = ['MEMORY', 'MAP', 'AREA', 'WIDTH', 'HEIGHT'];

    /** @var array<int, int>|null */
    private static ?array $captured = null;

    public static function capture(): void
    {
        if (self::$captured !== null) {
            return;
        }

        self::$captured = [];

        foreach (self::CAPTURED as $type) {
            $resource = self::resource($type);

            if ($resource !== null) {
                self::$captured[$resource] = (int) Imagick::getResourceLimit($resource);
            }
        }
    }

    public static function restore(): void
    {
        foreach (self::$captured ?? [] as $resource => $limit) {
            Imagick::setResourceLimit($resource, $limit);
        }

        $time = self::resource('TIME');

        if ($time !== null) {
            Imagick::setResourceLimit($time, PHP_INT_MAX);
        }
    }

    private static function resource(string $type): ?int
    {
        $constant = Imagick::class.'::RESOURCETYPE_'.$type;

        if (! defined($constant)) {
            return null;
        }

        $value = constant($constant);

        return is_int($value) ? $value : null;
    }
}
