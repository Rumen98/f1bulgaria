<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Формула 2 модул — РЪЧНО въвеждани данни (Jolpica не покрива F2).
 * Опростена схема: сезон → отбори + пилоти със стендинг директно върху пилота.
 * Без авто-синхрон; съдържанието се поддържа през Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('f2_seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        Schema::create('f2_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('f2_season_id')->constrained('f2_seasons')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color_hex')->nullable();
            $table->timestamps();

            $table->unique(['f2_season_id', 'slug']);
        });

        Schema::create('f2_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('f2_season_id')->constrained('f2_seasons')->cascadeOnDelete();
            $table->foreignId('f2_team_id')->nullable()->constrained('f2_teams')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('slug');
            $table->string('country_code', 3)->nullable();
            $table->unsignedSmallInteger('position')->nullable(); // крайна позиция в класирането
            $table->float('points')->default(0);
            $table->boolean('is_champion')->default(false);
            $table->timestamps();

            $table->unique(['f2_season_id', 'slug']);
            $table->index('is_champion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('f2_drivers');
        Schema::dropIfExists('f2_teams');
        Schema::dropIfExists('f2_seasons');
    }
};
