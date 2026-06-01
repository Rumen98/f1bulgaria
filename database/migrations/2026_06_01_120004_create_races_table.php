<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('races', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            // Jolpica circuitId (напр. "monza") — за маппване на sync response-и.
            // Самото състезание Jolpica го идентифицира със season+round (виж unique по-долу).
            $table->string('jolpica_id')->nullable()->index();
            $table->unsignedTinyInteger('round');
            $table->string('name');
            $table->string('circuit');
            $table->string('country');
            // Всички времена се пазят в UTC, показват се в Europe/Sofia.
            $table->dateTime('race_datetime_utc')->nullable();
            $table->dateTime('qualifying_datetime_utc')->nullable();
            $table->dateTime('sprint_datetime_utc')->nullable();
            $table->boolean('has_sprint')->default(false);

            // Реални резултати, нужни за точкуване на прогнозите, но недостъпни
            // на ниво result в Ergast: pole се взима от квалификацията при sync,
            // а safety car изобщо липсва в API-то и се въвежда ръчно от админа
            // (null = неизвестно, не се точкува).
            $table->foreignId('pole_driver_id')->nullable()
                ->constrained('drivers')->nullOnDelete();
            $table->boolean('had_safety_car')->nullable();

            $table->timestamps();

            $table->unique(['season_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('races');
    }
};
