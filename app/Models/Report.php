<?php

namespace App\Models;

use App\Enums\ReportTarget;
use Carbon\CarbonImmutable;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un signalement de joueur, limité par construction à DEUX cibles (§ 8.1).
 *
 * `target_type` est une liste fermée à deux natures — `nickname` et
 * `provider_avatar` —, ce qui rend structurellement impossible de signaler un
 * avatar prédéfini ou une image de jeu. `target_player_id` est renseigné si et
 * seulement si la cible est un pseudo ; `target_user_id` si et seulement si
 * c'est un avatar de fournisseur, le masquage étant global et non par salon.
 * Le signaleur, lui, est toujours désigné par son SIÈGE : un invité n'a pas de
 * compte.
 *
 * **Seuil unique de deux signaleurs DISTINCTS pour les deux cibles**, fermé en
 * base par les deux UNIQUE `(reporter_player_id, target_player_id)` et
 * `(reporter_player_id, target_user_id)` : deux lignes impliquent mécaniquement
 * deux signaleurs. Sans l'unique côté pseudo, un seul joueur masquerait le
 * pseudo d'un adversaire en cliquant deux fois, ce qui ferait du remède non
 * punitif une arme de partie.
 *
 * Aucune adresse IP, aucun motif libre, AUCUNE CATÉGORIE, aucune copie du
 * fichier signalé : « aucune conservation de preuve » porte sur l'image, pas sur
 * la ligne, qui doit survivre assez longtemps pour compter deux signaleurs. Il
 * n'existe donc aucun enum de motif de signalement, et il ne faut pas en
 * inventer un.
 *
 * Aucune colonne `locale` non plus : le signaleur ne reçoit jamais rien, et le
 * seul message sortant — la notification de masquage — s'adresse au titulaire du
 * compte cible, dont la langue se lit dans `users.locale` par `target_user_id`.
 * Un masquage de pseudo ne notifie personne.
 *
 * **Ajout seul, purge à 12 mois sur `created_at`.** La table n'a pas
 * d'`updated_at` : `const UPDATED_AT = null`, sans quoi `$timestamps = true`
 * écrirait une colonne inexistante (1054 en MySQL, « no such column » en
 * SQLite). Une garde sur `updating` refuse toute réécriture ; la SUPPRESSION
 * reste permise, c'est elle que la purge exerce. L'état résultant vit ailleurs
 * et survit — `users.avatar_provider_hidden_at` et `player.nickname_masked_at` :
 * purger un signalement ne démasque jamais rien.
 *
 * @property int $id
 * @property ReportTarget $target_type
 * @property int $reporter_player_id
 * @property int|null $target_player_id
 * @property int|null $target_user_id
 * @property CarbonImmutable|null $created_at
 * @property-read Player $reporterPlayer
 * @property-read Player|null $targetPlayer
 * @property-read User|null $targetUser
 */
#[Table('report')]
#[Fillable(['target_type'])]
#[Hidden(['reporter_player_id'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /** La table ne porte que `created_at` (§ 8.1). */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => ReportTarget::class,
        ];
    }

    /**
     * Ajout seul : une ligne de signalement ne bouge plus. La garde vit ici et
     * non dans un déclencheur SQL. `deleting` n'est pas gardé — la purge à
     * 12 mois supprime ces lignes, et c'est le seul geste qui les touche.
     */
    protected static function booted(): void
    {
        static::updating(function (self $report): void {
            throw new LogicException(
                'Un signalement est en ajout seul : la ligne ['.$report->id.'] ne peut pas être modifiée.',
            );
        });
    }

    /**
     * Le signaleur, désigné par son siège et jamais par un compte : un invité
     * n'en a pas.
     *
     * @return BelongsTo<Player, $this>
     */
    public function reporterPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'reporter_player_id');
    }

    /**
     * Renseigné si et seulement si `target_type` vaut `nickname`.
     *
     * @return BelongsTo<Player, $this>
     */
    public function targetPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'target_player_id');
    }

    /**
     * Renseigné si et seulement si `target_type` vaut `provider_avatar`, le
     * masquage d'un avatar étant global et non par salon.
     *
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
