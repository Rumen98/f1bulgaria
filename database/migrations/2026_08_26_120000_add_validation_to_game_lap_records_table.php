<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Сървърна валидация на обиколките: клиентът праща записа на входа
     * (input_trace), опашката го преиграва през същата симулация (Node,
     * scripts/game/validate-lap.mjs) и отбелязва изхода.
     *
     *   verify_status: null      — стар запис/без трейс (брои се, доверие)
     *                  pending   — чака преиграване
     *                  verified  — възпроизведено в толеранса
     *                  rejected  — НЕ се възпроизвежда → вън от класацията
     *                  error     — инфраструктурен проблем (брои се; не
     *                              наказваме играча заради счупен worker)
     */
    public function up(): void
    {
        Schema::table('game_lap_records', function (Blueprint $table) {
            $table->mediumText('input_trace')->nullable();
            $table->unsignedSmallInteger('sim_version')->nullable();
            $table->string('verify_status', 16)->nullable()->index();
            $table->unsignedInteger('verified_lap_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_lap_records', function (Blueprint $table) {
            $table->dropColumn(['input_trace', 'sim_version', 'verify_status', 'verified_lap_ms']);
        });
    }
};
