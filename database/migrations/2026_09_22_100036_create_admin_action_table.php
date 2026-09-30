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
        Schema::create('admin_action', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->string('action', 40);
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('takedown_request_id')->nullable()->constrained('takedown_request')->restrictOnDelete();
            $table->string('retention_class', 12);
            $table->string('reason', 500)->nullable();
            $table->unsignedTinyInteger('reports_count')->nullable();
            $table->string('role_before', 10)->nullable();
            $table->string('role_after', 10)->nullable();
            $table->timestamp('created_at');

            $table->index('actor_id');
            $table->index('takedown_request_id');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'admin_action_subject_idx');
            $table->index(['retention_class', 'created_at'], 'admin_action_retention_idx');
            $table->index(['actor_id', 'created_at'], 'admin_action_actor_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_action');
    }
};
