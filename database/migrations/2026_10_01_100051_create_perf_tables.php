<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La mesure des performances et la chronologie technique des parties —
 * spec 10 § 7.11 (D47 du 01/10). Migration additive : aucune table de la
 * règle 12 touchée.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('perf_sample', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->string('name', 150);
            $table->string('method', 8)->nullable();
            $table->string('queue', 20)->nullable();
            $table->string('status', 12);
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('query_count');
            $table->unsignedInteger('query_ms');
            $table->unsignedInteger('memory_kb');
            $table->unsignedInteger('wait_ms')->nullable();
            $table->timestamp('recorded_at', 3);

            $table->index('recorded_at', 'perf_sample_recorded_idx');
            $table->index(['kind', 'name', 'recorded_at'], 'perf_sample_kind_name_idx');
        });

        Schema::create('perf_slow_query', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perf_sample_id')->constrained('perf_sample')->cascadeOnDelete();
            $table->string('sql_text', 2000);
            $table->char('sql_hash', 40);
            $table->unsignedInteger('duration_ms');
            $table->timestamp('recorded_at', 3);

            $table->index('recorded_at', 'perf_slow_query_recorded_idx');
            $table->index(['sql_hash', 'recorded_at'], 'perf_slow_query_hash_idx');
        });

        Schema::create('game_trace', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('game')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence_index')->nullable();
            $table->unsignedTinyInteger('tier_index')->nullable();
            $table->string('event', 40);
            $table->foreignId('player_id')->nullable()->constrained('player')->nullOnDelete();
            $table->timestamp('theoretical_at', 3)->nullable();
            $table->timestamp('recorded_at', 3);
            $table->integer('delay_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('query_count')->nullable();
            $table->json('details')->nullable();

            $table->index(['game_id', 'id'], 'game_trace_game_idx');
            $table->index('recorded_at', 'game_trace_recorded_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_trace');
        Schema::dropIfExists('perf_slow_query');
        Schema::dropIfExists('perf_sample');
    }
};
