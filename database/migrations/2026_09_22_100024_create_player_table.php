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
        Schema::create('player', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 12);
            $table->foreignId('room_id')->nullable()->constrained('room')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nickname', 20)->nullable();
            $table->string('nickname_normalized', 20)->nullable();
            $table->timestamp('nickname_masked_at', 3)->nullable();
            $table->string('player_token_hash', 64)->nullable();
            $table->string('active_seat_token', 64)->nullable();
            $table->string('locale', 5);
            $table->string('avatar_kind', 20)->nullable();
            $table->string('avatar_preset', 40)->nullable();
            $table->timestamp('joined_at', 3);
            $table->string('connection_state', 20)->default('connected');
            $table->timestamp('last_seen_at', 3);
            $table->timestamp('disconnected_at', 3)->nullable();
            $table->timestamp('left_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique('public_id', 'player_public_id_uq');
            $table->unique(['room_id', 'nickname_normalized'], 'player_room_nickname_uq');
            $table->unique(['room_id', 'player_token_hash'], 'player_room_token_uq');
            $table->index('user_id', 'player_user_idx');
            $table->index(['room_id', 'connection_state'], 'player_room_state_idx');
            $table->index(['room_id', 'last_seen_at'], 'player_solo_expiry_idx');
            $table->index(['player_token_hash', 'room_id'], 'player_token_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player');
    }
};
