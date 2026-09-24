<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Frame;
use App\Models\Movie;
use App\Policies\FramePolicy;
use App\Support\Frames\FrameImageResponse;
use App\Support\Frames\FrameStoragePrefix;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'aperçu admin des octets d'une image — contrat C9-bis, spec 20 § 5.8,
 * ligne 5 de la matrice des capacités.
 *
 * **Seul second lecteur du disque `frames`**, avec `GET /f/{serveToken}`
 * (E10-61), et **distinct du service de jeu** : ces routes ne sont jamais
 * adressées par un `serve_token`, ne partagent jamais l'espace `/f/`, et ne
 * connaissent ni manche ni garde temporelle. Leur seule garde est le rôle,
 * posé par la route (`can:view,frame`, {@see FramePolicy::view()}) :
 *
 * - un joueur reçoit 403 à la porte (`role:curator`), avant toute résolution
 *   de modèle ;
 * - une frame d'un autre film répond 404 (`scopeBindings`) ;
 * - une frame `withdrawn` répond 404, jamais 403 (`denyAsNotFound`).
 *
 * **`game`** sert le dérivé, les mêmes octets que ceux servis aux joueurs :
 * c'est ce que la revue regarde et ce qu'elle hache. **`master`** sert la
 * source de re-cadrage, et c'est le **seul** point de l'application qui
 * lise le préfixe `master/` pour le rendre : aucune URL joueur n'y mène.
 *
 * **Les deux répondent 404 tant que `game_path` est NULL** : avant le premier
 * traitement réussi, `master_path` porte encore les octets ORIGINAUX
 * téléchargés, ni normalisés ni dépouillés de leurs métadonnées (§ 5.3), et
 * ils ne se montrent à personne.
 *
 * Chaque méthode nomme SON préfixe et SA colonne, sans aide partagée : c'est
 * ce qui permet à `FrameImagePreviewTest` de vérifier, action par action,
 * qu'une seule route de l'application lit `master/`.
 *
 * Le corps et les en-têtes viennent tous de {@see FrameImageResponse}, et
 * d'elle seule.
 */
class FrameImageController extends Controller
{
    /**
     * Le dérivé servi, 1280 × 720 paddé : `admin.catalog.frames.game`.
     *
     * `$movie` n'est lu par personne ici : sa présence dans la signature est
     * ce qui le résout en modèle, et ce qui permet à `scopeBindings` de
     * chercher la frame parmi SES images.
     */
    public function game(Movie $movie, Frame $frame): Response
    {
        if ($frame->game_path === null) {
            return FrameImageResponse::notFound();
        }

        return FrameImageResponse::make(FrameStoragePrefix::Game, $frame->game_path);
    }

    /**
     * La source de re-cadrage, 1920 de large : `admin.catalog.frames.master`.
     */
    public function master(Movie $movie, Frame $frame): Response
    {
        if ($frame->game_path === null || $frame->master_path === null) {
            return FrameImageResponse::notFound();
        }

        return FrameImageResponse::make(FrameStoragePrefix::Master, $frame->master_path);
    }
}
