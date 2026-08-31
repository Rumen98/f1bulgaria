<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Видяна ли е значката от притежателя ѝ. NULL = още не — при следващото
 * зареждане на сайта потребителят получава поздравление (BadgeAwardToast).
 *
 * Съществуващите редове остават NULL нарочно: наваксаните с backfill значки
 * трябва да бъдат обявени при първото влизане, не подминати.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badge_user', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable()->after('awarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('badge_user', function (Blueprint $table) {
            $table->dropColumn('seen_at');
        });
    }
};
