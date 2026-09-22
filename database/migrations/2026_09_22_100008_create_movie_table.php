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
        Schema::create('movie', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_id')->nullable();
            $table->string('import_source', 12)->default('discover');
            $table->foreignId('import_run_id')->nullable()->constrained('import_run')->nullOnDelete();
            $table->boolean('is_import_exception')->default(false);
            $table->boolean('exception_for_language')->default(false);
            $table->boolean('exception_for_vote_count')->default(false);
            $table->boolean('exception_for_release_year')->default(false);
            $table->string('title_original', 255);
            $table->string('title_original_latin', 255)->nullable();
            $table->string('original_language', 8);
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedInteger('vote_count')->default(0);
            $table->boolean('adult')->default(false);
            $table->foreignId('collection_id')->nullable()->constrained('collection')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('movie_group')->nullOnDelete();
            $table->string('availability', 12)->default('draft');
            $table->timestamp('availability_changed_at')->nullable();
            $table->string('availability_reason', 500)->nullable();
            $table->timestamp('first_published_at')->nullable();
            $table->string('content_flag', 16)->default('unrated_pending');
            $table->foreignId('content_verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('content_verified_at')->nullable();
            $table->string('movie_difficulty', 12)->nullable();
            $table->string('movie_difficulty_derived', 12)->nullable();
            $table->string('movie_difficulty_override', 12)->nullable();
            $table->foreignId('curated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('curation_active_seconds')->default(0);
            $table->timestamps();

            $table->unique('tmdb_id', 'movie_tmdb_uq');
            $table->index(['availability', 'content_flag', 'id'], 'movie_pool_idx');
            $table->index(['original_language', 'vote_count'], 'movie_decile_idx');
            $table->index('import_run_id');
            $table->index('collection_id');
            $table->index('group_id');
            $table->index('content_verified_by_id');
            $table->index('curated_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movie');
    }
};
