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
        Schema::create('game_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('game_sessions')->nullOnDelete();
            $table->string('submission_key', 80);
            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('controls_rating')->nullable();
            $table->unsignedTinyInteger('performance_rating')->nullable();
            $table->string('difficulty', 20)->nullable();
            $table->string('reason', 30)->nullable();
            $table->string('would_play_again', 10)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'submission_key']);
            $table->index('created_at');
            $table->index('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_feedback');
    }
};
