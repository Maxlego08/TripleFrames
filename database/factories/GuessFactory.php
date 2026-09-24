<?php

namespace Database\Factories;

use App\Enums\AnswerKeyKind;
use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Models\AnswerKey;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Catalog\AnswerKeyNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Guess} — **une bonne réponse, et rien d'autre**
 * (§ 7.6).
 *
 * La table ne contient que des bonnes réponses, par construction : il n'existe
 * aucune colonne `is_correct`, et une fabrique n'a donc aucun état « mauvaise
 * réponse » à exposer. Les tentatives fausses sont comptées par
 * `round_player.wrong_attempts` ({@see RoundPlayerFactory::withWrongAttempts()}),
 * jamais stockées.
 *
 * **`answered_at_ms` est un entier, jamais un horodatage** : c'est la valeur qui a
 * déterminé le palier et le bonus, et le calcul du palier se fait en arithmétique
 * entière, identique dans les deux moteurs et immune à tout fuseau (§ 1.2).
 * `received_at` est l'instant SERVEUR de réception — jamais l'instant d'envoi du
 * client, qu'aucune colonne ne porte (invariant **L3**).
 *
 * Les trois parts de points sont **figées au verrouillage et jamais recalculées** :
 * {@see self::atTier()} et {@see self::withSpeedBonus()} les écrivent ensemble,
 * pour qu'aucun état ne laisse `points_total ≠ points_tier + points_bonus`.
 *
 * `lock_rank` est alloué **sous verrou** dans la transaction de verrouillage
 * (`round.found_count + 1`) : la fabrique le pose à 1, et `guess_round_rank_uq`
 * impose de le faire varier explicitement dès qu'une manche porte plusieurs
 * gagnants ({@see self::withRank()}).
 *
 * `answer_key_id` reste **nul** par défaut, et c'est voulu : la projection
 * `answer_key` est reconstruite par différence à chaque publication, et
 * `answer_key_normalized` garde l'instantané autosuffisant.
 *
 * @extends Factory<Guess>
 */
class GuessFactory extends Factory
{
    /**
     * Forme normalisée de fabrique : déjà repliée (minuscules, sans diacritique,
     * espaces compressés), comme la sortie du normaliseur de la spec 70.
     */
    public const string NORMALIZED_ANSWER = 'ombre et cite';

    /**
     * Define the model's default state.
     *
     * Premier gagnant d'une manche, verrouillé au palier 1 en texte libre, sans
     * bonus de rapidité.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $settings = RoomSettings::defaults();

        return array_merge([
            'round_id' => Round::factory(),
            'player_id' => Player::factory(),
            'received_at' => now(),
            'lock_rank' => 1,
            'source' => GuessSource::Text,
            'match_kind' => GuessMatchKind::Title,
            'answer_key_id' => null,
            'answer_key_normalized' => self::NORMALIZED_ANSWER,
            'submitted_normalized' => self::NORMALIZED_ANSWER,
            'edit_distance' => 0,
            'prefix_was_ambiguous' => false,
        ], self::atTierState(1, $settings));
    }

    /**
     * Bonne réponse d'un couple (manche, siège) existant.
     */
    public function forRound(Round $round, Player $player): static
    {
        return $this->state(fn (array $attributes): array => [
            'round_id' => $round->id,
            'player_id' => $player->id,
        ]);
    }

    /**
     * Palier retenu, et les points qui en découlent — les trois parts ensemble.
     *
     * `answered_at_ms` est posé **une seconde après l'ouverture du palier**, donc
     * loin de la frontière : la fenêtre de grâce de ±300 ms
     * ({@see PlatformLimits::tierGraceMs()}) ne peut pas faire
     * basculer le palier d'un test qui ne l'étudie pas.
     */
    public function atTier(int $tierIndex, ?RoomSettings $settings = null): static
    {
        return $this->state(fn (array $attributes): array => self::atTierState($tierIndex, $settings));
    }

    /**
     * Rang d'arrivée dans la manche — la seule colonne visible des autres avant la
     * révélation, avec `player.public_id`.
     */
    public function withRank(int $lockRank): static
    {
        return $this->state(fn (array $attributes): array => [
            'lock_rank' => $lockRank,
        ]);
    }

    /**
     * Bonus de rapidité — **fraction du palier**, donc jamais additionné sans
     * réécrire `points_total`. La formule exacte appartient à `80`, et cet état ne
     * la reproduit pas : il pose une part déjà calculée.
     */
    public function withSpeedBonus(int $pointsBonus): static
    {
        return $this->state(function (array $attributes) use ($pointsBonus): array {
            $pointsTier = $attributes['points_tier'] ?? 0;

            return [
                'points_bonus' => $pointsBonus,
                'points_total' => (is_int($pointsTier) ? $pointsTier : 0) + $pointsBonus,
            ];
        });
    }

    /**
     * Verrouillage par clic de proposition : jugé par **égalité stricte** contre
     * `round_choice_set.choice_1`, donc sans distance d'édition et sans clé — le
     * cas `choice` n'existe pas dans `answer_key`.
     */
    public function viaChoice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => GuessSource::Choice,
            'match_kind' => GuessMatchKind::Choice,
            'answer_key_id' => null,
            'edit_distance' => 0,
            'prefix_was_ambiguous' => false,
        ]);
    }

    /**
     * Acceptation par tolérance : la distance retenue est stockée, **le seuil qui
     * l'a acceptée se lit par `game.validation_version`** et jamais ici.
     */
    public function withEditDistance(int $editDistance): static
    {
        return $this->state(fn (array $attributes): array => [
            'edit_distance' => $editDistance,
        ]);
    }

    /**
     * Acceptation par préfixe. `prefix_was_ambiguous` y vaut **toujours faux** :
     * la décision 13 n'accepte un préfixe que non ambigu, et un préfixe porté par
     * un autre film publié est refusé (spec 70 § 6.4, E10-50). L'état « préfixe
     * accepté et ambigu » n'existe donc pas : un test posé dessus prouverait un
     * comportement sur un instantané que le validateur ne peut pas émettre.
     */
    public function viaPrefix(): static
    {
        return $this->state(fn (array $attributes): array => [
            'match_kind' => GuessMatchKind::Prefix,
            'prefix_was_ambiguous' => false,
        ]);
    }

    /**
     * Clé retenue au moment du match. `answer_key_normalized` en recopie la forme :
     * la colonne est un instantané autosuffisant, que le `nullOnDelete` de la FK
     * existe précisément pour préserver.
     *
     * **`match_kind` est DÉRIVÉ de la nature de la clé** par
     * {@see AnswerKeyKind::toMatchKind()}, jamais codé ni recopié : une ligne
     * étiquetée `alias` dont `answer_key_id` désigne une clé `prefix` est un
     * instantané que le validateur ne peut pas émettre, et un test de
     * `prefix_was_ambiguous` passerait dessus en prouvant le contraire de ce qu'il
     * croit.
     *
     * `submitted_normalized` vaut la forme de la clé, sauf acceptation par
     * tolérance (spec 70 § 6.2, étape d) : `$submittedNormalized` est alors la
     * saisie normalisée, et `edit_distance` est **calculée** par
     * {@see AnswerKeyNormalizer::distance()}, jamais posée à la main — une saisie
     * différente de la clé n'existe qu'avec la distance qui l'a acceptée.
     *
     * `prefix_was_ambiguous` ne peut être vrai que sur un appariement exact d'une
     * clé de nature exacte (étape a, homonyme publié) : il est forcé à faux pour
     * une clé dérivée et pour une acceptation par tolérance (§ 6.4), et garde
     * sinon la valeur des états précédents.
     */
    public function forAnswerKey(AnswerKey $answerKey, ?string $submittedNormalized = null): static
    {
        $submitted = $submittedNormalized ?? $answerKey->normalized;
        $exactMatch = $submitted === $answerKey->normalized;

        return $this->state(fn (array $attributes): array => [
            'answer_key_id' => $answerKey->id,
            'answer_key_normalized' => $answerKey->normalized,
            'submitted_normalized' => $submitted,
            'edit_distance' => $exactMatch ? 0 : AnswerKeyNormalizer::distance($submitted, $answerKey->normalized),
            'match_kind' => $answerKey->key_kind->toMatchKind(),
            'prefix_was_ambiguous' => $exactMatch
                && $answerKey->key_kind->isExact()
                && ($attributes['prefix_was_ambiguous'] ?? false) === true,
        ]);
    }

    /**
     * Le palier, l'instant en millisecondes et les trois parts de points — toujours
     * ensemble, jamais l'un sans les autres.
     *
     * @return array<string, mixed>
     */
    private static function atTierState(int $tierIndex, ?RoomSettings $settings): array
    {
        $settings ??= RoomSettings::defaults();
        $pointsTier = $settings->tierPoints[$tierIndex - 1] ?? 0;

        return [
            'answered_at_ms' => $settings->tierStartOffsetMs($tierIndex) + 1_000,
            'tier_index' => $tierIndex,
            'points_tier' => $pointsTier,
            'points_bonus' => 0,
            'points_total' => $pointsTier,
        ];
    }
}
