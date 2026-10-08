<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Models\Frame;
use App\Support\ContentReport\FrameViewEligibility;
use App\Support\Frames\FrameImageResponse;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'aperçu de l'image signalée — route `content-report.frame`,
 * `GET /report/frame/{publicId}` (D63 du 07/10, amendé le 07/10 ; spec 90
 * § 4.5 bis) : le second chemin de service du dérivé de jeu, après
 * `GET /f/{serveToken}`, et aucun troisième préfixe.
 *
 * À chaque requête, le prédicat unique {@see FrameViewEligibility} : le
 * demandeur — compte connecté ou `player_token` courant, jamais frappé —
 * a participé à une manche révélée où cette frame a été servie ; ni la frame
 * ni son film ne sont retirés ou suspendus. Sinon
 * {@see FrameImageResponse::notFound()}, la 404 uniforme, quelle que soit la
 * cause (inconnue, inéligible, retirée, fichiers effacés). Les octets passent
 * par `FrameImageResponse::make(FrameStoragePrefix::Game, …)` : mêmes
 * en-têtes que `/f/` (`no-store, private`, `noindex`, `nosniff`), et
 * `master/` refusé par construction.
 *
 * Lecture seule : aucune écriture, aucun `seen_frame`, aucun jeton frappé.
 */
final class ContentReportFrameController extends Controller
{
    public function __construct(
        private readonly FrameViewEligibility $eligibility,
    ) {}

    public function show(Request $request, string $publicId): Response
    {
        $frame = Frame::query()
            ->with('movie')
            ->where('public_id', $publicId)
            ->first();

        if (! $frame instanceof Frame
            || ! $this->eligibility->allows($frame, $request)
            || $frame->game_path === null) {
            return FrameImageResponse::notFound();
        }

        return FrameImageResponse::make(FrameStoragePrefix::Game, $frame->game_path);
    }
}
