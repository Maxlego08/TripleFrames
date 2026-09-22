<?php

namespace Database\Factories;

use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Models\Movie;
use App\Models\MovieProjection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tout ce qui est dérivé d'un film, et rien d'autre (§ 3.2). Clé primaire
 * `movie_id`, exactement une ligne par film.
 *
 * **Cette factory est une exception assumée.** En production, une ligne de
 * projection n'est jamais créée seule : elle naît dans la transaction de
 * création du film et {@see MovieFactory::create()} reproduit exactement cela.
 * Elle existe ici pour deux usages, et aucun autre — le balayage de modèles du
 * § 1.7, qui fabrique une ligne par modèle, et les tests qui ont besoin d'une
 * projection **délibérément fausse** (masque périmé, niveaux non couverts) pour
 * vérifier que le vivier et le tirage des leurres s'en défendent.
 *
 * D'où `Movie::factory()->withoutProjection()` en défaut de `movie_id` : sans
 * lui, le film créé par la résolution de la relation écrirait déjà sa
 * projection, et l'insertion suivante violerait la clé primaire.
 *
 * Pour recalculer une projection sur l'état réel de la base, ne pas passer par
 * cette factory : {@see MovieFactory::recomputeProjection()}.
 *
 * @extends Factory<MovieProjection>
 */
class MovieProjectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Une projection **vide et fraîche** : aucun niveau couvert, aucun titre,
     * mais `title_mask_version` à la version courante et `recomputed_at` posée —
     * les deux colonnes NOT NULL sans défaut du § 3.2.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movie_id' => Movie::factory()->withoutProjection(),
            'levels_mask' => 0,
            'levels_count' => 0,
            'level_1_variants' => 0,
            'level_2_variants' => 0,
            'level_3_variants' => 0,
            'level_4_variants' => 0,
            'level_5_variants' => 0,
            'variants_total' => 0,
            'title_locale_mask' => 0,
            'title_mask_version' => Locale::MASK_VERSION,
            'recomputed_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * Les niveaux couverts par au moins une variante jouable, avec le nombre de
     * variantes par niveau. `levels_count` est la seule colonne indexable du
     * calcul de vivier : l'éligibilité à un `N` se lit `levels_count >= N`.
     */
    public function coveringLevels(int $variantsPerLevel = 1, FrameLevel ...$levels): static
    {
        $variants = [];

        foreach (FrameLevel::cases() as $level) {
            $variants[$level->variantsColumn()] = 0;
        }

        $perLevel = max(0, $variantsPerLevel);
        $mask = 0;
        $distinct = 0;

        foreach ($levels as $level) {
            $column = $level->variantsColumn();

            if ($perLevel > 0 && ($variants[$column] ?? 0) === 0) {
                $mask |= $level->bit();
                $distinct++;
            }

            $variants[$column] = $perLevel;
        }

        return $this->state(array_merge($variants, [
            'levels_mask' => $mask,
            'levels_count' => $distinct,
            'variants_total' => $distinct * $perLevel,
        ]));
    }

    /**
     * La moitié « banque d'images » de la condition de publication : niveaux 1,
     * 3 et 5 couverts, soit `levels_mask & 21 = 21`. L'autre moitié,
     * `content_flag = clear`, vit sur `movie`.
     */
    public function publishable(int $variantsPerLevel = 1): static
    {
        return $this->coveringLevels(
            $variantsPerLevel,
            FrameLevel::Level1,
            FrameLevel::Level3,
            FrameLevel::Level5,
        );
    }

    /**
     * Le profil de disponibilité de titre : un bit par locale **activée**, dans
     * l'ordre ordinal de `App\Enums\Locale`. C'est lui qui rend le tirage des
     * trois leurres à profil identique interrogeable par égalité indexée.
     */
    public function withTitleLocales(Locale ...$locales): static
    {
        $mask = 0;

        foreach ($locales as $locale) {
            $mask |= $locale->maskBit();
        }

        return $this->state(['title_locale_mask' => $mask]);
    }

    /**
     * Un masque de titre **périmé** : aucune ligne n'apparie la version
     * courante, donc aucun leurre n'est trouvé et les quatre propositions
     * basculent ensemble sur `title_original`. L'échec tombe du côté visible,
     * jamais du côté silencieux (§ 3.2) — et ce state est le seul moyen de le
     * prouver par un test.
     */
    public function staleTitleMask(): static
    {
        return $this->state(['title_mask_version' => 0]);
    }

    /**
     * Une projection dont la date de fraîcheur est ancienne : une projection
     * sans date juste est indébogable après restauration.
     */
    public function recomputedAt(CarbonImmutable $at): static
    {
        return $this->state(['recomputed_at' => $at]);
    }
}
