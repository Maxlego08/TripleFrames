<?php

namespace App\Support\Visitor;

use App\Http\Middleware\RecordVisit;

/**
 * L'appareil d'un siège, **grossier** (D62 du 06/10) : classe d'appareil,
 * famille de navigateur, famille de système, lues dans le seul en-tête
 * `User-Agent`. Ni version, ni modèle, ni résolution : de quoi savoir sur
 * quel genre d'appareil on joue, jamais de quoi reconnaître un appareil.
 *
 * L'ordre des motifs compte : Edge, Opera et Samsung Internet se déclarent
 * aussi « Chrome », Chrome se déclare aussi « Safari ».
 */
final class UserAgentFamily
{
    /** @var array<string, string> motif → famille de navigateur */
    private const array BROWSERS = [
        '/edg(e|a|ios)?\//i' => 'edge',
        '/opr\/|opera/i' => 'opera',
        '/samsungbrowser/i' => 'samsung',
        '/firefox|fxios/i' => 'firefox',
        '/chrome|crios|chromium/i' => 'chrome',
        '/safari/i' => 'safari',
    ];

    /** @var array<string, string> motif → famille de système */
    private const array SYSTEMS = [
        '/iphone|ipad|ipod/i' => 'ios',
        '/android/i' => 'android',
        '/cros/i' => 'chromeos',
        '/windows/i' => 'windows',
        '/mac os x|macintosh/i' => 'macos',
        '/linux/i' => 'linux',
    ];

    public const string OTHER = 'other';

    /** `mobile`, `tablet` ou `desktop` — la règle de la mesure d'audience. */
    public static function device(string $userAgent): string
    {
        return RecordVisit::device($userAgent);
    }

    public static function browser(string $userAgent): string
    {
        return self::first(self::BROWSERS, $userAgent);
    }

    public static function os(string $userAgent): string
    {
        return self::first(self::SYSTEMS, $userAgent);
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function first(array $patterns, string $userAgent): string
    {
        foreach ($patterns as $pattern => $family) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $family;
            }
        }

        return self::OTHER;
    }
}
