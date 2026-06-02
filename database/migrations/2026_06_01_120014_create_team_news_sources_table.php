<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_news_sources', function (Blueprint $table) {
            $table->id();
            // null = глобален източник, важи за всички отбори.
            $table->foreignId('constructor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('feed_url');
            $table->enum('type', ['rss', 'atom', 'youtube_rss'])->default('rss');
            $table->enum('language', ['en', 'bg', 'it', 'de'])->default('en');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_fetched_at')->nullable();
            $table->timestamps();

            $table->index(['constructor_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_news_sources');
    }
};
