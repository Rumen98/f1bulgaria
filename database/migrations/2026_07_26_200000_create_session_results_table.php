<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Класации от сесиите БЕЗ шампионатни точки: квалификация, спринт
 * квалификация и трите тренировки.
 *
 * ЗАЩО ОТДЕЛНА ТАБЛИЦА, а не нов session_type в `results`:
 * десетки заявки към `results` не филтрират по session_type — например
 * DriverStatsService сумира точки и брои старта/подиуми върху цялата таблица.
 * Ред за тренировка там би направил трето място във FP2 „подиум", а всяка
 * сесия — „старт", при това тихо. `results` остава само за сесиите с точки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->string('session_type', 20); // fp1|fp2|fp3|qualifying|sprint_quali
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('position')->nullable(); // null = без време/изключен
            $table->string('best_time', 20)->nullable();         // „1:23.456"
            $table->string('gap', 20)->nullable();               // „+0.312"

            // Отсечките на квалификацията. Празни за тренировките.
            $table->string('q1', 20)->nullable();
            $table->string('q2', 20)->nullable();
            $table->string('q3', 20)->nullable();

            // Откъде идва редът: Jolpica покрива квалификацията, OpenF1 —
            // тренировките и спринт квалификацията. Различни са по надеждност
            // и по лиценз, затова се вижда в данните.
            $table->string('source', 20)->default('jolpica');

            $table->timestamps();

            $table->unique(['race_id', 'session_type', 'driver_id'], 'session_results_unique');
            $table->index(['race_id', 'session_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_results');
    }
};
