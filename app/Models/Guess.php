<?php

namespace App\Models;

use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\RoundStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GuessFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une bonne réponse, et rien d'autre (§ 7.6).
 *
 * **La table ne contient QUE des bonnes réponses**, par construction : il
 * n'existe aucune colonne `is_correct`, qui inviterait à y ranger les fausses.
 * Aucune IP, aucun horodatage client. Au plus 120 lignes par partie.
 *
 * **Famille d'horodatage : `created_at` seul, `const UPDATED_AT = null`.** La
 * table n'a pas de colonne `updated_at` : sans cette constante, `$timestamps`
 * vaut `true` par défaut et l'écriture vise une colonne inexistante — erreur 1054
 * en MySQL, « no such column » en SQLite —, donc **chaque bonne réponse du jeu
 * échoue**. Et `created_at` est en `timestamp(3)`, d'où le `#[DateFormat]`
 * sub-seconde, sans lequel MySQL arrondit à la seconde supérieure et le journal
 * montre une ligne créée une seconde après sa réception.
 *
 * Les trois parts de points sont figées au verrouillage et jamais recalculées ;
 * `answered_at_ms` — un entier, jamais un horodatage — est la valeur qui a
 * déterminé le palier et le bonus, sous `game.tier_grace_ms` (§ 7.5). Le seuil de
 * Levenshtein qui a accepté `edit_distance` se lit par `game.validation_version`,
 * la fraction de bonus par `game.scoring_version`.
 *
 * `lock_rank` est alloué SOUS VERROU dans la transaction de verrouillage :
 * `SELECT … FROM round WHERE id = ? FOR UPDATE` → `found_count = found_count + 1`
 * → `lock_rank := found_count` → `INSERT guess` → `round_player.input_state =
 * 'locked'`, les cinq opérations ensemble. Un `SELECT MAX(lock_rank)` suivi d'un
 * `INSERT` n'est pas sérialisé et perdrait une bonne réponse sur 1062.
 *
 * Avant la révélation, la diffusion ne porte que `player.public_id` et
 * `lock_rank` : ni points, ni `tier_index`, ni chaîne — le badge « a trouvé » ne
 * doit rien apprendre. **C'est `#[Hidden]` qui l'outille**, exactement comme le
 * § 7.8 le fait pour `round_choice_set.choice_1` : `answer_key_normalized` EST la
 * bonne réponse en clair, et la ligne `guess` naît à l'instant précis où part
 * l'événement « X a trouvé », alors que les onze autres cherchent encore jusqu'à
 * `D`. Seul `lock_rank` reste visible. Le score, lui, ne passe par aucune
 * sérialisation de modèle : c'est `SUM(guess.points_total)` sous
 * {@see self::counted()} ; la ressource de révélation fait un `makeVisible()`
 * explicite après `reveal_ends_at`.
 *
 * @property int $id
 * @property int $round_id
 * @property int $player_id `restrictOnDelete`.
 * @property CarbonImmutable $received_at Instant SERVEUR de réception, jamais l'instant d'envoi du client.
 * @property int $answered_at_ms Millisecondes depuis `round.started_at`.
 * @property int $tier_index Palier retenu, matérialisé. `#[Hidden]` avant la révélation.
 * @property int $lock_rank Rang d'arrivée dans la manche, visible des autres.
 * @property GuessSource $source
 * @property GuessMatchKind $match_kind Quatre cas, dont `choice`, qui n'existe pas dans `answer_key`.
 * @property int|null $answer_key_id `nullOnDelete` : l'index est recalculé à chaque publication et ne doit jamais faire perdre un score. `#[Hidden]`.
 * @property string $answer_key_normalized Copie de la chaîne retenue — 200 et non 191. **La bonne réponse en clair** : `#[Hidden]`.
 * @property string $submitted_normalized Le texte brut n'est pas conservé. `#[Hidden]`.
 * @property int $edit_distance
 * @property bool $prefix_was_ambiguous Ambiguïté mesurée à l'instant du match, jamais rétroactive.
 * @property int $points_tier `#[Hidden]`.
 * @property int $points_bonus `#[Hidden]`.
 * @property int $points_total `#[Hidden]` — le podium l'agrège en SQL, jamais par `toArray()`.
 * @property CarbonImmutable|null $created_at
 * @property-read Round $round
 * @property-read Player $player
 * @property-read AnswerKey|null $answerKey
 */
#[Table('guess')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden([
    'player_id',
    'answer_key_id',
    'answer_key_normalized',
    'submitted_normalized',
    'tier_index',
    'points_tier',
    'points_bonus',
    'points_total',
])]
class Guess extends Model
{
    /** @use HasFactory<GuessFactory> */
    use HasFactory;

    /**
     * La table n'a pas de colonne `updated_at` : la ligne ne bouge plus.
     */
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round_id' => 'integer',
            'player_id' => 'integer',
            'received_at' => 'datetime',
            'answered_at_ms' => 'integer',
            'tier_index' => 'integer',
            'lock_rank' => 'integer',
            'source' => GuessSource::class,
            'match_kind' => GuessMatchKind::class,
            'answer_key_id' => 'integer',
            'edit_distance' => 'integer',
            'prefix_was_ambiguous' => 'boolean',
            'points_tier' => 'integer',
            'points_bonus' => 'integer',
            'points_total' => 'integer',
        ];
    }

    /**
     * Invariant **L1** : les réponses dont les points comptent.
     *
     * **Toute agrégation de `guess` passe par ici** — score vivant, classement
     * intermédiaire, gel du podium, les quatre compteurs d'historique. Une manche
     * annulée ne rapporte aucun point, or `guess` est immuable et la ligne `round`
     * n'est pas supprimée : sans cette exclusion, deux joueurs verrouillés au
     * palier 2 d'une manche ensuite annulée gardent 400 points chacun.
     *
     * Sous-requête sur `round` plutôt qu'une jointure : le scope ne doit changer ni
     * le `select` ni la cardinalité de l'agrégat qui le suit.
     *
     * @param  Builder<Guess>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->whereIn('round_id', Round::query()
            ->where('status', '!=', RoundStatus::Cancelled)
            ->select('id'));
    }

    /**
     * @return BelongsTo<Round, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * La clé retenue au moment du match — nullable, et c'est voulu : la
     * projection `answer_key` est reconstruite par différence à chaque
     * publication, et `answer_key_normalized` garde l'instantané autosuffisant.
     *
     * @return BelongsTo<AnswerKey, $this>
     */
    public function answerKey(): BelongsTo
    {
        return $this->belongsTo(AnswerKey::class);
    }
}
