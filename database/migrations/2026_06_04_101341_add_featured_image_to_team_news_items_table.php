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
            // Метаданни за визуалния header (НЕ самото изображение):
            // {"type": "driver_photo|team_banner|circuit_outline|generic", "data": {...}}
            $table->json('featured_image')->nullable()->after('key_facts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_news_items', function (Blueprint $table) {
            $table->dropColumn('featured_image');
        });
    }
};
