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
        Schema::create('game', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained('room')->restrictOnDelete();
            $table->string('mode', 20);
            $table->string('status', 20)->default('running');
            $table->string('input_difficulty', 10);
            $table->unsignedTinyInteger('rounds_count');
            $table->unsignedTinyInteger('frames_per_round');
            $table->unsignedTinyInteger('rounds_completed')->default(0);
            $table->string('draw_seed', 64);
            $table->unsignedSmallInteger('draw_pool_size');
            $table->unsignedSmallInteger('tier_grace_ms');
            $table->unsignedSmallInteger('preload_lead_ms');
            $table->unsignedSmallInteger('settings_version');
            $table->json('settings_snapshot');
            $table->unsignedSmallInteger('scoring_version');
            $table->unsignedSmallInteger('validation_version');
            $table->timestamp('started_at', 3);
            $table->timestamp('paused_at', 3)->nullable();
            $table->unsignedInteger('total_paused_ms')->default(0);
            $table->timestamp('ended_at', 3)->nullable();
            $table->timestamps(3);

            $table->index('ended_at', 'game_ended_idx');
            $table->index(['mode', 'ended_at'], 'game_mode_ended_idx');
            $table->index(['room_id', 'started_at'], 'game_room_started_idx');
            $table->index('started_at', 'game_started_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game');
    }
};
