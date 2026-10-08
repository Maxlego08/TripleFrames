<?php

namespace App\Support\Http;

use App\Http\Middleware\RobotsDirectives;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Balises d'en-tête de document rendues **côté serveur** par
 * `resources/views/app.blade.php` (spec 90 § 11.5, D66 du 07/10) : balise
 * canonique, description et Open Graph génériques.
 *
 * **Côté serveur, jamais par le `<Head>` d'Inertia** : il n'y a aucun SSR en
 * v1, et un robot comme l'aperçu d'une messagerie ne lit que le HTML initial.
 *
 * - **Canonique** (n° 48) : seulement là où {@see RobotsDirectives::routeIndexable()}
 *   est vrai — variable `SITE_INDEXABLE` levée, route porteuse de
 *   `RobotsDirectives::ROUTE_FLAG` (l'accueil et les trois pages légales,
 *   source unique de la liste), page légale non provisoire. Jamais sur une
 *   page `noindex`, donc jamais sur une URL portant un `room_code`. L'URL est
 *   bâtie sur `APP_URL` et l'URI déclarée de la route, **sans chaîne de
 *   requête** ni variante de chemin venue de la requête.
 * - **Open Graph et description** (n° 47) : les **mêmes** balises sur toutes
 *   les pages, dans la langue du visiteur (un robot sans `Accept-Language`
 *   reçoit le repli anglais, accepté par la spec 05). Clés fixes
 *   `common.meta.*`, sans paramètre : **aucune image**, jamais un `room_code`,
 *   un titre de film ni un pseudo (règle 3). `og:url` n'est posé que là où la
 *   canonique existe.
 */
final class PageMeta
{
    /**
     * URL canonique de la page, ou `null` quand la page n'en porte pas.
     */
    public function canonical(Request $request): ?string
    {
        if (! RobotsDirectives::routeIndexable($request)) {
            return null;
        }

        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        $uri = trim($route->uri(), '/');

        // Une route paramétrée n'a pas d'URL nue unique : aucune ne porte le
        // drapeau aujourd'hui (`IndexingTest`), et si l'une le portait un jour,
        // mieux vaut aucune canonique qu'une canonique fausse.
        if (str_contains($uri, '{')) {
            return null;
        }

        $base = rtrim($this->baseUrl(), '/');

        return $uri === '' ? $base.'/' : $base.'/'.$uri;
    }

    /**
     * Balises `<meta>` génériques, dans l'ordre d'émission : liste de paires
     * (attribut de nom, nom, contenu). `property` pour Open Graph, `name` pour
     * la description et Twitter.
     *
     * @return list<array{attribute: 'name'|'property', key: string, content: string}>
     */
    public function tags(?string $canonical): array
    {
        $title = $this->text('common.meta.title');
        $description = $this->text('common.meta.description');
        $siteName = config('app.name');

        $tags = [
            ['attribute' => 'name', 'key' => 'description', 'content' => $description],
            ['attribute' => 'property', 'key' => 'og:site_name', 'content' => is_string($siteName) ? $siteName : 'TripleFrames'],
            ['attribute' => 'property', 'key' => 'og:type', 'content' => 'website'],
            ['attribute' => 'property', 'key' => 'og:title', 'content' => $title],
            ['attribute' => 'property', 'key' => 'og:description', 'content' => $description],
        ];

        if ($canonical !== null) {
            $tags[] = ['attribute' => 'property', 'key' => 'og:url', 'content' => $canonical];
        }

        $tags[] = ['attribute' => 'name', 'key' => 'twitter:card', 'content' => 'summary'];

        return $tags;
    }

    /**
     * Traduction dans la locale courante, posée par `SetLocale`.
     */
    private function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }

    /**
     * `APP_URL`, jamais l'hôte de la requête : un en-tête `Host` forgé ne doit
     * pas pouvoir écrire la canonique.
     */
    private function baseUrl(): string
    {
        $url = config('app.url');

        return is_string($url) ? $url : '';
    }
}
