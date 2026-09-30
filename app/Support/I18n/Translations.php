<?php

namespace App\Support\I18n;

use App\Enums\Locale;
use Illuminate\Contracts\Translation\Translator;

/**
 * Aplatit les dictionnaires `lang/{locale}/{domaine}.php` en clés pointées,
 * seule forme que consomme le `t()` maison du front.
 *
 * Source de vérité unique : `lang/`. Le front ne possède pas son propre
 * dictionnaire et n'importe aucun fichier de langue ; il lit la prop
 * `translations`.
 *
 * Les dictionnaires `lang/*.json` ne sont **jamais** expédiés : ce sont des
 * clés littérales émises par le framework et Fortify, résolues côté serveur et
 * transmises déjà rendues (message de validation, flash), alors que `t()`
 * n'accepte que des clés `domaine.chemin.clé`.
 */
final class Translations
{
    /**
     * Paramètre de requête du **préchargement** décrit par la spec 05 : le
     * sélecteur de langue demande le dictionnaire de la locale visée par une
     * visite partielle `only: ['translations']` avant même de persister le
     * choix, pour que la bascule soit immédiate au clic.
     *
     * Il ne pilote que cette prop. Il n'appelle jamais `App::setLocale()` et
     * n'entre donc pas dans l'ordre de résolution à cinq niveaux : un
     * paramètre d'URL n'est pas une source de locale.
     */
    public const string PREVIEW_PARAM = 'translations_locale';

    public function __construct(private readonly Translator $translator) {}

    /**
     * Dictionnaire aplati des domaines demandés, dans la locale demandée.
     *
     * Le repli d'instance est chargé **sous** la locale active, exactement
     * comme `__()` le ferait côté serveur : un joueur ne voit jamais une clé
     * brute à l'écran. Ce n'est pas un filet contre l'oubli de traduction —
     * la symétrie des clés vérifiée en CI l'est.
     *
     * @param  list<string>  $domains
     * @return array<string, string>
     */
    public function flatten(Locale $locale, array $domains): array
    {
        $fallback = self::fallback();
        $messages = [];

        foreach ($domains as $domain) {
            if ($fallback !== $locale) {
                $messages = array_merge($messages, $this->lines($fallback, $domain));
            }

            $messages = array_merge($messages, $this->lines($locale, $domain));
        }

        ksort($messages);

        return $messages;
    }

    /**
     * Repli d'instance, validé par l'enum : `APP_FALLBACK_LOCALE` est une
     * variable d'environnement, donc une entrée non fiable.
     *
     * Statique parce que c'est une lecture de configuration sans état, dont
     * `SetLocale` (niveau 5 de la résolution) et `HandleInertiaRequests` ont
     * besoin autant que le dictionnaire : une seule définition du repli.
     */
    public static function fallback(): Locale
    {
        $configured = config('app.fallback_locale');

        return (is_string($configured) ? Locale::tryFrom($configured) : null) ?? Locale::English;
    }

    /**
     * @return array<string, string>
     */
    private function lines(Locale $locale, string $domain): array
    {
        $lines = $this->translator->get($domain, [], $locale->value);

        return is_array($lines) ? $this->dot($domain, $lines) : [];
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    private function dot(string $prefix, array $lines): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat = array_merge($flat, $this->dot($path, $value));
            } elseif (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
