<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'avatar téléversé d'un compte — spec 10 § 5.1, spec 40 § 11, D49 du 01/10.
 *
 * - `avatar_upload_path` : chemin RELATIF sur le disque `avatars`
 *   (`upload/<32 hex>.webp`). Unique : la route `avatar.show` retrouve le
 *   compte par ce chemin, et un nom aléatoire ne désigne jamais deux comptes.
 * - `avatar_upload_hidden_at` : masquage par seuil ou retrait par l'admin.
 *   Survit à la suppression du fichier et bloque le téléversement jusqu'à la
 *   levée.
 * - `avatar_upload_reports_from` : début de la fenêtre de comptage des
 *   signalements, posé au téléversement et à la levée.
 *
 * Additive seulement. `users` n'est pas une des cinq tables de la règle 12 :
 * aucun instantané préalable n'est exigé.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_upload_path', 64)->nullable()->after('avatar_provider_hidden_at');
            $table->timestamp('avatar_upload_hidden_at')->nullable()->after('avatar_upload_path');
            $table->timestamp('avatar_upload_reports_from')->nullable()->after('avatar_upload_hidden_at');

            $table->unique('avatar_upload_path', 'users_avatar_upload_path_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_avatar_upload_path_uq');
            $table->dropColumn(['avatar_upload_path', 'avatar_upload_hidden_at', 'avatar_upload_reports_from']);
        });
    }
};
