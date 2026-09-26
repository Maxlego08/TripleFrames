<?php

namespace App\Support\Game;

use App\Http\Controllers\Game\FrameServeController;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\EngineConstants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * L'URL d'image d'un palier — spec 60 § 7.5, contrat C8 § 2 et § 3 (nom et
 * signature figés).
 *
 * `URL::temporarySignedRoute('frame.serve', $expiresAt, ['serveToken' =>
 * $tier->serve_token], absolute: false)` : signature **relative**, l'URL ne
 * dépend pas de `APP_URL`, seulement de `APP_KEY`. Produite par le serveur,
 * jamais reconstruite par Wayfinder, **jamais stockée**, et recalculée à
 * chaque émission avec
 *
 * `expiresAt = (round.reveal_ends_at ?? started_at + duration_ms +
 * tier_grace_ms + R × 1000) + serveUrlExpiryMarginMs`
 *
 * — `tier_grace_ms` relu sur la partie (colonne figée au lancement, jamais la
 * configuration), `R` sur son instantané de réglages, la marge dans
 * {@see EngineConstants}. L'expiration borne la RÉUTILISATION d'une URL ;
 * c'est {@see ServeGuard}, à chaque service, qui borne l'ACCÈS (C8 § 4.4) :
 * une URL transmise avant sa garde (`round.scheduled`, `tier.opened.next`)
 * est refusée jusqu'à `Tᵢ − preload_lead_ms` (résidu nommé, § 18).
 *
 * Le seul identifiant d'image qui quitte le serveur est le `serve_token`,
 * lié à une MANCHE et non à une frame : aucun chemin, aucun `frame.id`,
 * aucun niveau n'entre dans l'URL (règle 3, 10 § 7.10).
 *
 * **Livrée par L60-5**, avant le reste du service d'image (L60-8) : la
 * programmation d'une manche émet `round.scheduled`, dont la charge porte
 * l'URL du palier 1 (§ 11.3) ; la route nommée `frame.serve` est posée avec
 * elle, et {@see FrameServeController} ne sert ses octets qu'à travers
 * {@see ServeGuard} (L60-8).
 */
final class ServeUrl
{
    /**
     * @throws LogicException Palier sans jeton frappé, ou manche sans origine de temps.
     */
    public static function for(RoundTier $tier): string
    {
        $token = $tier->serve_token
            ?? throw new LogicException(sprintf(
                'ServeUrl : le palier %d n’a pas de jeton frappé ; aucune URL ne se signe avant la frappe (spec 60 § 6.2).',
                $tier->tier_index,
            ));

        $round = $tier->round;

        return URL::temporarySignedRoute(
            'frame.serve',
            self::expiresAt($round, $round->game),
            ['serveToken' => $token],
            absolute: false,
        );
    }

    /**
     * L'expiration de toute URL d'image de la manche (§ 7.5) : la fin de sa
     * révélation — écrite, ou prévue à `D` si la manche n'est pas close —,
     * plus la marge d'expiration.
     *
     * @throws LogicException Manche sans origine de temps.
     */
    public static function expiresAt(Round $round, Game $game): CarbonImmutable
    {
        $revealEndsAt = $round->reveal_ends_at ?? self::startedAt($round)->addMilliseconds(
            $round->duration_ms + $game->tier_grace_ms + $game->settings_snapshot->revealDuration * 1000,
        );

        return $revealEndsAt->addMilliseconds(EngineConstants::serveUrlExpiryMarginMs());
    }

    /**
     * @throws LogicException
     */
    private static function startedAt(Round $round): CarbonImmutable
    {
        return $round->started_at ?? throw new LogicException(sprintf(
            'ServeUrl : la manche %d n’est pas programmée ; aucune URL d’image sans origine de temps.',
            $round->sequence_index,
        ));
    }
}
