<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_news_item_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            // Soft delete = модерация със следа: собственикът трие своето,
            // админът скрива/възстановява от Filament.
            $table->softDeletes();
            $table->timestamps();

            // Често query: коментарите на статия по ред на публикуване.
            $table->index(['team_news_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
