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
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->uuid('client_id')->nullable();
            $table->string('status', 16)->default('legacy');
            $table->json('context')->nullable();
            $table->unsignedInteger('active_ms')->default(0);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('lap_count')->default(0);
            $table->unsignedInteger('valid_lap_count')->default(0);
            $table->unsignedInteger('invalid_lap_count')->default(0);
            $table->float('max_progress')->default(0);
            $table->float('max_speed')->default(0);
            $table->unsignedTinyInteger('last_sector')->nullable();
            $table->unique(['user_id', 'client_id']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_id']);
            $table->dropIndex(['status', 'last_seen_at']);
            $table->dropColumn(['client_id', 'status', 'context', 'active_ms', 'last_sequence', 'last_seen_at', 'ended_at', 'lap_count', 'valid_lap_count', 'invalid_lap_count', 'max_progress', 'max_speed', 'last_sector']);
        });
    }
};
