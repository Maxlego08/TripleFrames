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
        Schema::create('takedown_request', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 12);
            $table->string('status', 20)->default('received');
            $table->string('requester_name', 120);
            $table->string('requester_email', 255);
            $table->string('requester_capacity', 20);
            $table->string('locale', 5);
            $table->text('claimed_scope')->nullable();
            $table->string('scope_kind', 10)->nullable();
            $table->text('body');
            $table->foreignId('target_movie_id')->nullable()->constrained('movie')->restrictOnDelete();
            $table->foreignId('target_frame_id')->nullable()->constrained('frame')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('decision', 20)->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('requester_anonymized_at')->nullable();
            $table->timestamps();

            $table->index('target_frame_id');
            $table->index('decided_by_id');
            $table->unique('reference', 'takedown_reference_uq');
            $table->index(['status', 'received_at'], 'takedown_status_idx');
            $table->index('target_movie_id', 'takedown_movie_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('takedown_request');
    }
};
