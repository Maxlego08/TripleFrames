<?php

namespace App\Models;

use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Database\Factories\RoundChoiceSetFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Les quatre chaînes du QCM, figées à la composition (§ 7.8).
 *
 * Figer les trois **films** ne suffit pas : le jugement d'un clic passerait par
 * `answer_key`, que le projecteur reconstruit PAR DIFFÉRENCE — donc en supprimant
 * la clé normalisée d'un titre corrigé entre-temps. D'où les quatre chaînes en
 * clair, et la règle de jugement : une soumission `source = 'choice'` est jugée
 * par **égalité stricte** contre `choice_1`, et ne consulte JAMAIS `answer_key`.
 *
 * **`choice_1` est la bonne réponse en clair.** Les quatre colonnes sont donc
 * cachées deux fois — par `#[Hidden]` et par `$hidden` au niveau modèle — parce
 * qu'un `toArray()` distrait dans une ressource de resynchronisation les
 * publierait. Les quatre chaînes ne partent au client que par la ressource dédiée
 * du QCM, **permutées** par `HMAC(game.draw_seed, round_id, player_id)`, à `T_N`
 * (ou `T₁` en Facile) : rien n'est stocké par joueur, et l'ordre ne peut être ni
 * deviné ni comparé entre deux écrans.
 *
 * Au plus une ligne par locale activée et par manche — jamais une ligne par
 * joueur, qui coûterait ~720 Mo sur douze mois.
 *
 * La table a des `timestamps(3)` conventionnels MALGRÉ `composed_at` : sans eux,
 * `$timestamps = true` fait tomber l'erreur 1054 à la composition du QCM, en
 * pleine manche (§ 1.7).
 *
 * @property int $id
 * @property int $round_id
 * @property Locale $locale Locale d'INTERFACE, `string(5)` — jamais une locale de catalogue.
 * @property string $choice_1 La chaîne du film CIBLE. `#[Hidden]`.
 * @property string $choice_2 Premier leurre, dans l'ordre de `round.decoy_movie_id_1`. `#[Hidden]`.
 * @property string $choice_3 `#[Hidden]`.
 * @property string $choice_4 `#[Hidden]`.
 * @property CarbonImmutable $composed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Round $round
 */
#[Table('round_choice_set')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden(['choice_1', 'choice_2', 'choice_3', 'choice_4'])]
class RoundChoiceSet extends Model
{
    /** @use HasFactory<RoundChoiceSetFactory> */
    use HasFactory;

    /**
     * Doublon VOLONTAIRE de `#[Hidden]`, exigé par le § 7.8 : la bonne réponse en
     * clair ne dépend pas de la résolution d'un attribut de classe.
     *
     * @var list<string>
     */
    protected $hidden = ['choice_1', 'choice_2', 'choice_3', 'choice_4'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round_id' => 'integer',
            'locale' => Locale::class,
            'composed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Round, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }
}
