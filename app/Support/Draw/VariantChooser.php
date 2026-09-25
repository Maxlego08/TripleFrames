<?php

namespace App\Support\Draw;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Le choix d'une variante parmi celles d'un même (film, niveau) — au lancement
 * comme, au lot L30-6, en substitution (spec 30 § 7, contrat C3).
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
 * films exclut par construction du masque (§ 7.3).
 */
final readonly class VariantChooser
{
    /**
     * La variante retenue parmi `$candidates`, toutes d'un même niveau.
     *
     * @param  list<VariantCandidate>  $candidates  Variantes jouables d'un (film, niveau).
     * @param  CarbonImmutable|null  $memorySince  Borne basse de la mémoire du salon ; nulle sans salon.
     * @param  DrawContext  $context  `DrawContext::variant(s, i)` au lancement.
     * @return int|null `frame.id` retenu ; nul seulement pour une liste vide.
     *
     * @throws InvalidArgumentException Les candidates mêlent plusieurs niveaux :
     *                                  un palier ne change jamais de niveau (§ 8.1).
     */
    public function choose(array $candidates, ?CarbonImmutable $memorySince, SeededPrf $prf, DrawContext $context): ?int
    {
        if ($candidates === []) {
            return null;
        }

        $level = $candidates[0]->frameLevel;

        foreach ($candidates as $candidate) {
            if ($candidate->frameLevel !== $level) {
                throw new InvalidArgumentException(
                    'VariantChooser : les variantes candidates d’un palier sont toutes d’un même niveau.',
                );
            }
        }

        $group = self::tieGroup($candidates, $memorySince);

        usort($group, static fn (VariantCandidate $a, VariantCandidate $b): int => $a->frameId <=> $b->frameId);

        return $group[$prf->index($context, count($group))]->frameId;
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
}
