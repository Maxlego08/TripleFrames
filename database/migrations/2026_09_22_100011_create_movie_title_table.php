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
        Schema::create('movie_title', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movie')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('title', 255);
            $table->string('origin', 12);
            $table->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['movie_id', 'locale'], 'movie_title_movie_locale_uq');
            $table->index('edited_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movie_title');
    }
};
