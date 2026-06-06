<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2 състезателни данни (синхронизирани от Wikipedia): кръгове → сесии
 * (спринт/главно) → резултати. circuit_jolpica_id съвпада с F1 пистите когато
 * е приложимо (за кръстосани връзки).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('f2_races', function (Blueprint $table) {
            $table->id();
            $table->foreignId('f2_season_id')->constrained('f2_seasons')->cascadeOnDelete();
            $table->string('circuit_jolpica_id')->nullable();   // съвпада с F1 circuits, ако има
            $table->string('location_name');                    // от заглавието на Wikipedia статията
            $table->unsignedSmallInteger('round');
            $table->dateTime('race_datetime_utc')->nullable();
            $table->string('wikipedia_url')->nullable();
            $table->string('slug');                             // {year}-{location} за URL
            $table->timestamps();

            $table->unique(['f2_season_id', 'round']);
            $table->index('circuit_jolpica_id');
            $table->index('slug');
        });

        Schema::create('f2_race_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('f2_race_id')->constrained('f2_races')->cascadeOnDelete();
            $table->string('session_type'); // feature_race | sprint_race
            $table->date('date')->nullable();
            $table->unsignedSmallInteger('laps')->nullable();
            $table->foreignId('pole_position_driver_id')->nullable()->constrained('f2_drivers')->nullOnDelete();
            $table->string('pole_position_time')->nullable();
            $table->foreignId('fastest_lap_driver_id')->nullable()->constrained('f2_drivers')->nullOnDelete();
            $table->string('fastest_lap_time')->nullable();
            $table->timestamps();

            $table->unique(['f2_race_id', 'session_type']);
        });

        Schema::create('f2_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('f2_race_session_id')->constrained('f2_race_sessions')->cascadeOnDelete();
            $table->foreignId('f2_driver_id')->constrained('f2_drivers')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->nullable(); // null = DNF/DSQ/NC
            $table->unsignedSmallInteger('grid_position')->nullable();
            $table->unsignedSmallInteger('laps_completed')->nullable();
            $table->string('time_or_gap')->nullable();
            $table->decimal('points', 5, 2)->default(0);
            $table->string('status')->nullable(); // Finished, Accident, +1 Lap, Ret, DSQ...
            $table->boolean('fastest_lap')->default(false);
            $table->timestamps();

            $table->unique(['f2_race_session_id', 'f2_driver_id']);
        });

        // Номер на болида (от Wikipedia) — нужен за резултатите/идентификацията.
        Schema::table('f2_drivers', function (Blueprint $table) {
            $table->unsignedSmallInteger('car_number')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('f2_drivers', function (Blueprint $table) {
            $table->dropColumn('car_number');
        });
        Schema::dropIfExists('f2_results');
        Schema::dropIfExists('f2_race_sessions');
        Schema::dropIfExists('f2_races');
    }
};
