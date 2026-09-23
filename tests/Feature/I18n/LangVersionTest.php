<?php

use App\Enums\Locale;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\I18n\LangVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/*
|--------------------------------------------------------------------------
| L'empreinte de `lang/`, seul lien entre un déploiement et un texte à jour
|--------------------------------------------------------------------------
|
| `HandleInertiaRequests::version()` est le SEUL mécanisme entre un
| déploiement qui ne touche qu'une traduction et un joueur qui garde l'ancien
| texte : l'empreinte des assets Vite ne bouge pas, Inertia ne force aucun
| rechargement. Elle n'était adossée à aucune assertion.
|
| Les deux propriétés vérifiées sont de sens opposé, et c'est exprès : elle
| doit changer quand `lang/` change, et ne JAMAIS changer quand seule la
| locale change — sinon un changement de langue provoque un rechargement
| complet de page, en pleine manche.
|
*/

beforeEach(function (): void {
    // Le fichier de `lang:hash` est un artefact de déploiement qui peut
    // traîner sur le poste : tant qu'il est là, l'empreinte est figée et ces
    // tests ne vérifieraient plus rien. Il est mis de côté et rendu à la fin.
    $this->langVersionCachePath = app(LangVersion::class)->cachePath();
    $this->langVersionCacheBackup = is_file($this->langVersionCachePath)
        ? (string) file_get_contents($this->langVersionCachePath)
        : null;

    if ($this->langVersionCacheBackup !== null) {
        unlink($this->langVersionCachePath);
    }
});

afterEach(function (): void {
    if ($this->langVersionCacheBackup !== null) {
        file_put_contents($this->langVersionCachePath, $this->langVersionCacheBackup);
    } elseif (is_file($this->langVersionCachePath)) {
        unlink($this->langVersionCachePath);
    }
});

/**
 * L'empreinte Inertia complète, telle qu'un client la reçoit, sous la locale
 * demandée. Une instance neuve à chaque appel : `LangVersion` mémoïse, et une
 * instance partagée masquerait précisément ce qu'on vérifie.
 */
function langVersionOf(Locale $locale): string
{
    App::setLocale($locale->value);
    App::forgetInstance(LangVersion::class);

    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

    expect($version)->toBeString();

    return (string) $version;
}

it('keeps the asset version identical across locales', function () {
    expect(langVersionOf(Locale::English))->toBe(langVersionOf(Locale::French));
});

it('changes the asset version when a language file changes', function () {
    $before = langVersionOf(Locale::French);

    $path = lang_path('fr/lang-version-probe.php');

    file_put_contents($path, '<?php return [];'.PHP_EOL);

    try {
        expect(langVersionOf(Locale::French))->not->toBe($before);
    } finally {
        unlink($path);
    }

    // Le fichier retiré, l'empreinte revient à sa valeur : elle ne dépend que
    // du contenu, jamais de l'ordre de parcours du système de fichiers.
    expect(langVersionOf(Locale::French))->toBe($before);
});

it('prefers the file written by lang:hash over a live scan', function () {
    expect(app(LangVersion::class)->cached())->toBeNull();

    $this->artisan('lang:hash')->assertSuccessful();

    App::forgetInstance(LangVersion::class);

    $written = app(LangVersion::class);

    expect($written->cached())->toBe($written->compute());
});
