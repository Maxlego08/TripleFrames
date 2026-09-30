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
        Schema::create('collection', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_id')->nullable();
            $table->string('name', 160);
            $table->timestamps();

            $table->unique('tmdb_id', 'collection_tmdb_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection');
    }
};
