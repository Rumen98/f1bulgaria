<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_scores', function (Blueprint $table) {
            $table->id();
            // Едно към едно с predictions.
            $table->foreignId('prediction_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('points')->default(0);
            // Разбивка по категории (топ3, pole, fastest lap, dnf, safety car).
            $table->json('breakdown_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_scores');
    }
};
