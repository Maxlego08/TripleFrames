<?php

namespace App\Actions\Game;

use App\Events\Game\SeatSuperseded;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Player;
use App\Support\Game\CurrentGame;
use App\Support\Game\GameStateBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Le second onglet prend la main — spec 60 § 12.7, contrat C7 § 2.5 et
 * § 4.9 (nom et signature figés).
 *
 * **Un `player_token` = un siège ; le second onglet prend la main, le premier
 * passe en lecture seule.** Appelée au rendu de toute page de salon (50, 90)
 * et de `game/solo`, **avant** {@see GameStateBuilder::build()}, qui reçoit
 * le jeton rendu comme jeton présenté — l'onglet qui vient de prendre la main
 * lit donc `self.seatActive = true` dans le paquet de la même réponse, alors
 * qu'un chargement complet n'envoie jamais `X-Seat-Token` :
 *
 * ```php
 * $seatToken = $claim->handle($seat, EnsureActiveSeat::presentedToken($request));
 * $state = GameStateBuilder::build($game, $seat, $now, $seatToken);
 * // props : state, seatToken — jamais le jeton dans `state`
 * ```
 *
 * - La requête présente le jeton actif (`X-Seat-Token`) : rien n'est frappé,
 *   le jeton présenté est rendu inchangé — c'est ce qui empêche un
 *   rechargement partiel (changement de langue, « Rejouer ») de supplanter
 *   son propre onglet.
 * - Sinon : un nouveau `active_seat_token` est frappé — un **ULID
 *   applicatif, jamais l'identifiant de session** (10 § 7.1 : `sessions`
 *   porte l'adresse IP et l'agent, une jointure par session fabriquerait la
 *   fuite que le principe 12 nie) —, écrit sous le verrou de la ligne
 *   `player`, et, si un jeton précédent existait, `seat.superseded` part sur
 *   le canal privé du siège, **en multijoueur seulement** (§ 11.2 : en solo,
 *   l'onglet supplanté l'apprend par `seatActive: false` à son sondage
 *   suivant, ou par le 409 de sa prochaine écriture). L'événement part après
 *   validation de la transaction (`ShouldDispatchAfterCommit`).
 *
 * L'onglet supplanté n'écrit plus : `seat.active` lui répond 409
 * ({@see EnsureActiveSeat}). Aucun verrou de salon n'est pris : seule la
 * ligne `player` est écrite (ordre global room → player → game → round).
 */
final class ClaimSeatTab
{
    /**
     * @return string le jeton d'onglet actif du siège après l'appel — prop
     *                `seatToken` de la page, jamais dans `state`
     */
    public function handle(Player $seat, ?string $presentedSeatToken): string
    {
        if (EnsureActiveSeat::holdsTab($seat, $presentedSeatToken) && $presentedSeatToken !== null) {
            return $presentedSeatToken;
        }

        $token = DB::transaction(function () use ($seat, $presentedSeatToken): string {
            $locked = Player::query()->whereKey($seat->getKey())->lockForUpdate()->firstOrFail();

            // Relu sous le verrou : un autre onglet a pu prendre la main entre
            // la lecture du siège et ce verrou.
            if (EnsureActiveSeat::holdsTab($locked, $presentedSeatToken) && $presentedSeatToken !== null) {
                return $presentedSeatToken;
            }

            $previous = $locked->active_seat_token;
            $minted = (string) Str::ulid();

            $locked->active_seat_token = $minted;
            $locked->save();

            // Garde de mode (§ 11.2) : un siège solo n'a pas de canal.
            if ($previous !== null && $locked->room_id !== null) {
                SeatSuperseded::dispatch($locked, CurrentGame::of($locked), []);
            }

            return $minted;
        });

        // L'instance de l'appelant reflète la ligne : le paquet construit
        // ensuite sur ce siège lit le jeton actif rendu ici.
        $seat->setAttribute('active_seat_token', $token);
        $seat->syncOriginalAttribute('active_seat_token');

        return $token;
    }
}
