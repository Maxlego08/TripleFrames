<?php

namespace App\Support\I18n;

use Illuminate\Support\Facades\File;

/**
 * Empreinte des fichiers `lang/`, injectée dans
 * `HandleInertiaRequests::version()`.
 *
 * Sans elle, un déploiement qui ne touche qu'une traduction ne change pas
 * l'empreinte des assets Vite : Inertia ne force aucun rechargement et le
 * joueur garde l'ancien texte jusqu'au vidage de son cache.
 *
 * **L'empreinte ne dépend jamais de la locale active** : elle couvre tous les
 * fichiers de toutes les locales. Une empreinte par locale ferait changer
 * `version()` au changement de langue, donc un rechargement complet de page —
 * inacceptable en pleine manche.
 */
final class LangVersion
{
    /**
     * Chemin du fichier écrit par `php artisan lang:hash`, à côté de
     * `config:cache` dans le script de déploiement. Absent en développement :
     * l'empreinte est alors calculée à la volée.
     */
    public const string CACHE_PATH = 'cache/lang-version.php';

    private ?string $fingerprint = null;

    /**
     * Mémoïsée : `version()` est interrogée plusieurs fois par réponse, et
     * l'empreinte de développement balaye `lang/` à chaque calcul.
     */
    public function fingerprint(): string
    {
        return $this->fingerprint ??= $this->cached() ?? $this->compute();
    }

    public function cached(): ?string
    {
        $path = $this->cachePath();

        if (! is_file($path)) {
            return null;
        }

        /** @var mixed $cached */
        $cached = require $path;

        return is_string($cached) && $cached !== '' ? $cached : null;
    }

    public function compute(): string
    {
        $hash = hash_init('xxh128');

        foreach ($this->files() as $relative => $absolute) {
            hash_update($hash, $relative);
            hash_update_file($hash, $absolute);
        }

        return hash_final($hash);
    }

    public function cachePath(): string
    {
        return base_path('bootstrap/'.self::CACHE_PATH);
    }

    /**
     * Fichiers de langue, chemin relatif (trié, séparateurs normalisés) vers
     * chemin absolu. Le tri rend l'empreinte indépendante de l'ordre de
     * parcours du système de fichiers — sans quoi elle changerait d'une
     * machine à l'autre sans qu'aucune traduction ne bouge.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $root = lang_path();

        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        foreach (File::allFiles($root) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $files[$relative] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }
}
