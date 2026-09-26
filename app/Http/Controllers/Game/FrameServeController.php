<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Support\Frames\FrameImageResponse;
use App\Support\Game\ServeUrl;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le service d'image d'un palier — route `frame.serve`, `GET /f/{serveToken}`
 * (spec 60 § 7.3, contrat C8 § 2 : nom, chemin et signature figés).
 *
 * **Posé par L60-5, fermé** : la programmation d'une manche émet déjà l'URL
 * signée du palier 1 ({@see ServeUrl}), qui exige la route nommée ; tant que
 * le prédicat de service `ServeGuard` n'est pas livré (lot L60-8), la route
 * refuse TOUT par la 404 uniforme de {@see FrameImageResponse::notFound()} —
 * jamais un octet sans les trois parties du prédicat (catalogue, temps,
 * appartenance), jamais une réponse qui distinguerait un jeton connu d'un
 * jeton inconnu.
 *
 * L60-8 y branche : jeton → `round_tier` par `round_tier_serve_token_uq`,
 * avec `round.game` et `servedFrame` ; `ServeGuard::allows()` faux → la même
 * 404 ; sinon `FrameImageResponse::make(FrameStoragePrefix::Game,
 * $servedFrame->game_path)`. **Aucune écriture**, ni transition, ni
 * rattrapage, ni `seen_frame` (10 § 7.4).
 *
 * Pile : `signed:relative`, `throttle:frame-serve`, sans session ni
 * `Set-Cookie` (`routes/game.php`) ; `EncryptCookies` reste, pour lire le
 * `player_token` de la partie (3) du prédicat.
 */
final class FrameServeController extends Controller
{
    public function show(Request $request, string $serveToken): Response
    {
        return FrameImageResponse::notFound();
    }
}
