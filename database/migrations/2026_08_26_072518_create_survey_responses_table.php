<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();  // 1..5; null при само скриване
            $table->string('would_recommend', 10)->nullable();  // App\Enums\WouldRecommend; null при само скриване
            $table->text('comment')->nullable();                // свободният текст — най-ценната част
            $table->string('source', 10)->default('prompt');    // prompt (картата) | page (/obratna-vrazka)
            $table->timestamp('submitted_at')->nullable();      // null = редът е само скриване на картата
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();                               // UTC
            // Проверката „питали ли сме скоро" търси последните редове на потребителя.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
