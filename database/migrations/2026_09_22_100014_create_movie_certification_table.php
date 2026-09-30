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
        Schema::create('movie_certification', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movie')->cascadeOnDelete();
            $table->char('country', 2);
            $table->string('certification', 16);
            $table->date('released_on')->nullable();
            $table->boolean('is_restrictive')->default(false);
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['movie_id', 'country'], 'movie_cert_movie_country_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movie_certification');
    }
};
