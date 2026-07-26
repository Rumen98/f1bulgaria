<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Полета за синхрона от официалния F2 API.
 *
 * Ключовете от API-то (`meetingKey`, `driverReference`, `teamKey`) заместват
 * досегашното съпоставяне по име и по позиция в календара. Това не е удобство:
 * при разместен календар или преименуван кръг старата евристика в
 * F2WikipediaSync (`locationFromTitle` + номер по ред) създава дубликат, а
 * стабилният ключ прави upsert-а верен по определение.
 *
 * Старите колони остават — сезоните преди 2026 г. идват от Wikipedia, която
 * тези ключове няма.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('f2_races', function (Blueprint $table) {
            $table->string('meeting_key', 20)->nullable()->after('f2_season_id');
            $table->string('country_name')->nullable()->after('location_name');

            $table->index('meeting_key');
        });

        Schema::table('f2_race_sessions', function (Blueprint $table) {
            // `date` е само дата (наследство от Wikipedia). За канала трябва
            // точен час, за да знаем кога сесията е свършила.
            $table->dateTime('scheduled_at_utc')->nullable()->after('date');
            $table->dateTime('ends_at_utc')->nullable()->after('scheduled_at_utc');
            $table->string('state', 20)->nullable()->after('ends_at_utc');
            // Provisional | Final — класацията се променя от стюардите.
            $table->string('version', 20)->nullable()->after('state');

            $table->index(['state', 'ends_at_utc']);
        });

        Schema::table('f2_drivers', function (Blueprint $table) {
            $table->string('driver_reference', 30)->nullable()->after('slug');
            $table->string('tla', 5)->nullable()->after('driver_reference');

            $table->index('driver_reference');
        });

        Schema::table('f2_teams', function (Blueprint $table) {
            $table->string('team_key', 20)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('f2_teams', function (Blueprint $table) {
            $table->dropColumn('team_key');
        });

        Schema::table('f2_drivers', function (Blueprint $table) {
            $table->dropIndex(['driver_reference']);
            $table->dropColumn(['driver_reference', 'tla']);
        });

        Schema::table('f2_race_sessions', function (Blueprint $table) {
            $table->dropIndex(['state', 'ends_at_utc']);
            $table->dropColumn(['scheduled_at_utc', 'ends_at_utc', 'state', 'version']);
        });

        Schema::table('f2_races', function (Blueprint $table) {
            $table->dropIndex(['meeting_key']);
            $table->dropColumn(['meeting_key', 'country_name']);
        });
    }
};
