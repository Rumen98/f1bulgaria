<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_lap_records', function (Blueprint $table): void {
            $table->index(
                ['sim_version', 'track_slug', 'lap_ms'],
                'game_laps_sim_track_lap_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('game_lap_records', function (Blueprint $table): void {
            $table->dropIndex('game_laps_sim_track_lap_index');
        });
    }
};
