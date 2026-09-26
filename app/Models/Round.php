<?php

namespace App\Models;

use App\Enums\RoundIncidentReason;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RoundFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une manche — un film, `N` paliers matérialisés, une origine de temps (§ 7.4).
 *
 * **`movie_id` est la bonne réponse**, et `decoy_movie_id_1..3` sont les trois
 * leurres : les quatre sont `#[Hidden]`, comme `choices_use_original_title`, qui
 * apprendrait avant la première image que le film cible n'a pas de titre dans la
 * locale du joueur — réduisant un catalogue majoritairement traduit à une poignée
 * de films. Rien de tout cela ne part au client avant `T_N` (ou `T₁` en Facile),
 * et jamais autrement que par la ressource dédiée du QCM (règle 3).
 *
 * **Les quatre RELATIONS sont cachées avec leurs colonnes.**
 * `relationsToArray()` passe par `getArrayableRelations()`, donc `$hidden` filtre
 * aussi les relations chargées, par leur clé camelCase. Sans cela un
 * `Round::with('movie')` — l'écriture réflexe pour composer un QCM ou verrouiller
 * une réponse — publierait `round.movie.title_original` dès `T₁`, et le test
 * « `toArray()` ne contient pas `movie_id` » passerait au vert dessus.
 *
 * L'horloge d'une manche ne se met JAMAIS en pause : si le dernier joueur
 * connecté part, la manche va au bout de `D` et c'est la PARTIE qui passe ensuite
 * en `paused`. Une pause en cours de manche rendrait faux
 * `started_at + starts_at_offset_ms`.
 *
 * @property int $id
 * @property int $game_id Identifiant interne : `#[Hidden]` (§ 1.1).
 * @property int|null $room_id Dénormalisé depuis `game` au lancement et jamais modifié ; NULL en solo. `#[Hidden]`.
 * @property int $sequence_index Position dans le tirage figé, `1..min(M + PlatformLimits::drawSubstituteMargin(), W)`, `W` = œuvres du vivier.
 * @property int|null $round_number Numéro affiché `1..M`, NON unique : une manche annulée et son remplaçant le partagent.
 * @property int $movie_id La bonne réponse. `#[Hidden]`.
 * @property RoundStatus $status
 * @property CarbonImmutable|null $started_at Origine unique du journal de la manche.
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $reveal_ends_at
 * @property int $duration_ms `D` de cette manche, dénormalisé.
 * @property int $found_count Compteur VIVANT : c'est lui qui alloue `guess.lock_rank`. Jamais remis à zéro à l'annulation.
 * @property int|null $decoy_movie_id_1 NULL en difficulté `expert`. `#[Hidden]`.
 * @property int|null $decoy_movie_id_2 `#[Hidden]`.
 * @property int|null $decoy_movie_id_3 `#[Hidden]`.
 * @property bool $choices_use_original_title Mode dégradé décidé une fois pour tout le salon. `#[Hidden]`.
 * @property RoundIncidentReason|null $cancel_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Game $game
 * @property-read Room|null $room
 * @property-read Movie $movie `#[Hidden]` : une relation chargée est sérialisée comme une colonne.
 * @property-read Movie|null $decoyMovie1 `#[Hidden]`.
 * @property-read Movie|null $decoyMovie2 `#[Hidden]`.
 * @property-read Movie|null $decoyMovie3 `#[Hidden]`.
 * @property-read Collection<int, RoundTier> $tiers
 * @property-read Collection<int, RoundPlayer> $roundPlayers
 * @property-read Collection<int, RoundChoiceSet> $choiceSets
 * @property-read Collection<int, Guess> $guesses
 */
#[Table('round')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden([
    'game_id',
    'room_id',
    'movie_id',
    'decoy_movie_id_1',
    'decoy_movie_id_2',
    'decoy_movie_id_3',
    'choices_use_original_title',
    'movie',
    'decoyMovie1',
    'decoyMovie2',
    'decoyMovie3',
])]
class Round extends Model
{
    /** @use HasFactory<RoundFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `round` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'status' => RoundStatus::Pending->value,
        'found_count' => 0,
        'choices_use_original_title' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'room_id' => 'integer',
            'sequence_index' => 'integer',
            'round_number' => 'integer',
            'movie_id' => 'integer',
            'status' => RoundStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'reveal_ends_at' => 'datetime',
            'duration_ms' => 'integer',
            'found_count' => 'integer',
            'decoy_movie_id_1' => 'integer',
            'decoy_movie_id_2' => 'integer',
            'decoy_movie_id_3' => 'integer',
            'choices_use_original_title' => 'boolean',
            'cancel_reason' => RoundIncidentReason::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Les manches dont les points comptent — invariant **L1**.
     *
     * Une manche annulée ne rapporte aucun point, or `guess` est immuable et la
     * ligne `round` n'est pas supprimée : sans cette exclusion, deux joueurs
     * verrouillés au palier 2 d'une manche ensuite annulée gardent 400 points
     * chacun. Le pendant côté réponses est {@see Guess::counted()}.
     *
     * @param  Builder<Round>  $query
     */
    #[Scope]
    protected function notCancelled(Builder $query): void
    {
        $query->where('status', '!=', RoundStatus::Cancelled);
    }

    /**
     * Les manches clôturées, seul filtre de la file de curation « jamais trouvé ».
     *
     * Emprunte `round_movie_found_idx (movie_id, found_count)`.
     *
     * @param  Builder<Round>  $query
     */
    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where('status', RoundStatus::Completed);
    }

    /**
     * Les manches **à jouer**, dans l'ordre de jeu (spec 60 § 1.2, 50 § 15.2) :
     * `pending` et **numérotées** (`round_number` non nul), par `round_number`
     * puis `sequence_index` croissants. La première est la « manche suivante
     * à jouer » ; une manche de réserve (sans numéro, E10-45) n'en fait
     * jamais partie tant qu'elle ne remplace rien.
     *
     * Lue par l'enchaînement (`RevealRound`), la fin de révélation
     * (`EndReveal`) et l'annulation (`CancelRound`), sous le verrou `game` :
     * une seule définition de l'ordre de jeu.
     *
     * @param  Builder<Round>  $query
     */
    #[Scope]
    protected function toPlay(Builder $query): void
    {
        $query->where('status', RoundStatus::Pending->value)
            ->whereNotNull('round_number')
            ->orderBy('round_number')
            ->orderBy('sequence_index');
    }

    /**
     * Le prédicat de fin anticipée du § 7.7, en toutes lettres.
     *
     * **Participants** = les lignes `round_player` de cette manche dont le
     * `player` est `connected` ET `left_at IS NULL`.
     * **Fin anticipée si et seulement si** `COUNT(participants) >= 1` ET tous les
     * participants ont leur saisie close : `input_state NOT IN ('open',
     * 'text_exhausted')`, lu par {@see RoundPlayerInputState::isClosed()}
     * (E10-53). `text_exhausted` n'est JAMAIS une saisie close (D20 du 23/09) :
     * en Normal, un participant qui a épuisé son texte libre attend encore le
     * QCM, et la manche ne se clôt pas d'anticipation tant qu'il peut cliquer.
     * Le code ne change pas avec D20 : c'est la sémantique d'`isClosed()` qui
     * change.
     *
     * La borne `>= 1` est la correction du cas vide et se lit comme telle : zéro
     * participant connecté ne clôt JAMAIS une manche — elle va au bout de `D`,
     * puis `game` passe en `paused`. Sans elle, deux joueurs qui perdent le réseau
     * à `t = 3 s` feraient défiler les manches restantes vers un podium 0-0. Et le
     * dénominateur ne compte que les lignes `round_player` existantes : un
     * retardataire admis « à la manche suivante » est `connected` sans ligne dans
     * la manche en cours, et ne doit pas bloquer la clôture.
     *
     * Lecture : au plus 12 lignes par `round_player_round_player_uq`, puis
     * jointure `player` par clé primaire. Aucun index nouveau.
     */
    public function isEarlyEndReached(): bool
    {
        $participants = RoundPlayer::query()
            ->where('round_id', $this->id)
            ->participants()
            ->get();

        if ($participants->isEmpty()) {
            return false;
        }

        return $participants->every(
            static fn (RoundPlayer $participant): bool => $participant->input_state->isClosed(),
        );
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Le salon, dénormalisé au lancement pour la non-répétition des films.
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Le film à trouver — jamais sérialisé avant la révélation.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Premier leurre du QCM. **Aucun inverse n'est déclaré sur `Movie`** : rien ne
     * balaie jamais cet ensemble et la cardinalité est fixée à trois par la règle.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function decoyMovie1(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'decoy_movie_id_1');
    }

    /**
     * @return BelongsTo<Movie, $this>
     */
    public function decoyMovie2(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'decoy_movie_id_2');
    }

    /**
     * @return BelongsTo<Movie, $this>
     */
    public function decoyMovie3(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'decoy_movie_id_3');
    }

    /**
     * Les paliers matérialisés, `1..N`.
     *
     * @return HasMany<RoundTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(RoundTier::class);
    }

    /**
     * Qui était là — y compris ceux qui n'ont pas trouvé, que `guess` ignore.
     *
     * @return HasMany<RoundPlayer, $this>
     */
    public function roundPlayers(): HasMany
    {
        return $this->hasMany(RoundPlayer::class);
    }

    /**
     * Au plus une ligne par locale activée.
     *
     * @return HasMany<RoundChoiceSet, $this>
     */
    public function choiceSets(): HasMany
    {
        return $this->hasMany(RoundChoiceSet::class);
    }

    /**
     * Les bonnes réponses, et rien d'autre.
     *
     * @return HasMany<Guess, $this>
     */
    public function guesses(): HasMany
    {
        return $this->hasMany(Guess::class);
    }
}
