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
        Schema::create('near_miss', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movie')->cascadeOnDelete();
            $table->string('normalized_text', 200);
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedInteger('distinct_rounds')->default(1);
            $table->unsignedTinyInteger('best_distance');
            $table->date('first_seen_on');
            $table->date('last_seen_on');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index('movie_id');
            $table->unique(['movie_id', 'normalized_text'], 'near_miss_movie_text_uq');
            $table->index('last_seen_on', 'near_miss_last_seen_idx');
            $table->index(['movie_id', 'occurrences'], 'near_miss_movie_occ_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('near_miss');
    }
};
