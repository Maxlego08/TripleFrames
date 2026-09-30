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
        Schema::create('movie_tmdb_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movie')->cascadeOnDelete();
            $table->string('tag_kind', 12);
            $table->unsignedInteger('tmdb_tag_id');
            $table->timestamp('created_at');

            $table->unique(['movie_id', 'tag_kind', 'tmdb_tag_id'], 'movie_tmdb_tag_uq');
            $table->index(['tag_kind', 'tmdb_tag_id', 'movie_id'], 'movie_tmdb_tag_rule_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movie_tmdb_tag');
    }
};
