<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les thèmes choisis au collage — spec 10 § 9.1, spec 20 § 3.3, D43 du 01/10.
 *
 * - `added_theme_ids` : identifiants de `theme` joints par des virgules,
 *   **jamais interrogés** (comme `filter_languages`), écrits à l'ouverture du
 *   collage et jamais modifiés, relus par la reprise. NULL hors collage et
 *   pour un collage sans thème.
 * - `total_themes_applied` / `total_themes_kept_removed` : les deux compteurs
 *   du rapport du balayage (critique C7) — couples (film, thème) qui ont reçu
 *   l'exception `added`, et couples laissés hors du thème parce qu'un curateur
 *   les en avait retirés. Accumulés en mémoire et enregistrés avec les quatre
 *   autres compteurs, donc repris avec eux.
 *
 * Additive seulement. `import_run` n'est pas une des cinq tables de la
 * règle 12 : aucun instantané préalable n'est exigé.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('import_run', function (Blueprint $table) {
            $table->string('added_theme_ids', 255)->nullable()->after('is_widened');
            $table->unsignedInteger('total_themes_applied')->default(0)->after('total_refused_content');
            $table->unsignedInteger('total_themes_kept_removed')->default(0)->after('total_themes_applied');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_run', function (Blueprint $table) {
            $table->dropColumn(['added_theme_ids', 'total_themes_applied', 'total_themes_kept_removed']);
        });
    }
};
