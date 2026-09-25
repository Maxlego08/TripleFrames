<?php

namespace App\Actions\Curation;

use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\Movie;
use App\Models\User;
use App\Support\Catalog\AnswerKeyProjector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Ajouter un alias à un film — spec 20 § 9.2, ligne 22 de la matrice des
 * capacités (`can:curate,movie`).
 *
 * Un alias sert à **valider** une réponse, jamais à afficher le film ; il est
 * accepté quel que soit le joueur et quel que soit le salon. Il est écrit en
 * `origin = curator`, `created_by_id` = le curateur — une resynchronisation
 * ne touche jamais une ligne `curator` (spec 10 § 9.3) —, dans une locale
 * **activée** seulement : seules elles entrent dans `answer_key`, et la
 * requête refuse les autres.
 *
 * **Un alias ne produit jamais de forme dérivée** — ni préfixe, ni sous-titre
 * (décision 13, D23 du 23/09) : le projecteur n'en dérive que des titres.
 * « seigneur des anneaux 2 » est un alias curé, pas une règle.
 *
 * **Aucune unicité sur le texte** (spec 10 § 3.4) : deux alias dont la forme
 * normalisée coïncide coexistent, et le dédoublonnage vit dans `answer_key`
 * (`unique(normalized, movie_id)`). L'écran avertit avant l'envoi quand la
 * forme est déjà acceptée pour ce film ; le serveur ne refuse pas.
 *
 * Une transaction, le film verrouillé : la ligne, puis les clés reprojetées
 * par différence avec le recompte synchrone de leur ambiguïté.
 */
final class AddAlias
{
    public function __construct(
        private readonly AnswerKeyProjector $answerKeys,
    ) {}

    /**
     * @param  string  $text  déjà rogné et borné par la requête
     *
     * @throws AuthorizationException le film a été retiré entre la garde et le verrou
     * @throws Throwable
     */
    public function handle(Movie $movie, User $curator, Locale $locale, string $text): Alias
    {
        return DB::transaction(function () use ($movie, $curator, $locale, $text): Alias {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('curate', $locked);

            $alias = new Alias;
            $alias->movie_id = $locked->id;
            $alias->locale = $locale->value;
            $alias->alias = $text;
            $alias->origin = ContentOrigin::Curator;
            $alias->created_by_id = $curator->id;
            $alias->save();

            $this->answerKeys->project($locked);

            return $alias;
        });
    }
}
