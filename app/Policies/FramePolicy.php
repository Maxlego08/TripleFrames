<?php

namespace App\Policies;

use App\Enums\ContentAvailability;
use App\Enums\UserRole;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Config;

/**
 * Voir, ajouter, recadrer et dépublier une image de la banque d'un film —
 * contrat C9, table du § 2.9 de la spec 20, méthodes du jalon 1.
 *
 * Même règle que partout : **aucun `Gate::before`**, chaque seuil est posé ici
 * par {@see UserRole::atLeast()}. Les gestes réservés à l'admin — suspendre,
 * lever, retirer, geste rétroactif de grille — sont des méthodes du jalon 2 et
 * arrivent avec les lots qui livrent leurs routes (L20-20, L20-21, L20-25).
 *
 * **La garde d'ajout nomme la classe en premier argument** :
 * `can:create,App\Models\Frame,movie`. Le middleware `can` résout la policy
 * d'après ce premier argument ; écrite `can:create,movie`, la garde tomberait
 * sur {@see MoviePolicy::create()}, qui refuse toujours, et toute la voie TMDB
 * répondrait 403 (C9, V-17).
 *
 * Les routes d'image vivent sous `scopeBindings()` : une frame d'un autre film
 * répond 404 avant d'arriver ici, si bien qu'aucune méthode ne compare
 * `frame.movie_id` au film de l'URL.
 *
 * Découverte par convention `App\Models\Frame` → `App\Policies\FramePolicy`.
 */
class FramePolicy
{
    /**
     * Motif du refus de la voie capture quand elle est fermée : une CLÉ de
     * traduction du domaine `admin`, jamais un texte (§ 5.4). Ouverte par
     * défaut (D38 du 28/09), la voie ne se ferme que par une valeur
     * explicitement fausse de `catalog.curation.capture_enabled` ; la garde
     * de la route répond alors 403 avec ce motif, avant toute résolution de
     * requête.
     */
    public const string CAPTURE_DISABLED = 'admin.frame.capture.disabled';

    /**
     * L'aperçu des octets `game` et `master` (C9-bis).
     *
     * Tout état sauf `withdrawn` : une image retirée a vu ses fichiers
     * supprimés, ou va les voir supprimés, et ne se montre plus à personne.
     *
     * **Refus d'une image retirée en 404, jamais en 403** (§ 5.8) : l'aperçu
     * d'une frame `withdrawn` répond comme celui d'une frame d'un autre film,
     * et c'est cette méthode qui le dit. D'où le type `Response` et non le
     * `bool` de C9 — un booléen faux serait rendu en 403. Sous le seuil de
     * curation, refus ordinaire : un joueur n'arrive jamais ici, la porte
     * `role:curator` l'a arrêté avant.
     */
    public function view(User $user, Frame $frame): Response
    {
        if (! $user->role->atLeast(UserRole::Curator)) {
            return Response::deny();
        }

        return $frame->availability === ContentAvailability::Withdrawn
            ? Response::denyAsNotFound()
            : Response::allow();
    }

    /**
     * Ajouter une variante depuis un visuel TMDB, au film de l'URL.
     *
     * Jamais à un film `suspended` ni `withdrawn` : la banque d'un film sous
     * instruction ou retiré ne grossit pas.
     */
    public function create(User $user, Movie $movie): bool
    {
        return $user->role->atLeast(UserRole::Curator)
            && ! in_array($movie->availability, [ContentAvailability::Suspended, ContentAvailability::Withdrawn], true);
    }

    /**
     * Ajouter une variante par capture personnelle.
     *
     * Voie ouverte (le défaut, D38 du 28/09) : le seuil et les états de
     * {@see self::create()} — curateur au moins, jamais la banque d'un film
     * `suspended` ni `withdrawn`. Rejouée sous le verrou du film par
     * `AddFrame::fromCapture()`.
     *
     * **Refus motivé quand la voie est fermée** (§ 5.4) : une valeur
     * explicitement fausse de `catalog.curation.capture_enabled` fait répondre
     * la route 403 avant toute résolution de requête — seconde garde derrière
     * l'absence de bouton. Le motif est la CLÉ `admin.frame.capture.disabled`,
     * jamais un texte brut.
     */
    public function createFromCapture(User $user, Movie $movie): Response
    {
        if (! Config::boolean('catalog.curation.capture_enabled', true)) {
            return Response::deny(self::CAPTURE_DISABLED);
        }

        return $this->create($user, $movie) ? Response::allow() : Response::deny();
    }

    /**
     * Re-recadrer, relancer un traitement, changer le niveau.
     *
     * **Sans condition d'état**, et c'est voulu : une image en traitement, sans
     * dérivé, suspendue ou retirée se refuse par une erreur de validation
     * traduite (`admin.frame.recrop.not_ready`, `busy`, `locked` et leurs
     * équivalents), jamais par un 403 — un 403 dirait « ce geste n'est pas pour
     * vous » à un curateur qui a seulement cliqué au mauvais moment.
     */
    public function update(User $user, Frame $frame): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /**
     * Dépublier une image publiée, ou écarter une image jamais publiée.
     *
     * Jamais depuis `suspended` ni `withdrawn` : ces états appartiennent à
     * l'admin, et une dépublication de curation les écraserait.
     */
    public function unpublish(User $user, Frame $frame): bool
    {
        return $user->role->atLeast(UserRole::Curator)
            && in_array($frame->availability, [ContentAvailability::Draft, ContentAvailability::Published], true);
    }

    /**
     * Refus sans condition : une image n'est jamais supprimée. Écartée, elle
     * passe `unpublished` ; retirée, elle passe `withdrawn` et seuls ses
     * fichiers disparaissent — la ligne, sa source déclarée et ses revues
     * restent la preuve opposable.
     */
    public function delete(User $user, Frame $frame): bool
    {
        return false;
    }
}
