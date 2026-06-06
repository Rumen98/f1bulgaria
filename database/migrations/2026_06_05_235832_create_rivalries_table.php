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
        Schema::create('rivalries', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('driver_one_canonical_id')->constrained('drivers_canonical')->cascadeOnDelete();
            $table->foreignId('driver_two_canonical_id')->constrained('drivers_canonical')->cascadeOnDelete();
            $table->unsignedSmallInteger('era_start_year')->nullable();
            $table->unsignedSmallInteger('era_end_year')->nullable();
            $table->string('title_bg');
            $table->text('description_bg')->nullable();
            $table->json('notable_moments')->nullable(); // [{year, description}]
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->index('is_featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rivalries');
    }
};
