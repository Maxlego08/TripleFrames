<?php

namespace App\Http\Controllers\Ops;

use App\Enums\OpsProbe;
use App\Http\Controllers\Controller;
use App\Support\Ops\Heartbeat;
use App\Support\Ops\IntegrityProbe;
use App\Support\Ops\LoadProbe;
use App\Support\Ops\ProbeResponse;
use App\Support\Ops\PurgeProbe;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Les sondes d'exploitation interrogées de l'extérieur — spec 100 § 15.
 *
 * `GET /ops/probe/{probe}`, route `ops.probe`, chargée hors du groupe `web`
 * (`bootstrap/app.php`, callback `then`) : sans session, sans cookie, sans
 * jeton CSRF, invisible du balayage des routes joueur. Pile
 * `throttle:ops-probe` puis `EnsureProbeToken`.
 *
 * Réponse : `200 {"status":"ok"}` ou `503 {"status":"stale"}`, **aucune autre
 * donnée**. Le motif d'une alerte part au journal de l'application, pour
 * l'administrateur qui la lit ; jamais dans la réponse, que lit un tiers.
 * Une sonde inconnue rend la même 404 qu'un jeton absent ou faux.
 */
final class ProbeController extends Controller
{
    public function show(
        string $probe,
        IntegrityProbe $integrity,
        PurgeProbe $purge,
        LoadProbe $load,
    ): JsonResponse|Response {
        $known = OpsProbe::tryFrom($probe);

        if ($known === null) {
            return ProbeResponse::notFound();
        }

        $now = CarbonImmutable::now();

        $failures = match ($known) {
            OpsProbe::WorkerGame => Heartbeat::failures(
                Heartbeat::GAME,
                Config::integer('ops.heartbeat.game_stale_seconds'),
                $now,
            ),
            OpsProbe::WorkerDefault => Heartbeat::failures(
                Heartbeat::DEFAULT,
                Config::integer('ops.heartbeat.default_stale_seconds'),
                $now,
            ),
            OpsProbe::Load => $load->failures(),
            OpsProbe::Integrity => $integrity->failures($now),
            OpsProbe::Purge => $purge->failures($now),
        };

        if ($failures === []) {
            return ProbeResponse::ok();
        }

        Log::warning('Sonde d’exploitation en alerte.', [
            'probe' => $known->value,
            'failures' => $failures,
        ]);

        return ProbeResponse::stale();
    }
}
