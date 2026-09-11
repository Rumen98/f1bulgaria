<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Кой е пробвал играта: един ред на всяко натискане на „Карай" от
     * регистриран потребител. Записаните обиколки (game_lap_records) хващат
     * само завършилите чиста обиколка — играч, който потегля и се отказва
     * след два завоя, не оставя следа там, а за общност от 40 души това е
     * половината история. Append-only; чете се само в Filament.
     */
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('track_slug', 64);
            $table->string('device', 16);   // mobile | desktop
            $table->string('mode', 16);     // solo | race
            $table->timestamps();
            // Списъкът на играчите се агрегира по потребител (първо/последно каране, брой).
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sessions');
    }
};
