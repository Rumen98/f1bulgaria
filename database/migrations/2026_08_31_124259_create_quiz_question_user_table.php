<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Покорени“ въпроси: един ред при ПЪРВИЯ верен отговор на даден въпрос.
 *
 * Точките на куиза се броят оттук, а не като сбор от опитите. Причината е
 * анти-фарм: верните отговори се разкриват след submit, така че сборът от
 * опити би се качвал безкрайно с повтаряне на същите въпроси. Уникалният
 * ключ (user_id, quiz_question_id) прави точката еднократна — а всеки нов
 * въпрос в базата вдига тавана и дава причина човек да се върне.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_question_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_question_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_correct_at');
            $table->timestamps();

            $table->unique(['user_id', 'quiz_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_question_user');
    }
};
