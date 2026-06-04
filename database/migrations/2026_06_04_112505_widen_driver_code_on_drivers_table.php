<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ergast кодовете са 3 знака, но генерираните за исторически пилоти
        // могат да са до 4-5 (при колизии: LA+FI, LAS+номер).
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('driver_code', 8)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('driver_code', 3)->nullable()->change();
        });
    }
};
