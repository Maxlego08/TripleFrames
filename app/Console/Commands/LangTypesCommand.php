<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Support\I18n\TranslationDomains;
use App\Support\I18n\Translations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Génère `resources/js/types/translations.d.ts`.
 *
 * Le front est sous `lint.typeAware` + `denyWarnings: true` : un dictionnaire
 * `Record<string, any>` casse la CI, et une clé mal orthographiée passée à
 * `t()` ne se verrait qu'à l'écran. Le typage vient donc de `lang/`, jamais
 * l'inverse.
 *
 * Fichier **committé, jamais édité à la main**, au même titre que les fichiers
 * Wayfinder : la CI relance la commande avec `--check` et échoue si le
 * résultat diffère du fichier versionné.
 *
 * Portée : les sept domaines normatifs, et eux seuls. Les fichiers du
 * framework (`auth`, `pagination`, `passwords`, `validation`) et les
 * dictionnaires `lang/*.json` n'y figurent pas — ils ne sont jamais expédiés
 * au client, leurs messages arrivant déjà résolus.
 */
class LangTypesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lang:types {--check : Échoue si le fichier généré diffère du fichier versionné}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Génère resources/js/types/translations.d.ts depuis les dictionnaires lang/';

    /** Chemin du fichier généré, relatif à la racine du dépôt. */
    public const string TARGET = 'resources/js/types/translations.d.ts';

    public function handle(Translations $translations): int
    {
        $path = base_path(self::TARGET);
        $generated = $this->render($translations);

        if ($this->option('check')) {
            $current = File::exists($path) ? File::get($path) : null;

            if ($current === $generated) {
                $this->components->info('Types de traduction à jour.');

                return self::SUCCESS;
            }

            $this->components->error(
                self::TARGET.' est périmé : relancez `php artisan lang:types` et versionnez le résultat.',
            );

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $generated);

        $this->components->info(self::TARGET.' généré.');

        return self::SUCCESS;
    }

    /**
     * Locale de référence d'un domaine : `en` partout, sauf pour un domaine
     * qui n'existe qu'en français — `admin`, français par construction. Sans
     * cette exception, les écrans d'administration n'auraient aucune clé
     * typée, alors qu'ils sont tenus de tout passer par des clés.
     */
    protected function reference(string $domain): ?Locale
    {
        foreach ([Locale::English, Locale::French] as $locale) {
            if (File::exists(lang_path($locale->value.'/'.$domain.'.php'))) {
                return $locale;
            }
        }

        return null;
    }

    protected function render(Translations $translations): string
    {
        $domains = [];
        $keys = [];

        foreach (TranslationDomains::KNOWN as $domain) {
            $locale = $this->reference($domain);

            if (! $locale instanceof Locale) {
                continue;
            }

            $domains[] = $domain;

            foreach (array_keys($translations->flatten($locale, [$domain])) as $key) {
                $keys[] = $key;
            }
        }

        sort($domains);
        sort($keys);

        return implode("\n", [
            '// Généré par `php artisan lang:types` — ne pas éditer à la main.',
            '// Source de vérité : les dictionnaires `lang/`, locale de',
            '// référence `en` (`fr` pour les domaines qui n’existent qu’en',
            '// français). Régénérer après toute clé ajoutée ou retirée.',
            '',
            $this->union('TranslationDomain', $domains),
            '',
            $this->union('TranslationKey', $keys),
            '',
            'export type TranslationKeyFor<D extends TranslationDomain> = Extract<',
            '    TranslationKey,',
            '    `${D}.${string}`',
            '>;',
            '',
            '/** Forme exacte de la prop Inertia `translations`. */',
            'export type TranslationMessages = Partial<Record<TranslationKey, string>>;',
            '',
        ]);
    }

    /**
     * Union de littéraux au format oxfmt : quatre espaces, guillemets simples,
     * une valeur par ligne — la liste des clés dépasse toujours 80 colonnes,
     * et une union courte réécrite sur une ligne ferait échouer `vp check`.
     *
     * @param  list<string>  $values
     */
    protected function union(string $name, array $values): string
    {
        if ($values === []) {
            return "export type {$name} = never;";
        }

        $lines = array_map(fn (string $value): string => "    | '".$value."'", $values);

        return "export type {$name} =\n".implode("\n", $lines).';';
    }
}
