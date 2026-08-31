<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Кадри за „духа" на обиколката (x, z, heading на всеки 2 тика, base64
     * Float32) — записват се от сървърното преиграване при потвърждение и се
     * пазят САМО за най-добрата обиколка на потребителя на пистата (job-ът
     * чисти останалите). Оттам идват дуелите „Карай срещу…" в класацията.
     */
    public function up(): void
    {
        Schema::table('game_lap_records', function (Blueprint $table): void {
            $table->mediumText('ghost_frames')->nullable()->after('verified_lap_ms');
            $table->unsignedInteger('lap_ticks')->nullable()->after('ghost_frames');
        });
    }

    public function down(): void
    {
        Schema::table('game_lap_records', function (Blueprint $table): void {
            $table->dropColumn(['ghost_frames', 'lap_ticks']);
        });
    }
};
