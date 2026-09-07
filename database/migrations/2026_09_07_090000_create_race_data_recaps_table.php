<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Един ред на състезание: готовият „рекап с данни“ от OpenF1.
 *
 * Тук НЕ влизат сурови телеметрични редове. Синхронизацията дърпа ~13 заявки
 * (обиколки, стинтове, питове, дирекция, метео, решетка, шампионат) и записва
 * само ИЗВЕДЕНИТЕ структури: числата за текста и готовите за рисуване серии.
 * Така страницата се рендира от един ред, графиките работят и когато OpenF1
 * падне, а базата не расте с десетки хиляди реда на кръг.
 *
 * `race_id` е уникален: рекапът е един на състезание и повторното пускане на
 * командата обновява същия ред вместо да произведе втори.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_data_recaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->unique()->constrained()->cascadeOnDelete();

            // Ключът на състезателната сесия в OpenF1. Пази се, за да не се
            // мачва по дата при всяко пускане.
            $table->unsignedBigInteger('openf1_session_key')->nullable();
            // Ключът на квалификацията — стартовата решетка виси там, не при
            // състезанието (питане с ключа на състезанието връща празно).
            $table->unsignedBigInteger('openf1_quali_session_key')->nullable();

            // Изведените числа: топ скорост, най-бърза обиколка, стратегии,
            // печалба/загуба на позиции, шампионатна люлка.
            $table->json('facts')->nullable();
            // Готови за рисуване серии — по една на графика.
            $table->json('charts')->nullable();

            $table->string('headline')->nullable();
            $table->text('body_bg')->nullable();

            // Статията в новините, ако е публикувана. nullOnDelete: изтрита
            // статия не бива да събаря рекапа — графиките му живеят отделно.
            $table->foreignId('news_item_id')->nullable()
                ->constrained('team_news_items')->nullOnDelete();

            // Тих отказ е доказан риск на този проект, затова броячът и
            // последната грешка стоят в реда, а дневният отчет ги чете.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('generated_at')->nullable();

            $table->timestamps();

            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_data_recaps');
    }
};
