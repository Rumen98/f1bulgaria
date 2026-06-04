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
        Schema::table('team_news_items', function (Blueprint $table) {
            // Наша оригинална бг статия по фактите (НЕ verbatim превод), + наш
            // анализ и ключови факти. Запълва се incrementally за approved items.
            $table->text('full_article_bg')->nullable()->after('summary_bg');
            $table->text('our_analysis_bg')->nullable()->after('full_article_bg');
            $table->json('key_facts')->nullable()->after('our_analysis_bg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_news_items', function (Blueprint $table) {
            $table->dropColumn(['full_article_bg', 'our_analysis_bg', 'key_facts']);
        });
    }
};
