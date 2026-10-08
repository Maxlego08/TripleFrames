<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fenêtre de comptage des signalements du pseudo (spec 10 § 7.1, spec 40
 * § 13.3 ; D66 du 07/10). Additive, hors règle 12 (`player` n'est pas une
 * table de curation).
 *
 * `nickname_reports_from` est posée à la LEVÉE du masquage, comme
 * `users.avatar_upload_reports_from` : sans elle, un siège démasqué serait
 * remasqué par le prochain signalement unique, les deux lignes anciennes
 * comptant encore. NULL = depuis toujours. Aucun index : elle se lit avec les
 * lignes `report` du siège.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->timestamp('nickname_reports_from', 3)->nullable()->after('nickname_masked_at');
        });
    }

    public function down(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->dropColumn('nickname_reports_from');
        });
    }
};
