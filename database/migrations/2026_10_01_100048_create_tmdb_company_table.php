<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tmdb_company` — le nom TMDB non localisé d'une société de production, pour
 * le back-office seul (spec 10 § 3.6 bis, D43 du 01/10).
 *
 * Aucune FK depuis `movie_tmdb_tag` : `tmdb_tag_id` reste l'identifiant brut,
 * une étiquette existe sans nom. Table de catalogue hors des cinq tables de la
 * règle 12.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tmdb_company', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_id');
            $table->string('name', 160);
            $table->timestamps();

            $table->unique('tmdb_id', 'tmdb_company_tmdb_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tmdb_company');
    }
};
