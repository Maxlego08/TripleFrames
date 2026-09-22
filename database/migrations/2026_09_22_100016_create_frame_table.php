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
        Schema::create('frame', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movie')->restrictOnDelete();
            $table->unsignedTinyInteger('frame_level');
            $table->string('availability', 12)->default('draft');
            $table->timestamp('availability_changed_at')->nullable();
            $table->timestamp('first_published_at')->nullable();
            $table->unsignedBigInteger('published_review_id')->nullable();
            $table->string('processing_state', 12)->default('pending');
            $table->string('processing_error', 120)->nullable();
            $table->string('source_kind', 8);
            $table->string('tmdb_file_path', 255)->nullable();
            $table->unsignedInteger('source_timecode_ms')->nullable();
            $table->char('source_hash', 64);
            $table->char('published_hash', 64)->nullable();
            $table->unsignedSmallInteger('crop_x');
            $table->unsignedSmallInteger('crop_y');
            $table->unsignedSmallInteger('crop_width');
            $table->unsignedSmallInteger('crop_height');
            $table->string('game_path', 64)->nullable();
            $table->string('master_path', 64)->nullable();
            $table->unsignedInteger('game_bytes')->nullable();
            $table->unsignedSmallInteger('game_width')->nullable();
            $table->unsignedSmallInteger('game_height')->nullable();
            $table->timestamp('files_deleted_at')->nullable();
            $table->string('files_deleted_error', 120)->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedTinyInteger('review_grid_version')->nullable();
            $table->unsignedSmallInteger('crop_seconds')->nullable();
            $table->timestamps();

            $table->unique('game_path', 'frame_game_path_uq');
            $table->unique('master_path', 'frame_master_path_uq');

            $table->index(['movie_id', 'frame_level'], 'frame_movie_level_idx');
            $table->index(['review_grid_version', 'availability'], 'frame_grid_version_idx');
            $table->index(['source_kind', 'availability'], 'frame_source_kind_idx');
            $table->index('processing_state', 'frame_processing_idx');
            $table->index(['availability', 'movie_id'], 'frame_availability_idx');
            $table->index(['availability', 'files_deleted_at'], 'frame_withdrawn_files_idx');
            $table->index('uploaded_by_id', 'frame_uploaded_by_idx');
            $table->index('published_review_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('frame');
    }
};
