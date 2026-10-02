<?php

namespace App\Support\Draw;

use InvalidArgumentException;

/**
 * Registre **fermé** des contextes de dérivation de la graine (spec 30 § 5.3,
 * contrat C3).
 *
 * Un contexte nomme explicitement l'usage d'un flux de {@see SeededPrf} : le
 * même `game.draw_seed` sert un usage **secret** (le tirage des films et des
 * variantes) et un usage **révélé** (l'ordre du QCM, que chaque joueur
 * observe). Deux contextes distincts donnent deux flux indépendants ; c'est ce
 * qui empêche une sortie publique d'exposer le secret.
 *
 * **Aucune autre chaîne de contexte n'existe** : le constructeur est privé, et
 * les onze fabriques ci-dessous sont les seules entrées (les deux contextes
 * d'affinité des leurres ajoutés le 01/10, D44 du 01/10 ; les deux contextes
 * de dernier recours des leurres le 02/10, D53 du 02/10). Deux écarts aux
 * exemples de la spec 10 § 7.2, qui n'étaient que des exemples (E10-40,
 * E10-54) :
 *
 * - `{roundId}` devient `{sequenceIndex}` et `{playerId}` devient
 *   `{playerPublicId}` : `round.sequence_index` est unique par partie et connu
 *   **avant** l'insertion des lignes, ce qui fait du tirage une fonction pure,
 *   rejouable sans identifiant attribué par la base ; `player.public_id` est
 *   l'identité de siège stable. Jamais un identifiant de base.
 * - **`tiebreak:{roundId}` est retiré** (contradiction n° 66) : il n'avait aucun
 *   usage assigné et se lisait comme un départage de score. La seule égalité
 *   que la graine départage est l'égalité **de variantes**, dans
 *   {@see self::variant()} et {@see self::substitute()} ; **aucune égalité de
 *   score n'est tranchée par la graine** — la chaîne de départage appartient à
 *   la spec 80 (amendement A-19).
 *
 * Les arguments sont gardés, jamais écrêtés : un index de manche ou de palier
 * nul ou négatif, ou un siège sans identité, désignerait un flux que le tirage
 * matérialisé n'a jamais lu.
 *
 * La valeur ne quitte jamais le serveur : la classe n'est ni `Arrayable` ni
 * `JsonSerializable` (§ 5.5, règle 3).
 */
final readonly class DrawContext
{
    /**
     * @param  string  $value  La chaîne de contexte, préfixée `draw:`.
     */
    private function __construct(public string $value) {}

    /** `draw:movies` — permutation des œuvres du vivier (§ 6.3). */
    public static function movies(): self
    {
        return new self('draw:movies');
    }

    /**
     * `draw:work-member:{s}` — film retenu d'une œuvre à plusieurs membres
     * (`movie_group`), à la position `s` qu'elle occuperait (§ 6.3).
     */
    public static function workMember(int $sequenceIndex): self
    {
        return new self('draw:work-member:'.self::sequence($sequenceIndex));
    }

    /**
     * `draw:variant:{s}:{t}` — départage des variantes d'un palier au lancement
     * (§ 7.1).
     */
    public static function variant(int $sequenceIndex, int $tierIndex): self
    {
        return new self('draw:variant:'.self::sequence($sequenceIndex).':'.self::tier($tierIndex));
    }

    /**
     * `draw:substitute:{s}:{t}` — départage d'une variante de substitution, à la
     * frappe du palier (§ 8.1).
     */
    public static function substitute(int $sequenceIndex, int $tierIndex): self
    {
        return new self('draw:substitute:'.self::sequence($sequenceIndex).':'.self::tier($tierIndex));
    }

    /** `draw:decoys:{s}` — parcours des rangs R1-R2 des leurres (spec 70, § 10). */
    public static function decoys(int $sequenceIndex): self
    {
        return new self('draw:decoys:'.self::sequence($sequenceIndex));
    }

    /**
     * `draw:decoys:{s}:original` — parcours des rangs R3-R4 des leurres
     * (spec 70, § 10, R-17).
     */
    public static function decoysOriginal(int $sequenceIndex): self
    {
        return new self('draw:decoys:'.self::sequence($sequenceIndex).':original');
    }

    /**
     * `draw:decoys:{s}:affinity` — parcours des groupes d'affinité des leurres
     * (même saga, puis thème studio, saga ou manuel, puis genre), au profil de
     * titre du mode normal (spec 70, § 10.3 bis, D44 du 01/10).
     */
    public static function decoysAffinity(int $sequenceIndex): self
    {
        return new self('draw:decoys:'.self::sequence($sequenceIndex).':affinity');
    }

    /**
     * `draw:decoys:{s}:affinity-original` — parcours des mêmes groupes
     * d'affinité en mode dégradé, avant R3-R4 (spec 70, § 10.3 bis, D44 du
     * 01/10).
     */
    public static function decoysAffinityOriginal(int $sequenceIndex): self
    {
        return new self('draw:decoys:'.self::sequence($sequenceIndex).':affinity-original');
    }

    /**
     * `draw:decoys:{s}:last-resort` — parcours des rangs de dernier recours
     * R5-R6 des leurres (catalogue publié sans non-répétition, puis réserve
     * non publiée), au profil de titre du mode normal (spec 70, § 10.3, D53 du
     * 02/10).
     */
    public static function decoysLastResort(int $sequenceIndex): self
    {
        return new self('draw:decoys:'.self::sequence($sequenceIndex).':last-resort');
    }

    /**
     * `draw:decoys:{s}:last-resort-original` — parcours des mêmes rangs R5-R6
     * en mode dégradé, repris à zéro (spec 70, § 10.3, D53 du 02/10).
     */
    public static function decoysLastResortOriginal(int $sequenceIndex): self
    {
        return new self('draw:decoys:'.self::sequence($sequenceIndex).':last-resort-original');
    }

    /**
     * `draw:qcm:{s}:{publicId}` — ordre des quatre propositions pour un siège
     * (spec 70, E10-54). `publicId` est `player.public_id`, jamais `player.id`.
     */
    public static function qcmOrder(int $sequenceIndex, string $playerPublicId): self
    {
        if ($playerPublicId === '') {
            throw new InvalidArgumentException('DrawContext : un contexte de QCM exige le public_id du siège.');
        }

        return new self('draw:qcm:'.self::sequence($sequenceIndex).':'.$playerPublicId);
    }

    /**
     * `round.sequence_index`, `1..K`.
     *
     * @throws InvalidArgumentException Index nul ou négatif.
     */
    private static function sequence(int $sequenceIndex): int
    {
        if ($sequenceIndex < 1) {
            throw new InvalidArgumentException(sprintf(
                'DrawContext : sequence_index = %d, attendu ≥ 1.',
                $sequenceIndex,
            ));
        }

        return $sequenceIndex;
    }

    /**
     * `round_tier.tier_index`, `1..N`.
     *
     * @throws InvalidArgumentException Index nul ou négatif.
     */
    private static function tier(int $tierIndex): int
    {
        if ($tierIndex < 1) {
            throw new InvalidArgumentException(sprintf(
                'DrawContext : tier_index = %d, attendu ≥ 1.',
                $tierIndex,
            ));
        }

        return $tierIndex;
    }
}
