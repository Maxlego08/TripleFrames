<?php

namespace App\Actions\Curation;

use App\Enums\ContentAvailability;
use App\Models\Movie;
use App\Models\User;
use App\Support\Curation\ReadyBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * « Publier les films prêts » — spec 20 § 8.1 bis, ligne 47 de la matrice,
 * D59 du 06/10.
 *
 * **Un geste explicite du curateur sur un lot qu'il a lu** : la liste des
 * films et l'avertissement d'ambiguïté de chacun ({@see ReadyBatch}), dont
 * l'écran poste les identifiants et l'empreinte. Rien d'autre que
 * {@see PublishMovie} n'écrit : mêmes gardes, mêmes écritures, une ligne
 * `movie.published` par film au nom du curateur.
 *
 * **Tout ou rien, dans une transaction** : les films verrouillés par
 * identifiant croissant (ordre de verrou constant, aucun interblocage entre
 * deux lots), puis chacun repasse les gardes de {@see PublishMovie}, puis
 * l'empreinte du lot est recalculée, puis seulement les écritures. Un film
 * qui n'est plus publiable, ou un avertissement qui a changé, refuse le lot
 * entier sans rien écrire (`admin.catalog.publish_ready.stale`) : l'écran
 * se recharge sur le lot à jour.
 */
final readonly class PublishReadyMovies
{
    public function __construct(
        private PublishMovie $publish,
        private ReadyBatch $batch,
    ) {}

    /**
     * Publie les films du lot ; rend leur nombre.
     *
     * @param  list<int>  $movieIds  les films que l'écran a montrés
     * @param  string  $digest  l'empreinte de l'aperçu du lot
     *
     * @throws ValidationException le lot a changé — sans aucune écriture
     * @throws Throwable
     */
    public function handle(User $curator, array $movieIds, string $digest): int
    {
        return DB::transaction(function () use ($curator, $movieIds, $digest): int {
            $ids = array_values(array_unique($movieIds));
            sort($ids);

            $locked = Movie::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            if ($ids === [] || $locked->count() !== count($ids)) {
                throw self::stale();
            }

            foreach ($locked as $movie) {
                // Le lot ne porte que des brouillons (`ReadyBatch`) : un film
                // dépublié ou écarté depuis l'affichage se republie à l'unité.
                if ($movie->availability !== ContentAvailability::Draft) {
                    throw self::stale();
                }

                try {
                    $this->publish->assertPublishable($movie, $curator);
                } catch (AuthorizationException|ValidationException) {
                    throw self::stale();
                }
            }

            if (! hash_equals($this->batch->digest($locked), $digest)) {
                throw self::stale();
            }

            foreach ($locked as $movie) {
                $this->publish->write($movie, $curator);
            }

            return $locked->count();
        });
    }

    private static function stale(): ValidationException
    {
        return ValidationException::withMessages([
            'ambiguity_digest' => __('admin.catalog.publish_ready.stale'),
        ]);
    }
}
