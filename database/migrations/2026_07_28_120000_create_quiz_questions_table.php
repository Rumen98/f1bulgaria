<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // стабилен идентификатор за seed-а
            $table->text('question');                       // въпросът (BG)
            $table->string('option_1');
            $table->string('option_2');
            $table->string('option_3');
            $table->string('option_4');
            $table->unsignedTinyInteger('correct_option');  // 1..4 — само сървърно
            $table->boolean('is_active')->default(true);
            $table->timestamps();                           // UTC

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
