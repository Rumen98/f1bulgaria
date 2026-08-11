<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Записани квалификационни обиколки на Хронометъра. Пазим ВСЯКА обиколка, не
     * само най-добрата на потребителя: лилавите сектори са най-бързото на всеки
     * поотделно и може да идват от различни обиколки — точно като в реалния
     * тайминг.
     */
    public function up(): void
    {
        Schema::create('game_lap_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('track_slug', 64);
            $table->unsignedInteger('lap_ms');
            $table->unsignedInteger('sector1_ms');
            $table->unsignedInteger('sector2_ms');
            $table->unsignedInteger('sector3_ms');
            $table->timestamps();

            // Класация по писта (най-бързи обиколки) и рекорди на потребител.
            $table->index(['track_slug', 'lap_ms']);
            $table->index(['track_slug', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_lap_records');
    }
};
