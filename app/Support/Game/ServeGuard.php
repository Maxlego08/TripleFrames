<?php

namespace App\Support\Game;

use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Http\Controllers\Game\FrameServeController;
use App\Models\Frame;
use App\Models\GamePlayer;
use App\Models\RoundTier;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Identity\PlayerTokenManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le prédicat de service d'une image de palier — spec 60 § 7.2, contrat C8
 * § 2 et § 4.4, spec 10 § 4.1 (noms et signatures figés).
 *
 * **Seul prédicat** : appelé par la route `frame.serve`
 * ({@see FrameServeController}) à CHAQUE service, et par `GameStateBuilder`
 * pour choisir les URL d'un paquet de resynchronisation (L60-12). Trois
 * parties, publiques pour les tests, toutes trois exigées ensemble :
 *
 * 1. **catalogue** ({@see self::catalogueAllows()}) — la variante SERVIE
 *    (`served_frame_id`, jamais la variante tirée) existe, est servable
 *    ({@see Frame::isServable()}) et son dérivé appartient au préfixe `game/`
 *    ({@see FrameStoragePrefix::owns()}) ;
 * 2. **temps** ({@see self::timeAllows()}) — selon l'état de la manche :
 *    - `pending` ou `running` : partie `running`, manche programmée
 *      (`started_at` non nul) et fenêtre de préchargement franchie
 *      (`now ≥ Tᵢ − game.preload_lead_ms`, {@see RoundTier::isOpenForServing()}),
 *      palier 1 de la manche 1 compris, sans aucune exception ; et, si la
 *      manche est close (`ended_at` non nul, grâce finale d'une fin anticipée),
 *      `Tᵢ < ended_at` : un palier que la fin anticipée a empêché de s'ouvrir
 *      n'est jamais servi (écart (f) du § 22 bis). **Aucune borne haute** :
 *      un palier passé reste servable, pour qu'un client lent finisse de
 *      charger l'image en cours ; c'est `GameStateBuilder`, pas le prédicat,
 *      qui borne le nombre d'URL d'un paquet (§ 12.4) ;
 *    - `revealing` : palier réellement OUVERT (`served_at` non nul) et
 *      `now < reveal_ends_at` (D14 du 23/09) ;
 *    - `completed`, `cancelled` : refus ;
 * 3. **appartenance** ({@see self::membershipAllows()}) — le hash du
 *    `player_token` courant désigne un siège de la PARTIE (`game_player`,
 *    jamais le seul salon), ni expulsé (`player.kicked_at` nul,
 *    `game_player.status <> kicked`), ni retardataire en attente
 *    (`first_round_number` NULL ou `≤ round.round_number`).
 *
 * `preload_lead_ms` est relu sur la PARTIE (colonne figée au lancement),
 * jamais en configuration ni dans `PlatformLimits` : la fenêtre de service
 * d'une partie en cours ne change pas avec un déploiement (§ 2.3).
 *
 * **Lecture seule, sans effet** : aucune écriture, aucune transition, aucun
 * rattrapage (10 § 7.4). Le palier doit porter sa manche et la partie de
 * celle-ci (la route les charge d'avance avec la frame servie) ; seule la
 * partie (3) interroge la base, une lecture d'existence sur les index
 * existants (E10-66).
 *
 * Le demandeur est désigné par `PlayerTokenManager::current($request)?->hash()`
 * ({@see PlayerTokenManager}, contrat C4) : jamais par une IP, une session ou
 * un identifiant fourni par le client.
 */
final readonly class ServeGuard
{
    /**
     * Les trois parties, ensemble : catalogue, temps, appartenance.
     *
     * @param  string|null  $requesterTokenHash  Hash du `player_token` courant ; `null` sans jeton.
     * @param  CarbonImmutable  $now  Instant serveur du service (réception de la requête).
     */
    public function allows(RoundTier $tier, ?string $requesterTokenHash, CarbonImmutable $now): bool
    {
        return $this->catalogueAllows($tier)
            && $this->timeAllows($tier, $now)
            && $this->membershipAllows($tier, $requesterTokenHash);
    }

    /**
     * (1) La variante servie existe, est servable, et son dérivé appartient
     * au préfixe `game/` — évalué à chaque service : une frame devenue non
     * servable après la frappe est refusée dès la requête suivante.
     */
    public function catalogueAllows(RoundTier $tier): bool
    {
        $servedFrame = $tier->servedFrame;

        return $servedFrame instanceof Frame
            && $servedFrame->isServable()
            && $servedFrame->game_path !== null
            && FrameStoragePrefix::Game->owns($servedFrame->game_path);
    }

    /**
     * (2) La garde temporelle, selon l'état de la manche.
     */
    public function timeAllows(RoundTier $tier, CarbonImmutable $now): bool
    {
        $round = $tier->round;

        return match ($round->status) {
            RoundStatus::Pending, RoundStatus::Running => $this->openingAllows($tier, $now),
            RoundStatus::Revealing => $tier->served_at !== null
                && $round->reveal_ends_at !== null
                && $now->lessThan($round->reveal_ends_at),
            RoundStatus::Completed, RoundStatus::Cancelled => false,
        };
    }

    /**
     * (3) Le demandeur tient un siège de la partie, admis à cette manche.
     *
     * Une seule lecture d'existence : `game_player` de la partie, joint au
     * `player` qui porte ce hash. Une manche de réserve encore sans numéro
     * n'admet que les sièges du lancement (`first_round_number` NULL) : une
     * comparaison à NULL n'est jamais vraie.
     */
    public function membershipAllows(RoundTier $tier, ?string $requesterTokenHash): bool
    {
        if ($requesterTokenHash === null) {
            return false;
        }

        $round = $tier->round;
        $roundNumber = $round->round_number;

        return GamePlayer::query()
            ->where('game_id', $round->game_id)
            ->where('status', '<>', GamePlayerStatus::Kicked->value)
            ->where(static function (Builder $query) use ($roundNumber): void {
                $query->whereNull('first_round_number');

                if ($roundNumber !== null) {
                    $query->orWhere('first_round_number', '<=', $roundNumber);
                }
            })
            ->whereHas('player', static function (Builder $query) use ($requesterTokenHash): void {
                $query->where('player_token_hash', $requesterTokenHash)
                    ->whereNull('kicked_at');
            })
            ->exists();
    }

    /**
     * Manche programmée ou en cours : partie en cours, fenêtre de
     * préchargement franchie sur la colonne de la partie, et palier ouvert
     * avant la clôture si la manche est déjà close.
     */
    private function openingAllows(RoundTier $tier, CarbonImmutable $now): bool
    {
        $round = $tier->round;
        $game = $round->game;

        if ($game->status !== GameStatus::Running || $round->started_at === null) {
            return false;
        }

        if (! $tier->isOpenForServing($now, $game->preload_lead_ms)) {
            return false;
        }

        if ($round->ended_at === null) {
            return true;
        }

        $opensAt = $tier->absoluteStartsAt();

        return $opensAt instanceof CarbonImmutable && $opensAt->lessThan($round->ended_at);
    }
}
