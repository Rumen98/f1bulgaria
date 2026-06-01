<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблицата е кръстена `race_sessions`, а НЕ `sessions`, защото Laravel
     * вече ползва таблица `sessions` за драйвера на сесиите (виж
     * 0001_01_01_000000_create_users_table.php). Моделът ще е RaceSession.
     */
    public function up(): void
    {
        Schema::create('race_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'fp1',
                'fp2',
                'fp3',
                'qualifying',
                'sprint_quali',
                'sprint',
                'race',
            ]);
            $table->dateTime('scheduled_at_utc')->nullable();
            $table->timestamps();

            $table->unique(['race_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_sessions');
    }
};
