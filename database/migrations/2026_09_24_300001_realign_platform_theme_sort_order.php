<?php

use Database\Seeders\PlatformDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Réalignement unique de `theme.sort_order` sur les blocs de famille
 * (spec 30 § 12.5, lot L30-7) — migration de DONNÉES, aucune colonne.
 *
 * L'ancien `PlatformDataSeeder` posait un ordre séquentiel (1 à 27), réécrit à
 * chaque passage. La version scindée ne réécrit jamais une ligne présente : sans
 * ce réalignement, une base amorcée avant elle garderait ces valeurs, et tout
 * thème livré ensuite (`bloc × 100 + rang`) s'afficherait après tous les autres.
 *
 * Pose `PlatformDataSeeder::sortOrderFor(key)` sur chaque thème dont la clé est
 * livrée par le seeder **et** dont `sort_order` est encore sous le premier bloc :
 * un thème créé en back-office (clé non livrée) et une valeur déjà réalignée ne
 * sont jamais touchés. D'où l'idempotence — un second passage ne trouve plus
 * rien à écrire — et l'absence de toute édition effacée : l'écran d'ordre du
 * back-office n'existe pas avant le jalon 2.
 *
 * Écriture par le constructeur de requêtes, sans modèle : ce réalignement n'est
 * pas une édition, `updated_at` n'est pas touché. Aucune table de la règle 12
 * (`movie`, `frame`, `movie_title`, `alias`, `frame_review`) n'est concernée ;
 * l'étape 4 du hook prend de toute façon l'instantané dès qu'une migration est
 * en attente (contrat C18-bis).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (PlatformDataSeeder::deliveredThemeKeys() as $key) {
            DB::table('theme')
                ->where('key', $key)
                ->where('sort_order', '<', PlatformDataSeeder::SORT_ORDER_BLOCK)
                ->update(['sort_order' => PlatformDataSeeder::sortOrderFor($key)]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Aucun retour : l'ancien ordre séquentiel n'était qu'un artefact de
     * l'ancien seeder, et le rétablir replacerait les thèmes livrés ensuite
     * derrière tous les autres.
     */
    public function down(): void
    {
        //
    }
};
