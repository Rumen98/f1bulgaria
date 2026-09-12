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
        Schema::create('game_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 32);
            $table->unsignedInteger('active_ms');
            $table->json('data')->nullable();
            $table->timestamp('received_at');
            $table->unique(['game_session_id', 'sequence']);
            $table->index(['type', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_session_events');
    }
};
