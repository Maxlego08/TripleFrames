<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NearMissFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La file d'alias : les chaînes quasi-justes AGRÉGÉES par film, au-dessus d'un
 * seuil de k-anonymat (§ 7.9).
 *
 * Ce n'est pas une ligne par tentative — le schéma s'interdit de stocker une
 * tentative fausse : c'est un compteur par couple (film, forme normalisée),
 * purgé à 90 jours sur `last_seen_on`, et non à 12 mois. `normalized_text` est
 * produit par le MÊME normaliseur qu'`answer_key`, d'où sa longueur de 200.
 *
 * La table est strictement vide de toute clé vers une personne : AUCUN
 * `player_id`, `room_id`, `game_id`, `round_id`, aucune adresse IP, aucune
 * locale, et `dismissed_at` est SANS auteur — aucune clé étrangère vers `users`
 * n'existe ici, sous aucun nom. `movie_id` est son seul lien, et il ne désigne
 * personne.
 *
 * Deux gardes de k-anonymat, sans lesquelles la qualification « agrégée et sans
 * aucune donnée personnelle » de la page de confidentialité serait inexacte, et
 * qui appartiennent toutes deux au JOB d'agrégation, jamais au modèle :
 *
 * 1. une ligne ne naît qu'au TROISIÈME couple (manche, chaîne) distinct — en
 *    dessous, la chaîne est tenue en cache par le job et jetée, parce qu'une
 *    ligne `occurrences = 1` est la saisie d'UNE personne et que trois requêtes
 *    suffiraient à la lui rattacher ;
 * 2. `first_seen_on` et `last_seen_on` sont à granularité MENSUELLE (date au
 *    1er du mois) ; `distinct_rounds` remplace la date fine pour trier la file
 *    du curateur.
 *
 * **Invariant L4.** Rien de cette table ne s'exécute dans la requête de
 * soumission : une tentative refusée dépose au plus un message sur la file par
 * défaut (jamais la file `game`), et le compteur de k-anonymat comme l'insertion
 * vivent dans le job. Un refus accomplit le même travail, en même temps et en
 * même nombre de requêtes, quelle que soit la proximité de la réponse — sinon
 * l'écart de quelques millisecondes se chronomètre et une dichotomie sur les
 * préfixes reconstitue le titre sans jamais regarder une image.
 *
 * **Aucun `#[Hidden]`, et c'est une décision.** La table ne porte aucune donnée
 * personnelle et n'est jamais sérialisée vers un client de jeu — `normalized_text`
 * est une chaîne quasi-juste, donc un oracle, et ne quitte jamais le back-office
 * de curation. Son `id` y est le seul identifiant de ligne, cette file n'ayant
 * aucune identité publique opaque à la manière de `room_code` ou de `reference` :
 * le cacher rendrait une ligne inadressable au geste de promotion ou de rejet.
 *
 * **Aucun `#[Fillable]` non plus.** Aucun formulaire n'écrit ici : les sept
 * colonnes d'agrégat sont composées par le job, et `dismissed_at` est un geste
 * de curation. Les cinq colonnes NOT NULL sans défaut font échouer bruyamment
 * (1364) toute tentative d'écriture par tableau de requête.
 *
 * @property int $id
 * @property int $movie_id
 * @property string $normalized_text
 * @property int $occurrences
 * @property int $distinct_rounds
 * @property int $best_distance
 * @property CarbonImmutable $first_seen_on
 * @property CarbonImmutable $last_seen_on
 * @property CarbonImmutable|null $dismissed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 */
#[Table('near_miss')]
#[Fillable([])]
class NearMiss extends Model
{
    /** @use HasFactory<NearMissFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `near_miss` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'occurrences' => 1,
        'distinct_rounds' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_on' => 'date',
            'last_seen_on' => 'date',
            'dismissed_at' => 'datetime',
        ];
    }

    /**
     * Le seul lien de la table, et il ne désigne aucune personne. La promotion
     * d'une ligne crée un `alias` en `origin = 'curator'` sur ce film, ce qui
     * déclenche le projecteur `answer_key`.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'movie_id');
    }
}
