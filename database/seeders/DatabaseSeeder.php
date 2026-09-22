<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Le point d'entrée de `php artisan db:seed`, et la seule décision qu'il prend :
 * **ce qui tourne partout, et ce qui ne tourne qu'en `local` et en `testing`.**
 *
 * L'ordre est un ordre de dépendance, pas une préférence :
 *
 * 1. **Les comptes** ({@see DemoAccountsSeeder}) — le curateur porte
 *    `movie.content_verified_by_id` et les estampilles de `frame_review`,
 *    l'administrateur porte les lignes `admin_action` permanentes. Le catalogue
 *    n'a aucune preuve nominative à citer tant qu'ils n'existent pas (§ 13.3,
 *    exigence 5).
 * 2. **Les données du site** ({@see PlatformDataSeeder}) — les quatre presets et
 *    les thèmes de base. Sans elles, aucun salon ne peut être créé : ce n'est pas
 *    de la démonstration, et c'est le **seul** seeder joué en production.
 * 3. **Le catalogue de démonstration** ({@see DemoCatalogueSeeder}) — les films
 *    jouables à tout `N` des bornes de salon, leurs images réelles et leurs
 *    appartenances aux thèmes posés en 2.
 *
 * `WithoutModelEvents` n'est **pas** utilisé, et c'est délibéré : le trait
 * désactive les événements de modèle pour tout l'appel, or les gardes de ce
 * schéma en dépendent — `AdminAction::creating` dérive `retention_class` de
 * l'action, et `FrameReview::saving` ferme la table en ajout seul. Un seeder qui
 * les éteindrait écrirait des lignes qu'aucune écriture applicative ne pourrait
 * produire, et la démonstration cesserait de démontrer quoi que ce soit.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // **Liste blanche, jamais liste noire.** `isProduction()` teste exactement
        // `APP_ENV === 'production'`, et `composer setup` — le chemin d'installation
        // documenté, celui que joue la CI — recopie `.env.example`, qui pose
        // `APP_ENV=local`. Une garde adossée au seul nom `production` laisserait donc
        // un `db:seed` créer `admin@tripleframes.test` au mot de passe connu, rôle
        // `admin` et adresse déjà vérifiée, sur une machine servie provisionnée par
        // cette procédure. Le panneau d'administration est précisément la porte qui
        // donne sa valeur à ce compte.
        $demo = app()->environment(['local', 'testing']);

        if ($demo) {
            $this->call(DemoAccountsSeeder::class);
        }

        $this->call(PlatformDataSeeder::class);

        if ($demo) {
            $this->call(DemoCatalogueSeeder::class);
        }
    }
}
