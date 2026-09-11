<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_news_items', function (Blueprint $table) {
            // Новина за Никола Цолов. Разпознава се детерминистично по име
            // при вземането, не от LLM: за конкретен човек точното съвпадение
            // няма фалшиви попадения, не струва нищо и работи и когато
            // доставчикът на модела е паднал.
            $table->boolean('is_tsolov')->default(false)->after('constructor_id');

            // Дали статията е за Формула 1. Досега това беше преходна
            // стойност — не-Ф1 се отхвърляше и въпросът отпадаше. С кътa на
            // Цолов вече публикуваме и Ф2 новини за него, затова трябва да
            // помним кои НЕ бива да влизат в главната емисия.
            //
            // default true: всичко публикувано до момента е минало през
            // проверката „това Ф1 ли е“, иначе нямаше да е публикувано.
            $table->boolean('is_f1_related')->default(true)->after('is_tsolov');

            $table->index(['is_tsolov', 'status', 'published_at']);
            $table->index(['is_f1_related', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('team_news_items', function (Blueprint $table) {
            $table->dropIndex(['is_tsolov', 'status', 'published_at']);
            $table->dropIndex(['is_f1_related', 'status', 'published_at']);
            $table->dropColumn(['is_tsolov', 'is_f1_related']);
        });
    }
};
