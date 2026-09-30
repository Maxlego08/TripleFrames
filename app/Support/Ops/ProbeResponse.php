<?php

namespace App\Support\Ops;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Les trois seules réponses d'une sonde d'exploitation — spec 100 § 15.
 *
 * `200 {"status":"ok"}` ou `503 {"status":"stale"}`, **aucune autre donnée** :
 * ni compte, ni périmètre, ni âge. Le détail d'une alerte part au journal de
 * l'application, jamais dans la réponse, que lit un prestataire tiers.
 *
 * La troisième, {@see self::notFound()}, est rendue À L'IDENTIQUE pour un jeton
 * absent, un jeton faux, un jeton non configuré et une sonde inconnue :
 * l'existence des sondes ne se sonde pas. Un seul constructeur la fabrique,
 * pour que ces quatre cas ne puissent jamais diverger.
 *
 * Toutes portent `Cache-Control: no-store` : un relais qui garderait une copie
 * servirait un état périmé à la supervision. `X-Robots-Tag` est posé par
 * `RobotsDirectives`, middleware global, et vaut `noindex, nofollow` sur toute
 * route qui ne porte pas son drapeau — ce qu'aucune route `ops.` ne peut faire.
 */
final class ProbeResponse
{
    public const string STATUS_OK = 'ok';

    public const string STATUS_STALE = 'stale';

    public const string CACHE_CONTROL = 'no-store, private';

    public static function ok(): JsonResponse
    {
        return self::status(self::STATUS_OK, JsonResponse::HTTP_OK);
    }

    public static function stale(): JsonResponse
    {
        return self::status(self::STATUS_STALE, JsonResponse::HTTP_SERVICE_UNAVAILABLE);
    }

    /** Corps vide : rien ne distingue une sonde qui existe d'une sonde inconnue. */
    public static function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => self::CACHE_CONTROL]);
    }

    private static function status(string $status, int $code): JsonResponse
    {
        return new JsonResponse(['status' => $status], $code, ['Cache-Control' => self::CACHE_CONTROL]);
    }
}
