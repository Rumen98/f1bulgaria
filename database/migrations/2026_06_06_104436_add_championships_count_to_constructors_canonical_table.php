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
        Schema::table('constructors_canonical', function (Blueprint $table) {
            // Ръчно поддържано в Filament — точковите системи са се менили, затова
            // показваме титли вместо all-time точки.
            $table->unsignedSmallInteger('championships_count')->default(0)->after('total_races');
        });
    }

    public function down(): void
    {
        Schema::table('constructors_canonical', function (Blueprint $table) {
            $table->dropColumn('championships_count');
        });
    }
};
