<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Изходяща опашка към канала (Telegram). Синхроните не пращат директно —
 * записват ред тук, а `channel:post` го изпраща.
 *
 * Разделянето не е украса: `f1:sync-results` върви на всеки час и препокрива
 * едни и същи резултати, така че без уникалния ключ по-долу каналът би
 * получавал един и същи резултат по веднъж на час, до безкрай.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_posts', function (Blueprint $table) {
            $table->id();

            // Дължините са изрично къси: четирите колони на уникалния индекс
            // по-долу трябва да се съберат в 3072 байта (InnoDB), а три
            // подразбиращи се varchar(255) в utf8mb4 сами по себе си са 3060.
            $table->string('channel', 20)->default('telegram');
            $table->string('kind', 40);
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');

            // Готовият HTML се пази при поставяне в опашката, а не се съставя
            // при изпращане: така в админа се вижда точно какво е тръгнало,
            // а по-късна промяна във форматирането не пренаписва историята.
            $table->text('body');

            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();

            // Отложено изпращане — резултатът от състезание чака стюардите.
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['channel', 'kind', 'subject_type', 'subject_id'],
                'channel_posts_subject_unique',
            );
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_posts');
    }
};
