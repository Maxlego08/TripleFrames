<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La copie locale de la photo du fournisseur — spec 10 § 5.1, spec 40 § 12.6,
 * D51 du 01/10.
 *
 * - `avatar_provider_source` : le fournisseur d'origine de la copie ; délier
 *   CE fournisseur supprime le fichier (40 § 12.5).
 * - `avatar_provider_reports_from` : fenêtre de comptage des signalements,
 *   posée à la copie et à la levée, comme `avatar_upload_reports_from`.
 *
 * Additive seulement, hors des cinq tables de la règle 12.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_provider_source', 20)->nullable()->after('avatar_provider_hidden_at');
            $table->timestamp('avatar_provider_reports_from')->nullable()->after('avatar_provider_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_provider_source', 'avatar_provider_reports_from']);
        });
    }
};
