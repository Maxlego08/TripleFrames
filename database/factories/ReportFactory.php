<?php

namespace Database\Factories;

use App\Enums\ReportTarget;
use App\Models\Player;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Report} — un signalement de joueur, limité **par
 * construction** à deux cibles (§ 8.1).
 *
 * `target_type` est une liste fermée à deux natures, ce qui rend structurellement
 * impossible de signaler un avatar prédéfini ou une image de jeu. Les deux cibles
 * s'excluent, et la fabrique le tient : `target_player_id` est renseigné **si et
 * seulement si** la cible est un pseudo ; `target_user_id` **si et seulement si**
 * c'est un avatar de fournisseur, le masquage étant global et non par salon. Les
 * deux états ci-dessous écrivent donc toujours les trois colonnes ensemble.
 *
 * Le signaleur est **toujours** désigné par son SIÈGE : un invité n'a pas de
 * compte.
 *
 * **Aucun motif, aucune catégorie, aucune copie du fichier signalé.** Il n'existe
 * aucun enum de motif de signalement, et il ne faut pas en inventer un — pas même
 * pour une fabrique.
 *
 * **Ajout seul** : le modèle refuse toute réécriture sur `updating`. La fabrique
 * ne produit donc que des insertions, et un `Report::factory()->create([...])`
 * suivi d'un `update()` lève — c'est le comportement voulu.
 *
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Un signalement de pseudo : la cible la plus fréquente, et la seule qui soit
     * cantonnée à un salon.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_type' => ReportTarget::Nickname,
            'reporter_player_id' => Player::factory(),
            'target_player_id' => Player::factory(),
            'target_user_id' => null,
        ];
    }

    /**
     * Signaleur imposé. Le seuil de masquage est de **deux signaleurs DISTINCTS**,
     * fermé en base par `report_reporter_player_uq` et `report_reporter_user_uq` :
     * deux appels avec le même siège sur la même cible lèvent une 1062, et c'est
     * exactement ce qui empêche qu'un seul joueur masque un adversaire en cliquant
     * deux fois.
     */
    public function by(Player $reporter): static
    {
        return $this->state(fn (array $attributes): array => [
            'reporter_player_id' => $reporter->id,
        ]);
    }

    /**
     * Cible « pseudo » — les trois colonnes ensemble.
     */
    public function againstNickname(Player $target): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_type' => ReportTarget::Nickname,
            'target_player_id' => $target->id,
            'target_user_id' => null,
        ]);
    }

    /**
     * Cible « avatar de fournisseur » — le masquage est **global**, donc la cible
     * est un COMPTE et jamais un siège.
     */
    public function againstProviderAvatar(?User $target = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_type' => ReportTarget::ProviderAvatar,
            'target_player_id' => null,
            'target_user_id' => $target === null ? User::factory() : $target->id,
        ]);
    }
}
