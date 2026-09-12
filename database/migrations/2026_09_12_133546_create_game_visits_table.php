<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Кой влиза в играта — включително гостите, които няма как да влязат в
     * game_sessions (там трябва акаунт). Един ред на отваряне на /game (view)
     * и на гост-„Карай" (start). Посетителят е ДНЕВЕН хеш от IP + браузър +
     * ключа на приложението: брои уникални хора в рамките на деня, не може да
     * се обърне обратно и не свързва дните — без бисквитка и без съгласие.
     */
    public function up(): void
    {
        Schema::create('game_visits', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_key', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 8);            // view | start
            $table->string('device', 16);         // mobile | desktop
            $table->string('track_slug', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['created_at', 'kind']);
            $table->index(['visitor_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_visits');
    }
};
