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
        Schema::create('movie_theme', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movie')->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained('theme')->cascadeOnDelete();
            $table->boolean('is_auto')->default(false);
            $table->string('manual_state', 8)->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['movie_id', 'theme_id'], 'movie_theme_uq');
            $table->index(['theme_id', 'is_active', 'movie_id'], 'movie_theme_pool_idx');
            $table->index('assigned_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movie_theme');
    }
};
