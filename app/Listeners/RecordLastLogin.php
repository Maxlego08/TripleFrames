<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;

/**
 * Inscrit `users.last_login_at` à chaque connexion ABOUTIE (spec 10 § 5.1).
 *
 * La colonne pilote la dormance du jalon 2 (24 mois, `40`) et la « dernière
 * connexion » de l'écran de gestion des accès (spec 20 § 2.8) ; jusqu'à cet
 * écouteur, rien ne l'écrivait. `Login` part d'une connexion par mot de passe,
 * d'un défi de double authentification réussi et d'une reprise par le jeton
 * « se souvenir de moi » ; jamais de l'étape qui n'a fait que DEMANDER le
 * second facteur, ni de la garde `player-token`, qui n'émet aucun événement.
 *
 * **Écriture de base, sans passer par le modèle** : ni `updated_at` touché — la
 * colonne existe précisément parce que `updated_at` ne dit pas la même chose
 * (spec 10 § 5.1) —, ni garde `saving` déclenchée. L'instance en mémoire est
 * resynchronisée, pour que la requête courante ne la voie pas modifiée.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $now = Date::now()->toImmutable();

        User::query()->whereKey($user->id)->toBase()->update(['last_login_at' => $now]);

        $user->last_login_at = $now;
        $user->syncOriginalAttribute('last_login_at');
    }
}
