<?php

namespace App\Support\Frames;

use App\Http\Middleware\RobotsDirectives;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Constructeur UNIQUE des réponses d'image du disque `frames` — contrat C9,
 * aperçu admin C9-bis (spec 20 § 5.8), partagé avec `GET /f/{serveToken}` de
 * la spec 60 (R-31).
 *
 * Le disque `frames` a exactement trois lecteurs HTTP, la route de jeu,
 * l'aperçu du back-office (E10-61) et l'aperçu de l'image signalée
 * (`content-report.frame`, D63 du 07/10 amendée, préfixe `game/` seul), et
 * tous passent par ici : les
 * en-têtes ne se disent donc qu'à un seul endroit, et ceux de `/f/` et de
 * l'aperçu sont identiques par construction.
 *
 * **200** : le flux des octets, et exactement ces en-têtes — `Content-Type:
 * image/webp`, `Content-Length` (la taille du fichier, donc `game_bytes` pour
 * un dérivé), `Cache-Control: no-store, private`, `X-Robots-Tag: noindex,
 * nofollow`, `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy:
 * same-origin`.
 *
 * **Jamais** `Content-Disposition`, `Last-Modified`, `ETag`, `Accept-Ranges`
 * ni `Expires`, et c'est pourquoi la réponse n'est **jamais**
 * `Storage::response()` ni un `BinaryFileResponse` (spec 10 § 10, E10-59) :
 * le premier pose `Content-Disposition: inline; filename="<nom>"` et ferait
 * repartir le nom du fichier dans un en-tête ; le second rétablit
 * `Last-Modified` et `ETag`, donc une empreinte réutilisable, et avec elle
 * l'instant de curation qui regroupe les images d'un même film. Une
 * {@see StreamedResponse} nue ne pose que ce qu'on lui donne.
 *
 * **404, corps vide**, mêmes `Cache-Control` et `X-Robots-Tag`, si le chemin
 * n'appartient pas au préfixe demandé ({@see FrameStoragePrefix::owns()},
 * qui ferme d'un seul geste l'autre préfixe, la traversée de répertoire et le
 * chemin fabriqué) ou si le fichier manque. La réponse est la même quelle que
 * soit la cause : c'est ce qui rend `master/` non servable par la route de jeu
 * **par construction** — `make(FrameStoragePrefix::Game, …)` refuse tout chemin
 * `master/` avant d'ouvrir quoi que ce soit.
 *
 * **Fichier manquant** : avertissement sur le canal `game` (spec 100 § 10.9),
 * sans pseudo, sans IP et sans chemin — le préfixe seul, comme le reste de la
 * chaîne d'image : un chemin ne quitte jamais le serveur, pas même vers un
 * journal que l'on pourrait un jour expédier.
 *
 * **Aucun cookie** : cette classe n'en pose jamais. Sur l'aperçu, ceux de la
 * pile `web` s'ajoutent ; sur `/f/`, la pile n'en a pas (C8 § 2).
 */
final class FrameImageResponse
{
    /** Seul format des deux préfixes. */
    public const string CONTENT_TYPE = 'image/webp';

    /** Ni cache partagé, ni cache navigateur : le client précharge par `fetch` (C8). */
    public const string CACHE_CONTROL = 'no-store, private';

    /** L'en-tête le plus strict, identique pour `/f/` et l'aperçu (R-31). */
    public const string ROBOTS = RobotsDirectives::NOINDEX;

    /** Le type déclaré fait foi : aucun reniflage. */
    public const string CONTENT_TYPE_OPTIONS = 'nosniff';

    /** Aucune intégration par une autre origine. */
    public const string RESOURCE_POLICY = 'same-origin';

    /** Canal du fichier manquant (spec 100 § 10.9). */
    private const string LOG_CHANNEL = 'game';

    /**
     * Les octets de `$relativePath`, s'il appartient à `$prefix` et existe
     * sur le disque `frames` ; sinon la 404 uniforme.
     */
    public static function make(FrameStoragePrefix $prefix, string $relativePath): Response
    {
        if (! $prefix->owns($relativePath)) {
            return self::notFound();
        }

        $stream = self::open($relativePath);
        $stat = is_resource($stream) ? fstat($stream) : false;

        if (! is_resource($stream) || $stat === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            Log::channel(self::LOG_CHANNEL)->warning('Image absente du disque frames.', [
                'prefix' => $prefix->value,
            ]);

            return self::notFound();
        }

        return new StreamedResponse(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            Response::HTTP_OK,
            [
                'Content-Type' => self::CONTENT_TYPE,
                'Content-Length' => (string) $stat['size'],
                'Cache-Control' => self::CACHE_CONTROL,
                'X-Robots-Tag' => self::ROBOTS,
                'X-Content-Type-Options' => self::CONTENT_TYPE_OPTIONS,
                'Cross-Origin-Resource-Policy' => self::RESOURCE_POLICY,
            ],
        );
    }

    /**
     * La 404 uniforme : corps vide, mêmes `Cache-Control` et `X-Robots-Tag`
     * que la 200.
     *
     * Publique pour qu'un appelant qui refuse AVANT d'avoir un chemin — une
     * frame sans dérivé à l'aperçu, un prédicat faux sur `/f/` — rende
     * exactement la même réponse que le fichier manquant, et qu'aucune cause
     * ne se distingue d'une autre par ses en-têtes.
     */
    public static function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, [
            'Cache-Control' => self::CACHE_CONTROL,
            'X-Robots-Tag' => self::ROBOTS,
        ]);
    }

    /**
     * Le flux du fichier, ou `null` s'il manque.
     *
     * Le disque `frames` est configuré `throw => true`, et `Storage::fake()`
     * hérite de ce réglage : un fichier absent lève `UnableToReadFile` (une
     * `FilesystemException`), rattrapée ici. La branche `null` ne sert qu'à
     * un disque configuré sans `throw`. Les deux cas se ramènent au même
     * refus.
     *
     * @return resource|null
     */
    private static function open(string $relativePath)
    {
        try {
            $stream = Storage::disk(FrameStoragePrefix::DISK)->readStream($relativePath);
        } catch (FilesystemException) {
            return null;
        }

        return is_resource($stream) ? $stream : null;
    }
}
