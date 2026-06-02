<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_news_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('team_news_sources')->cascadeOnDelete();
            // Попълва се при класификация (по-късна стъпка).
            $table->foreignId('constructor_id')->nullable()->constrained()->nullOnDelete();

            // Дедупликация: по URL (твърдо) и по RSS GUID (меко).
            $table->string('external_url')->unique();
            $table->string('external_guid')->nullable()->index();

            $table->string('title_original');
            // title_bg / summary_bg се попълват от LLM по-късно.
            $table->string('title_bg')->nullable();
            $table->text('summary_bg')->nullable();
            // Кратък откъс от оригинала за вътрешна обработка (вход за LLM).
            $table->text('content_snippet')->nullable();

            $table->dateTime('published_at');
            $table->enum('classification', ['race', 'driver', 'technical', 'rumor', 'business', 'other'])->nullable();
            // 1-5, попълва се от LLM.
            $table->unsignedTinyInteger('importance_score')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'auto_published'])->default('pending');
            $table->timestamps();

            $table->index(['constructor_id', 'status', 'published_at']);
            $table->index(['source_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_news_items');
    }
};
