<?php

namespace App\Enums;

/**
 * Phase du drapeau de drainage de déploiement — spec 100 § 11.3, contrat
 * C18-bis (noms figés).
 *
 * `Draining` : `deploy:drain` attend la fin des parties en cours ; `Window` :
 * deux relevés nuls consécutifs ont ouvert la fenêtre libre où le hook peut
 * migrer et redémarrer. Le drapeau bloque les lancements **dans les deux
 * phases** (`DeployDrain::isDraining()`). La valeur brute n'est jamais
 * injectée dans une phrase : la console la rend par
 * `admin.console.deploy.phase.*`.
 */
enum DrainPhase: string
{
    case Draining = 'draining';

    case Window = 'window';
}
