<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();

            $table->foreignId('p1_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('p2_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('p3_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            // Pole се отнася за ГЛАВНАТА квалификация. При sprint уикенд sprint pole
            // (sprint_quali) се игнорира за MVP — точкува се само главната квалификация.
            $table->foreignId('pole_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('fastest_lap_driver_id')->nullable()->constrained('drivers')->nullOnDelete();

            $table->unsignedTinyInteger('dnf_count')->default(0);
            $table->boolean('safety_car')->default(false);
            // Запълва се от f1:lock-predictions 5 мин преди квалификацията.
            $table->dateTime('locked_at')->nullable();
            $table->timestamps();

            // Една прогноза на потребител за състезание.
            $table->unique(['user_id', 'race_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
