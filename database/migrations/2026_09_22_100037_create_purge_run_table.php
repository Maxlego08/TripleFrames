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
        Schema::create('purge_run', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 32);
            $table->string('status', 12);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('rows_deleted')->default(0);
            $table->unsignedSmallInteger('batches')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['scope', 'started_at'], 'purge_run_scope_idx');
            $table->index('ran_at', 'purge_run_ran_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purge_run');
    }
};
