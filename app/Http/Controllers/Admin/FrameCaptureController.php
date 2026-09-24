<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Policies\FramePolicy;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Ajouter une variante par capture personnelle — spec 20 § 5.4, ligne 14 de
 * la matrice des capacités. **Au jalon 1, cette route refuse, et ne fait
 * rien d'autre.**
 *
 * Sans arbitrage de la licéité de l'acte de capture (décision 7), seule la
 * voie TMDB est ouverte. **Double garde** : l'interface ne montre aucun
 * bouton de téléversement ni de collage (`admin.frame.capture.disabled_notice`
 * explique l'attente), et le serveur refuse quand même —
 * `can:createFromCapture,App\Models\Frame,movie` répond 403 avec le motif
 * {@see FramePolicy::CAPTURE_DISABLED} **avant toute résolution de requête**,
 * tant que `catalog.curation.capture_enabled` est faux.
 *
 * La branche qui accepte un fichier — `FrameCaptureStoreRequest`,
 * `AddFrame::fromCapture()` — n'existe pas au jalon 1 : elle arrive avec le
 * lot L20-33, après l'arbitrage. D'ici là, un drapeau levé par erreur n'ouvre
 * rien : cette action oppose le même refus motivé, et aucun octet n'est lu.
 */
class FrameCaptureController extends Controller
{
    /**
     * Refuse, toujours, au jalon 1.
     *
     * `$movie` n'est lu par personne ici : sa présence dans la signature est
     * ce qui le résout en modèle, et c'est ce modèle que la garde
     * `can:createFromCapture` passe à {@see FramePolicy::createFromCapture()}.
     *
     * @throws AuthorizationException
     */
    public function store(Movie $movie): never
    {
        throw new AuthorizationException(FramePolicy::CAPTURE_DISABLED);
    }
}
