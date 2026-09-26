<?php

namespace App\Actions\Game;

use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundTier;
use App\Support\Draw\VariantChooser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La frappe du jeton d'image d'un palier — spec 60 § 6.1 et § 6.2, contrat
 * C8 § 2 et § 4.1-4.2 (nom et signature figés).
 *
 * **Seule écrivaine** de `round_tier.serve_token`, `served_frame_id` et
 * `substitution_reason` (C8 § 2, E10-47 ; prouvé par `TierServingWritersTest`) :
 * chacune s'écrit **une seule fois**, et aucune colonne de temps ni de points
 * du palier n'est touchée.
 *
 * **Instant** : le jeton du palier 1 est frappé par `ScheduleRound`, dans la
 * transaction qui écrit `started_at` ; celui du palier `i ≥ 2` par
 * `OpenTier(i−1)` (L60-6). La frappe **un cran à l'avance** est ce qui rend
 * le préchargement possible sans qu'une substitution ne change jamais les
 * octets derrière une URL déjà transmise.
 *
 * **Idempotente** : un jeton déjà frappé n'est jamais régénéré ni revu — ni
 * le jeton, ni la variante servie, ni le motif, même si la frame est devenue
 * non servable depuis (c'est `OpenTier` qui revérifie, § 6.3). Le jeton vaut
 * `bin2hex(random_bytes(16))`, UNIQUE (`round_tier_serve_token_uq`), et il
 * est **lié à une manche, pas à une frame** : deux manches portant la même
 * frame reçoivent deux jetons (10 § 7.10, barrière 4 reformulée).
 *
 * **Substitution décidée à la frappe** (E10-25) :
 * 1. la variante tirée (`frame_id`) est retenue si {@see Frame::isServable()}
 *    est vrai **et** si son dérivé est présent ({@see Frame::hasGameFile()} :
 *    préfixe `game/` et fichier sur le disque `frames` — la même présence que
 *    les candidates de substitution, E72-2) ;
 * 2. sinon, {@see VariantChooser::substitute()} (contrat C3 : même
 *    `frame_level`, servable, fichier présent, PRF déterministe), avec
 *    `substitution_reason = frame_unavailable`, rappelée en excluant la
 *    candidate tant que son fichier manque — boucle bornée par le nombre de
 *    variantes du film à ce niveau ;
 * 3. sans candidate : `CancelRound(no_variant_available)`, **aucun jeton
 *    frappé** — la manche est annulée, remplacée ou la partie gelée
 *    (§ 15.2).
 *
 * **Verrous** : `game`, `round`, puis la ligne `round_tier` (enfant de la
 * manche), dans l'ordre global room → player → game → round → round_player
 * (§ 4.5). Ses appelants (`ScheduleRound`, `OpenTier`) tiennent déjà les deux
 * premiers ; les reprendre ici est sans effet dans leur transaction et garde
 * l'ordre si la frappe est appelée seule. Le palier est relu **`FOR
 * UPDATE`** : en REPEATABLE READ (InnoDB), la première lecture non
 * verrouillante d'une transaction fixe son instantané (30 § 6.5) — ici la
 * lecture de `game_id`, qui précède l'attente du verrou `game` —, si bien
 * qu'une relecture simple pourrait ne pas voir le jeton qu'une frappe
 * concurrente vient de valider, et en frapper un second. Seule une lecture
 * verrouillante voit toujours la dernière version validée.
 *
 * Aucune diffusion : l'URL du palier part avec l'événement de l'étape qui l'a
 * frappée (`round.scheduled`, `tier.opened.next`), jamais d'ici.
 */
final readonly class MintTierServeToken
{
    /** Octets d'aléa du jeton : 32 caractères hexadécimaux (`char(32)`, 10 § 7.4). */
    public const int TOKEN_BYTES = 16;

    /**
     * Colonnes que la frappe écrit, recopiées sur l'instance de l'appelant.
     *
     * @var list<string>
     */
    private const array MINTED_COLUMNS = ['serve_token', 'served_frame_id', 'substitution_reason', 'updated_at'];

    public function __construct(private VariantChooser $variants) {}

    /**
     * Frappe le jeton du palier, ou annule sa manche faute de variante.
     *
     * Au retour, l'instance reçue porte les colonnes frappées (par cet appel
     * ou un appel antérieur) ; si la manche a été annulée, elle est inchangée
     * et la manche, relue, est `cancelled`.
     *
     * @param  CarbonImmutable  $now  Instant de la transition appelante : fenêtre de mémoire du
     *                                salon pour la substitution, instant d'une annulation.
     *
     * @throws LogicException Manche qui n'est ni `pending` ni `running`.
     */
    public function handle(RoundTier $tier, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($tier, $now): void {
            $gameId = (int) Round::query()->whereKey($tier->round_id)->value('game_id');

            Game::query()->whereKey($gameId)->lockForUpdate()->firstOrFail();
            $round = Round::query()->whereKey($tier->round_id)->lockForUpdate()->firstOrFail();
            $locked = RoundTier::query()->whereKey($tier->id)->lockForUpdate()->firstOrFail();

            if ($locked->serve_token !== null) {
                self::reflect($tier, $locked);

                return;
            }

            if (! in_array($round->status, [RoundStatus::Pending, RoundStatus::Running], true)) {
                throw new LogicException(sprintf(
                    'MintTierServeToken : la manche %d est %s ; seul un palier d’une manche pending ou running se frappe.',
                    $round->sequence_index,
                    $round->status->value,
                ));
            }

            $retained = self::retainedFrameId($locked);
            $servedFrameId = $retained ?? $this->substitute($round, $locked, $now);

            if ($servedFrameId === null) {
                app(CancelRound::class)->handle($round, RoundIncidentReason::NoVariantAvailable, $now);

                return;
            }

            $locked->forceFill([
                'serve_token' => bin2hex(random_bytes(self::TOKEN_BYTES)),
                'served_frame_id' => $servedFrameId,
                'substitution_reason' => $retained === null ? RoundIncidentReason::FrameUnavailable : null,
            ])->save();

            self::reflect($tier, $locked);
        });
    }

    /**
     * La variante tirée, si elle est servable ET son dérivé présent.
     */
    private static function retainedFrameId(RoundTier $tier): ?int
    {
        if ($tier->frame_id === null) {
            return null;
        }

        $frame = Frame::query()->find($tier->frame_id);

        return $frame instanceof Frame && $frame->isServable() && $frame->hasGameFile() ? $frame->id : null;
    }

    /**
     * La variante de substitution du palier, même niveau, fichier présent —
     * ou `null` : aucune ne reste.
     *
     * `substitute()` écarte déjà toute candidate au fichier absent ; la
     * vérification est refaite sur la candidate rendue, parce qu'un fichier
     * peut disparaître entre son choix et la frappe (C3 § 3) : elle est alors
     * exclue et le choix rejoué. Chaque tour exclut une variante du film à ce
     * niveau : leur nombre borne la boucle.
     */
    private function substitute(Round $round, RoundTier $tier, CarbonImmutable $now): ?int
    {
        $bound = Frame::query()
            ->where('movie_id', $round->movie_id)
            ->where('frame_level', $tier->frame_level->value)
            ->count();

        $excluded = [];

        for ($attempt = 0; $attempt <= $bound; $attempt++) {
            $candidateId = $this->variants->substitute($round, $tier, $excluded, $now);

            if ($candidateId === null) {
                return null;
            }

            $candidate = Frame::query()->find($candidateId);

            if ($candidate instanceof Frame && $candidate->isServable() && $candidate->hasGameFile()) {
                return $candidateId;
            }

            $excluded[] = $candidateId;
        }

        return null;
    }

    /**
     * Recopie sur l'instance de l'appelant ce que porte le palier relu, sans
     * rien réécrire en base.
     */
    private static function reflect(RoundTier $tier, RoundTier $locked): void
    {
        $tier->forceFill($locked->only(self::MINTED_COLUMNS))
            ->syncOriginalAttributes(self::MINTED_COLUMNS);
    }
}
