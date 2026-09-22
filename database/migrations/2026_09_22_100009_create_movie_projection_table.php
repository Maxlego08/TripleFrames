<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('movie_projection', function (Blueprint $table) {
            $table->foreignId('movie_id')->constrained('movie')->cascadeOnDelete();
            $table->unsignedTinyInteger('levels_mask')->default(0);
            $table->unsignedTinyInteger('levels_count')->default(0);
            $table->unsignedTinyInteger('level_1_variants')->default(0);
            $table->unsignedTinyInteger('level_2_variants')->default(0);
            $table->unsignedTinyInteger('level_3_variants')->default(0);
            $table->unsignedTinyInteger('level_4_variants')->default(0);
            $table->unsignedTinyInteger('level_5_variants')->default(0);
            $table->unsignedSmallInteger('variants_total')->default(0);
            $table->unsignedSmallInteger('title_locale_mask')->default(0);
            $table->unsignedTinyInteger('title_mask_version');
            $table->timestamp('recomputed_at');
            $table->timestamps();

            $table->primary('movie_id');
            $table->index(['levels_count', 'movie_id'], 'movie_projection_levels_idx');
            $table->index(
                ['title_mask_version', 'title_locale_mask', 'movie_id'],
                'movie_projection_qcm_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movie_projection');
    }
};
