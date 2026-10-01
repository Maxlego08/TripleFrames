<?php

namespace App\Support\Draw;

use App\Enums\FrameLevel;
use App\Models\Frame;
use App\Models\Round;
use App\Models\RoundTier;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;

/**
 * Le choix d'une variante parmi celles d'un palier d'un film, niveaux de sa
 * plage mêlés (D45 du 01/10) — au lancement
 * ({@see self::choose()}) comme en substitution ({@see self::substitute()})
 * (spec 30 § 7 et § 8.1, contrat C3).
 *
 * Ordre de repli complet (`00` § Le jeu en une manche, `CLAUDE.md` § 2) :
 * **non vue par le salon → vue la moins récemment par le salon → départage par
 * la graine.**
 *
 * 1. Une variante est **non vue** si `lastSeenAt` est nul **ou antérieur à
 *    `memorySince`** (E10-55) : une ligne `seen_frame` encore présente parce que
 *    la purge du jour n'a pas tourné ne change pas le tirage — **le tirage ne
 *    dépend pas de l'heure de la purge**. Une variante vue à `memorySince` pile
 *    est dans la fenêtre, donc vue (même borne incluse que la non-répétition,
 *    `started_at >= memorySince`).
 * 2. S'il existe au moins une variante non vue, le groupe d'égalité est
 *    l'ensemble des non vues ; sinon, l'ensemble des variantes de `lastSeenAt`
 *    minimal.
 * 3. Le groupe est trié par `frameId`, puis on rend
 *    `groupe[prf.index(context, |groupe|)]` : l'ordre d'entrée ne compte pas.
 *
 * **Aucun axe joueur** : tous les joueurs voient la même image, et `seen_frame`
 * ne porte aucune colonne de joueur (spec 10 § 7.9). En solo, aucune mémoire :
 * `memorySince` et tous les `lastSeenAt` sont nuls, le départage se fait par la
 * graine seule (§ 9).
 *
 * **Le choix n'est jamais bloqué** : une variante déjà vue vaut mieux qu'une
 * manche annulée. `null` ne sort que d'une liste vide, ce que le tirage des
 * films exclut par construction du masque (§ 7.3) ; en substitution, `null`
 * signifie qu’aucune variante de la plage ne reste, et la spec 60 annule la
 * manche (`no_variant_available`).
 */
final readonly class VariantChooser
{
    /**
     * La variante retenue parmi `$candidates`, qui peuvent mêler les niveaux
     * d'une plage (D45 du 01/10) : la préférence « non vue » porte sur toute la
     * plage, le niveau montré en découle.
     *
     * @param  list<VariantCandidate>  $candidates  Variantes jouables d'un palier d'un film.
     * @param  CarbonImmutable|null  $memorySince  Borne basse de la mémoire du salon ; nulle sans salon.
     * @param  DrawContext  $context  `DrawContext::variant(s, i)` au lancement,
     *                                `DrawContext::substitute(s, i)` en substitution.
     * @return int|null `frame.id` retenu ; nul seulement pour une liste vide.
     */
    public function choose(array $candidates, ?CarbonImmutable $memorySince, SeededPrf $prf, DrawContext $context): ?int
    {
        if ($candidates === []) {
            return null;
        }

        $group = self::tieGroup($candidates, $memorySince);

        usort($group, static fn (VariantCandidate $a, VariantCandidate $b): int => $a->frameId <=> $b->frameId);

        return $group[$prf->index($context, count($group))]->frameId;
    }

    /**
     * La variante de substitution d'un palier matérialisé (§ 8.1) : la règle de
     * choix appartient à cette spec ; la détection, l'instant, l'écriture de
     * `served_frame_id` / `substitution_reason` et l'annulation appartiennent à
     * la spec 60, qui l'appelle à la frappe du `serve_token` du palier.
     *
     * - **Candidates** : les variantes du film `round.movie_id`, **aux niveaux
     *   de la plage du palier** (plus son niveau tiré), strictement entre les
     *   niveaux de ses voisins (D45 du 01/10), qui satisfont le prédicat unique de
     *   variante jouable ({@see Frame::servable()}) **et dont le fichier est
     *   présent sur le disque `frames`, sous le préfixe `game/`** (C8, C9),
     *   moins `tier.frame_id` et moins `$excludedFrameIds`. La présence sur
     *   disque est testée ici, **avant le choix**, sur les seules candidates de
     *   ce niveau : une variante au fichier manquant n'est jamais proposée.
     * - **Mémoire** : `seen_frame` du salon `game.room_id`, fenêtre
     *   `RoomMemoryWindow::since(salon, $now)` ; **aucune mémoire en solo**, ni
     *   `seen_frame` ni `round` n'y sont lus.
     * - **Choix** : la règle du § 7.1 ({@see self::choose()}), contexte
     *   `DrawContext::substitute(round.sequence_index, tier.tier_index)`.
     *
     * **Jamais hors de la plage ni hors de l'ordre** : le palier matérialisé
     * porte une durée, une valeur en points et une place dans l'échelle de
     * cryptivité ; servir un niveau 5 au palier 1 donnerait la réponse au
     * palier le mieux payé.
     *
     * Aucune écriture, aucun verrou, aucun aléa hors de {@see SeededPrf} : à
     * entrées identiques (manche, palier, exclusions, instant, catalogue,
     * mémoire et disque), la même variante.
     *
     * @param  list<int>  $excludedFrameIds  Candidates déjà écartées par l'appelant
     *                                       (fichier disparu entre le choix et la frappe).
     * @return int|null `frame.id` d'un niveau permis, ou `null` : aucune variante ne
     *                  reste, la spec 60 annule la manche (`no_variant_available`).
     *
     * @throws InvalidArgumentException Le palier n'appartient pas à la manche.
     */
    public function substitute(Round $round, RoundTier $tier, array $excludedFrameIds, CarbonImmutable $now): ?int
    {
        if ($tier->round_id !== $round->id) {
            throw new InvalidArgumentException('VariantChooser::substitute : le palier n’appartient pas à la manche.');
        }

        $game = $round->game;
        $roomId = $game->room_id;

        if ($tier->frame_id !== null) {
            $excludedFrameIds[] = $tier->frame_id;
        }

        $candidates = self::substituteCandidates(
            $round->movie_id,
            self::substituteLevels($game->frames_per_round, $tier),
            $excludedFrameIds,
            $roomId,
        );

        if ($candidates === []) {
            return null;
        }

        return $this->choose(
            $candidates,
            $roomId === null ? null : RoomMemoryWindow::since($roomId, $now),
            SeededPrf::forGame($game),
            DrawContext::substitute($round->sequence_index, $tier->tier_index),
        );
    }

    /**
     * Le groupe d'égalité : les non vues s'il en existe, sinon les vues le moins
     * récemment. Jamais vide pour une entrée non vide.
     *
     * @param  non-empty-list<VariantCandidate>  $candidates
     * @return non-empty-list<VariantCandidate>
     */
    private static function tieGroup(array $candidates, ?CarbonImmutable $memorySince): array
    {
        $unseen = [];
        $oldest = null;

        foreach ($candidates as $candidate) {
            $seenAt = $candidate->lastSeenAt;

            if ($seenAt === null || ($memorySince !== null && $seenAt->lessThan($memorySince))) {
                $unseen[] = $candidate;

                continue;
            }

            if ($oldest === null || $seenAt->lessThan($oldest)) {
                $oldest = $seenAt;
            }
        }

        if ($unseen !== []) {
            return $unseen;
        }

        $leastRecent = array_values(array_filter(
            $candidates,
            static fn (VariantCandidate $candidate): bool => $oldest !== null
                && $candidate->lastSeenAt !== null
                && $candidate->lastSeenAt->equalTo($oldest),
        ));

        // Aucune non vue : chaque candidate a un `lastSeenAt`, et la plus
        // ancienne est dans le groupe par construction.
        return $leastRecent === [] ? $candidates : $leastRecent;
    }

    /**
     * Les niveaux permis à la substitution d'un palier (§ 8.1, D45 du 01/10) :
     * ceux de sa plage `FrameLevelCoverage::bands(N)[i − 1]`, plus son niveau
     * tiré (un palier tiré en repli de niveau garde son niveau), strictement
     * entre le niveau du palier précédent et celui du palier suivant. Un
     * voisin déjà substitué compte pour le niveau de l'image réellement
     * servie.
     *
     * @return list<int>
     */
    private static function substituteLevels(int $framesPerRound, RoundTier $tier): array
    {
        $levels = array_map(
            static fn (FrameLevel $level): int => $level->value,
            FrameLevelCoverage::bands($framesPerRound)[$tier->tier_index - 1],
        );
        $levels[] = $tier->frame_level->value;

        $neighbours = RoundTier::query()
            ->where('round_id', $tier->round_id)
            ->whereIn('tier_index', [$tier->tier_index - 1, $tier->tier_index + 1])
            ->get(['tier_index', 'frame_id', 'frame_level', 'served_frame_id']);

        $servedLevels = Frame::query()
            ->whereIn('id', $neighbours
                ->filter(static fn (RoundTier $neighbour): bool => $neighbour->served_frame_id !== null
                    && $neighbour->served_frame_id !== $neighbour->frame_id)
                ->pluck('served_frame_id')
                ->all())
            ->pluck('frame_level', 'id');

        $floor = 0;
        $ceiling = PHP_INT_MAX;

        foreach ($neighbours as $neighbour) {
            $served = $neighbour->served_frame_id === null ? null : $servedLevels->get($neighbour->served_frame_id);
            $level = $served instanceof FrameLevel ? $served->value : $neighbour->frame_level->value;

            if ($neighbour->tier_index < $tier->tier_index) {
                $floor = $level;
            } else {
                $ceiling = $level;
            }
        }

        return array_values(array_unique(array_filter(
            $levels,
            static fn (int $level): bool => $level > $floor && $level < $ceiling,
        )));
    }

    /**
     * Les candidates de substitution, en une requête puis au plus un `exists()`
     * par ligne : variantes jouables du film aux niveaux permis du palier,
     * moins les exclues, triées par `id`, fichier présent sous `game/`. Avec un
     * salon, jointure gauche sur `seen_frame` du salon
     * (`seen_frame_room_frame_uq`) pour `lastSeenAt` ; sans salon, aucune
     * jointure : aucune mémoire n'est lue, pas même un attribut absent.
     *
     * @param  list<int>  $levels
     * @param  list<int>  $excludedFrameIds
     * @return list<VariantCandidate>
     */
    private static function substituteCandidates(int $movieId, array $levels, array $excludedFrameIds, ?int $roomId): array
    {
        if ($levels === []) {
            return [];
        }

        $query = Frame::query()
            ->select(['frame.id', 'frame.frame_level', 'frame.game_path'])
            ->servable()
            ->where('frame.movie_id', $movieId)
            ->whereIn('frame.frame_level', $levels)
            ->orderBy('frame.id');

        if ($excludedFrameIds !== []) {
            $query->whereNotIn('frame.id', $excludedFrameIds);
        }

        if ($roomId !== null) {
            $query->leftJoin('seen_frame', static function (JoinClause $seen) use ($roomId): void {
                $seen->on('seen_frame.frame_id', '=', 'frame.id')
                    ->where('seen_frame.room_id', '=', $roomId);
            })
                ->addSelect('seen_frame.last_seen_at')
                ->withCasts(['last_seen_at' => 'datetime']);
        }

        $candidates = [];

        foreach ($query->get() as $frame) {
            // Même présence que la retenue de la variante tirée à la frappe
            // (`MintTierServeToken`, spec 60 § 6.2 ; E72-2) : préfixe `game/`
            // et fichier sur le disque `frames`.
            if (! $frame->hasGameFile()) {
                continue;
            }

            $lastSeenAt = $roomId === null ? null : $frame->getAttribute('last_seen_at');

            $candidates[] = new VariantCandidate(
                frameId: $frame->id,
                frameLevel: $frame->frame_level,
                lastSeenAt: $lastSeenAt instanceof CarbonImmutable ? $lastSeenAt : null,
            );
        }

        return $candidates;
    }
}
