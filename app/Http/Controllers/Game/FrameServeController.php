<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Models\Frame;
use App\Models\RoundTier;
use App\Support\Frames\FrameImageResponse;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Game\ReceptionInstant;
use App\Support\Game\ServeGuard;
use App\Support\Game\ServeUrl;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le service d'image d'un palier — route `frame.serve`, `GET /f/{serveToken}`
 * (spec 60 § 7.3 et § 7.4, contrat C8 § 2 à § 4 : nom, chemin et signature
 * figés). L'URL est produite par le serveur ({@see ServeUrl}), jamais
 * reconstruite par le client.
 *
 * Jeton → `round_tier` par `round_tier_serve_token_uq`, chargé avec sa manche,
 * la partie de celle-ci et la frame SERVIE ; puis le prédicat en trois parties
 * ({@see ServeGuard::allows()} : catalogue, temps, appartenance du demandeur),
 * évalué à l'instant serveur de réception ({@see ReceptionInstant}), pour le
 * siège que désigne le `player_token` courant ({@see PlayerTokenManager::current()},
 * qui ne frappe ni ne repose jamais le cookie).
 *
 * - Jeton inconnu ou prédicat faux : {@see FrameImageResponse::notFound()},
 *   la 404 uniforme que `make()` rend lui-même pour un chemin hors préfixe ou
 *   un fichier absent — même statut, même corps vide, mêmes en-têtes, quelle
 *   que soit la cause. Le refus précède tout chemin : aucun `make()` sur un
 *   chemin fabriqué pour obtenir un refus (E40-1).
 * - Sinon : `FrameImageResponse::make(FrameStoragePrefix::Game, game_path)`,
 *   constructeur unique des réponses d'image (contrat C9) ; le préfixe de
 *   jeu est le seul que cette route nomme.
 *
 * **Aucune écriture** : ni transition, ni rattrapage, ni `served_at`, ni
 * `seen_frame` (10 § 7.4). Une requête d'image anticipée ne date rien et
 * n'ouvre rien ; environ cinq lectures par clé, aucun index nouveau (E10-66).
 *
 * Pile (`routes/game.php`) : `signed:relative` (403 sur une signature
 * invalide ou expirée), `throttle:frame-serve` (429), sans session, sans
 * jeton CSRF, sans file de cookies — donc sans aucun `Set-Cookie` ;
 * `EncryptCookies` reste, pour lire le `player_token` de la partie (3).
 */
final class FrameServeController extends Controller
{
    public function __construct(
        private readonly ServeGuard $guard,
        private readonly PlayerTokenManager $tokens,
    ) {}

    public function show(Request $request, string $serveToken): Response
    {
        $tier = RoundTier::query()
            ->with(['round.game', 'servedFrame'])
            ->where('serve_token', $serveToken)
            ->first();

        if (! $tier instanceof RoundTier
            || ! $this->guard->allows($tier, $this->tokens->current($request)?->hash(), ReceptionInstant::of($request))) {
            return FrameImageResponse::notFound();
        }

        // Assuré par la partie (1) du prédicat ; redit pour le typage.
        $servedFrame = $tier->servedFrame;

        if (! $servedFrame instanceof Frame || $servedFrame->game_path === null) {
            return FrameImageResponse::notFound();
        }

        return FrameImageResponse::make(FrameStoragePrefix::Game, $servedFrame->game_path);
    }
}
