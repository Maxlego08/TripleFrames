<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `admin_action.details` — D41 du 30/09, spec 10 § 8.3.
 *
 * Additive seulement : un JSON NULLABLE, sans index, jamais lu dans une
 * clause `WHERE` ni `ORDER BY` — affiché seulement. Les lignes existantes le
 * gardent NULL. `admin_action` n'est pas une des cinq tables de la règle 12
 * (`movie`, `frame`, `movie_title`, `alias`, `frame_review`) : aucun
 * instantané préalable n'est exigé.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admin_action', function (Blueprint $table) {
            $table->json('details')->nullable()->after('role_after');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_action', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
