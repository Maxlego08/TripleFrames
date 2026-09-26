<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use Carbon\CarbonImmutable;
use Database\Factories\RoundPlayerFactory;
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
 * Qui était là, et n'a pas trouvé (§ 7.6).
 *
 * C'est la ligne qui manque au journal si l'on se contente de `guess` et
 * `round_tier` : `guess` n'a que des gagnants, donc sans elle on ne distingue pas
 * « Bob était présent et a échoué » de « Bob n'était pas dans la manche » — or
 * c'est le dénominateur du taux de réussite et la première phrase d'un joueur
 * dans un litige.
 *
 * Les tentatives fausses sont **comptées, jamais stockées** : `wrong_attempts` et
 * rien d'autre, ni texte ni ligne.
 *
 * `input_state` n'est JAMAIS diffusé pour un autre joueur : `qcm_wrong`
 * révélerait une mauvaise réponse, que la règle interdit de diffuser.
 *
 * Invariants testés : `input_state = 'locked'` **si et seulement si** une ligne
 * `guess` existe pour le même couple ; `text_exhausted` est inatteignable hors
 * `game.input_difficulty = 'normal'` ; `revealed` et `skipped` sont
 * inatteignables hors `game.mode = 'solo'`
 * ({@see RoundPlayerInputState::isSoloOnly()}).
 *
 * @property int $id
 * @property int $round_id
 * @property int $player_id `restrictOnDelete`.
 * @property RoundPlayerInputState $input_state
 * @property CarbonImmutable|null $input_closed_at En Normal, un clic faux ferme AUSSI le texte libre, et cet instant doit être opposable.
 * @property int $wrong_attempts
 * @property Locale|null $choices_locale Langue de COMPOSITION du QCM, figée à la première composition ; un changement de langue en manche ne recompose jamais.
 * @property CarbonImmutable|null $choices_composed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Round $round
 * @property-read Player $player
 */
#[Table('round_player')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden(['player_id'])]
class RoundPlayer extends Model
{
    /** @use HasFactory<RoundPlayerFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `round_player` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'input_state' => RoundPlayerInputState::Open->value,
        'wrong_attempts' => 0,
    ];

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
            'input_state' => RoundPlayerInputState::class,
            'input_closed_at' => 'datetime',
            'wrong_attempts' => 'integer',
            'choices_locale' => Locale::class,
            'choices_composed_at' => 'datetime',
        ];
    }

    /**
     * Les **participants** au sens du § 7.7 : les lignes de cette manche dont le
     * siège est `connected` ET n'est pas parti.
     *
     * C'est le dénominateur, et le seul, du prédicat de fin anticipée
     * ({@see Round::isEarlyEndReached()}). Un joueur connecté SANS ligne
     * `round_player` — un retardataire admis à la manche suivante — n'entre donc
     * jamais dans ce compte et ne peut pas bloquer la clôture.
     *
     * Sous-requête sur la clé primaire de `player` plutôt qu'une jointure : le
     * scope ne doit toucher ni au `select` ni au nombre de lignes rendues.
     *
     * @param  Builder<RoundPlayer>  $query
     */
    #[Scope]
    protected function participants(Builder $query): void
    {
        $query->whereIn('player_id', Player::query()
            ->where('connection_state', PlayerConnectionState::Connected)
            ->whereNull('left_at')
            ->select('id'));
    }

    /**
     * Les lignes dont la saisie n'est **pas close** — le complément du
     * numérateur de la fin anticipée.
     *
     * Le nom est conservé, la sémantique élargie (D20 du 23/09, contrat C10) :
     * `open` ET `text_exhausted`, qui attend encore le QCM. La liste se lit
     * sur {@see RoundPlayerInputState::notClosedValues()}, jamais recopiée.
     *
     * @param  Builder<RoundPlayer>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('input_state', RoundPlayerInputState::notClosedValues());
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
}
