<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отпаднал пилот в класация от състезание, дошла по бързия път (OpenF1).
 *
 * В тренировка и квалификация няма отпадане — там липсата на позиция значи
 * само „без класирано време". В състезание разликата е съществена и постът
 * трябва да я покаже.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_results', function (Blueprint $table) {
            $table->boolean('dnf')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('session_results', function (Blueprint $table) {
            $table->dropColumn('dnf');
        });
    }
};
