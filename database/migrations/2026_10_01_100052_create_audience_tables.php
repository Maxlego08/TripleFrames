<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La mesure d'audience — spec 10 § 7.12 (D48 du 01/10). Des compteurs
 * quotidiens et une présence de dix minutes : aucune adresse, aucun
 * identifiant durable. Migration additive, hors règle 12.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audience_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('metric', 20);
            $table->string('dimension', 150)->default('');
            $table->unsignedInteger('total')->default(0);

            $table->unique(['day', 'metric', 'dimension'], 'audience_daily_key_uq');
            $table->index('day', 'audience_daily_day_idx');
        });

        Schema::create('audience_presence', function (Blueprint $table) {
            $table->char('visitor_hash', 16)->primary();
            $table->string('route', 150);
            $table->timestamp('last_seen_at', 3);

            $table->index('last_seen_at', 'audience_presence_seen_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audience_presence');
        Schema::dropIfExists('audience_daily');
    }
};
