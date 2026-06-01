<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('constructors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            // Jolpica constructorId (напр. "mclaren") — за маппване на sync response-и.
            // Повтаря се между сезоните, затова е индексиран, но не уникален.
            $table->string('jolpica_id')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->string('color_hex', 7)->nullable();
            $table->timestamps();

            // Jolpica constructorId е стабилен между сезони, затова slug е
            // уникален в рамките на сезон, а не глобално.
            $table->unique(['season_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('constructors');
    }
};
