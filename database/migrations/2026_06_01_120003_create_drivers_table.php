<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            // Пилот може да остане без конструктор при междусезонни корекции.
            $table->foreignId('constructor_id')->nullable()->constrained()->nullOnDelete();
            // Jolpica driverId (напр. "hamilton") — за маппване на sync response-и.
            // Повтаря се между сезоните, затова е индексиран, но не уникален.
            $table->string('jolpica_id')->nullable()->index();
            // 3-буквен код (напр. "HAM") — стабилен между сезоните, ползва се за
            // групиране на all-time статистика (Hamilton 2024 + 2025 → един пилот).
            $table->string('driver_code', 3)->nullable()->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('slug');
            $table->unsignedSmallInteger('permanent_number')->nullable();
            $table->char('country_code', 3)->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
