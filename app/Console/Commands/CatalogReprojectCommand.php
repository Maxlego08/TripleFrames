<?php

namespace App\Console\Commands;

use App\Enums\AnswerKeyKind;
use App\Enums\Locale;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\MovieProjector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * La reprojection du catalogue — spec 10 § 3.2 (règle 3), contrat C18-bis,
 * place dans le hook décidée par 100 § 11.5 (étape 7).
 *
 * Elle recalcule `movie_projection` par {@see MovieProjector} et `answer_key`
 * par {@see AnswerKeyProjector}, les deux implémentations uniques du projet :
 * aucune règle de projection n'est réécrite ici.
 *
 * **Par différence, jamais par purge et réinsertion.** Une clé qui existe
 * encore garde son identifiant — `guess` le conserve douze mois dans son
 * instantané de règle —, une clé périmée est supprimée (`guess.answer_key_id`
 * passe à NULL, l'instantané reste autosuffisant), une clé manquante est
 * créée. D'où l'**idempotence** : rejouée sur un catalogue à jour, elle ne
 * réécrit aucune clé.
 *
 * **Ordre** (10 § 3.2, règle 3) :
 * 1. les lignes `movie_projection` manquantes sont créées AVANT toute mise à
 *    jour, chacune avec toutes ses colonnes calculées ;
 * 2. chaque film est reprojeté dans sa propre transaction — projection, clés
 *    de réponse et ambiguïté des valeurs touchées ;
 * 3. sans `--movie`, l'ambiguïté des clés dérivées est recomptée sur le
 *    catalogue `published` ENTIER, y compris pour les valeurs qu'aucun film n'a
 *    touchées : c'est le filet d'une disponibilité changée hors du projecteur
 *    (restauration, correction manuelle).
 *
 * **Quand.** À chaque déploiement (étape 7 du hook : après tout changement de
 * `Locale::MASK_VERSION` ou de la règle de normalisation, sans compter sur la
 * mémoire du porteur) et après toute restauration (§ 13.5, étape (d bis)).
 *
 * **Ce qu'elle n'écrit jamais** : `movie`, `frame`, `movie_title`, `alias`,
 * `frame_review`. La règle 12 ne l'astreint donc pas à un instantané.
 */
class CatalogReprojectCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:reproject
        {--movie=* : identifiants à reprojeter, tous par défaut}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprojette movie_projection et answer_key par différence, de façon idempotente';

    /** Films lus par lot : borne la mémoire sur un catalogue entier. */
    private const int CHUNK = 200;

    /** Valeurs dérivées recomptées par appel du projecteur. */
    private const int AMBIGUITY_CHUNK = 500;

    public function handle(MovieProjector $projections, AnswerKeyProjector $answerKeys): int
    {
        $ids = $this->requestedIds();

        // 1. Lignes manquantes d'abord : un film n'est jamais laissé sans
        //    projection si une reprojection s'interrompt plus loin.
        /** @var array<int, true> $created */
        $created = [];

        $this->movies($ids)
            ->whereDoesntHave('projection')
            ->chunkById(self::CHUNK, function (EloquentCollection $movies) use ($projections, &$created): void {
                foreach ($movies as $movie) {
                    DB::transaction(static fn () => $projections->recompute($movie));

                    $created[$movie->id] = true;
                }
            });

        // 2. Chaque film, dans sa transaction.
        $reprojected = 0;

        $this->movies($ids)->chunkById(self::CHUNK, function (EloquentCollection $movies) use ($projections, $answerKeys, $created, &$reprojected): void {
            foreach ($movies as $movie) {
                DB::transaction(static function () use ($movie, $projections, $answerKeys, $created): void {
                    if (! isset($created[$movie->id])) {
                        $projections->recompute($movie);
                    }

                    $answerKeys->project($movie);
                });

                $reprojected++;
            }
        });

        // 3. Le filet : l'ambiguïté sur le catalogue entier.
        if ($ids === null) {
            $this->recomputeDerivedAmbiguity($answerKeys);
        }

        $message = trans('admin.console.reproject.done', ['movies' => $reprojected], Locale::French->value);

        $this->components->info(is_string($message) ? $message : '');

        return self::SUCCESS;
    }

    /**
     * Les identifiants de `--movie`, ou `null` sans l'option (tous les films).
     * Une option présente dont aucune valeur n'est un identifiant rend une
     * liste VIDE, jamais `null` : une faute de frappe ne reprojette pas le
     * catalogue entier. Le compte rendu dit combien de films l'ont été.
     *
     * @return list<int>|null
     */
    private function requestedIds(): ?array
    {
        $option = $this->option('movie');

        if ($option === []) {
            return null;
        }

        $ids = [];

        foreach ($option as $value) {
            $value = is_string($value) ? trim($value) : '';

            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>|null  $ids
     * @return Builder<Movie>
     */
    private function movies(?array $ids): Builder
    {
        $query = Movie::query();

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query;
    }

    /**
     * Recompte `is_ambiguous` pour chaque valeur portée par une clé dérivée,
     * préfixe ou sous-titre ({@see AnswerKeyKind::collisionCheckedValues()}),
     * par le projecteur lui-même : aucune seconde formulation de la règle.
     */
    private function recomputeDerivedAmbiguity(AnswerKeyProjector $answerKeys): void
    {
        /** @var list<string> $values */
        $values = AnswerKey::query()
            ->whereIn('key_kind', AnswerKeyKind::collisionCheckedValues())
            ->distinct()
            ->orderBy('normalized')
            ->pluck('normalized')
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->values()
            ->all();

        foreach (array_chunk($values, self::AMBIGUITY_CHUNK) as $chunk) {
            $answerKeys->recomputeAmbiguity($chunk);
        }
    }
}
