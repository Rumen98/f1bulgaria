<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Правилото на куиза става „един опит на въпрос на седмица": редът вече се
 * създава при ВСЕКИ отговор (answered_at), не само при верен. first_correct_at
 * остава NULL при грешен отговор — точката се брои само по него, а нов опит
 * идва чак когато въпросът се падне в бъдещ седмичен набор.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_question_user', function (Blueprint $table) {
            $table->timestamp('answered_at')->nullable()->after('quiz_question_id');
            $table->timestamp('first_correct_at')->nullable()->change();
        });

        // Съществуващите редове са само верни отговори — отговорът им е
        // моментът на покоряването.
        DB::table('quiz_question_user')
            ->whereNull('answered_at')
            ->update(['answered_at' => DB::raw('first_correct_at')]);
    }

    public function down(): void
    {
        Schema::table('quiz_question_user', function (Blueprint $table) {
            $table->dropColumn('answered_at');
        });
    }
};
