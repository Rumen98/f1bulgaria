<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            // Jolpica result identifier (за идемпотентен sync на резултатите).
            $table->string('jolpica_id')->nullable()->index();
            // Nullable при DNF/DSQ. App-level: при dnf=true позицията е null/DSQ-class
            // (валидира се в ResultObserver / Service слоя, не на ниво схема).
            $table->unsignedTinyInteger('position')->nullable();
            // decimal(5,2) заради полу-точки в съкратени състезания + fastest lap бонус.
            $table->decimal('points', 5, 2)->default(0);
            $table->boolean('dnf')->default(false);
            $table->boolean('fastest_lap')->default(false);
            $table->unsignedTinyInteger('grid_position')->nullable();
            $table->timestamps();

            $table->unique(['race_id', 'driver_id']);
            // Често query: класиране на състезание подредено по позиция.
            $table->index(['race_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
