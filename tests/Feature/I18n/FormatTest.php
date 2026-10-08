<?php

use App\Enums\Locale;
use App\Support\Format;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| `App\Support\Format` — spec 05 § Nombres, dates et durées, point 2
|--------------------------------------------------------------------------
|
| Mise en forme serveur des seuls textes qu'aucun client ne rend (e-mails,
| export de données), sans `ext-intl`. Séparateurs tirés du registre
| `App\Enums\Locale` ; jamais consommée par le back-office ni par un écran.
|
*/

it('sépare les milliers par une espace insécable et les décimales par une virgule en français', function () {
    expect(Format::number(1234567, 0, Locale::French))->toBe("1\u{00A0}234\u{00A0}567")
        ->and(Format::number(12345.678, 2, Locale::French))->toBe("12\u{00A0}345,68")
        ->and(Format::number(-1500, 0, Locale::French))->toBe("-1\u{00A0}500");
});

it('sépare les milliers par une virgule et les décimales par un point en anglais', function () {
    expect(Format::number(1234567, 0, Locale::English))->toBe('1,234,567')
        ->and(Format::number(12345.678, 2, Locale::English))->toBe('12,345.68');
});

it('n’écrit jamais un zéro négatif', function () {
    expect(Format::number(-0.04, 1, Locale::French))->toBe('0,0')
        ->and(Format::number(-0.04, 1, Locale::English))->toBe('0.0');
});

it('rend une durée en secondes depuis des millisecondes', function () {
    expect(Format::seconds(12_400, 1, Locale::French))->toBe("12,4\u{00A0}s")
        ->and(Format::seconds(1_234_000, 0, Locale::English))->toBe("1,234\u{00A0}s");
});

it('écrit une date dans la langue demandée', function () {
    $date = CarbonImmutable::parse('2026-02-01 09:30:00', 'UTC');

    expect(Format::date($date, Locale::French))->toBe('1 février 2026')
        ->and(Format::date($date, Locale::English))->toBe('February 1, 2026')
        ->and(Format::dateTime($date, Locale::French))->toBe('1 février 2026 à 09:30 UTC')
        ->and(Format::dateTime($date, Locale::English))->toBe('February 1, 2026, 09:30 UTC');
});

it('ne modifie jamais la locale d’une date mutable reçue', function () {
    $date = Carbon::parse('2026-10-07 14:05:00', 'UTC')->locale('en');

    Format::date($date, Locale::French);

    expect($date->locale)->toBe('en');
});

it('suit la locale de l’application quand aucune n’est donnée, comme un e-mail en file', function () {
    $date = CarbonImmutable::parse('2026-10-07', 'UTC');

    app()->setLocale('fr');
    expect(Format::number(2500.5, 1))->toBe("2\u{00A0}500,5")
        ->and(Format::date($date))->toBe('7 octobre 2026');

    app()->setLocale('en');
    expect(Format::number(2500.5, 1))->toBe('2,500.5')
        ->and(Format::date($date))->toBe('October 7, 2026');
});

it('se replie sur une locale activée quand celle de l’application ne l’est pas', function () {
    config()->set('app.fallback_locale', 'fr');
    app()->setLocale('de');

    expect(Format::number(1000))->toBe("1\u{00A0}000");

    config()->set('app.fallback_locale', 'de');
    expect(Format::number(1000))->toBe('1,000');
});

it('n’est jamais consommée par le back-office ni par une page', function () {
    $offenders = collect([
        ...File::allFiles(app_path('Http')),
        ...File::allFiles(app_path('Support/Admin')),
        ...File::allFiles(base_path('routes')),
    ])
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'App\\Support\\Format'))
        ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
