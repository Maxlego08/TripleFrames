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
        Schema::create('round_tier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('round')->cascadeOnDelete();
            $table->unsignedTinyInteger('tier_index');
            $table->foreignId('frame_id')->nullable()->constrained('frame')->nullOnDelete();
            $table->unsignedTinyInteger('frame_level');
            $table->char('serve_token', 32)->nullable();
            $table->foreignId('served_frame_id')->nullable()->constrained('frame')->nullOnDelete();
            $table->timestamp('served_at', 3)->nullable();
            $table->string('substitution_reason', 30)->nullable();
            $table->unsignedInteger('starts_at_offset_ms');
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('points');
            $table->timestamps(3);

            $table->unique(['round_id', 'tier_index'], 'round_tier_round_index_uq');
            $table->unique('serve_token', 'round_tier_serve_token_uq');
            $table->index('served_frame_id', 'round_tier_served_idx');
            $table->index('frame_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round_tier');
    }
};
