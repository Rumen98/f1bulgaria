<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Премахва double opt-in на бюлетина: confirmed_at изчезва, а токенът
     * остава само за отписване (unsubscribe_token). Съществуващите
     * непотвърдени абонати стават активни автоматично, защото scope-ът
     * `active` вече гледа само unsubscribed_at.
     *
     * Операциите са в отделни Schema::table извиквания заради SQLite
     * (DROP COLUMN отказва върху индексирана колона).
     */
    public function up(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->dropUnique(['confirmation_token']);
            $table->dropIndex(['confirmed_at']);
        });

        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->renameColumn('confirmation_token', 'unsubscribe_token');
        });

        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });

        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->unique('unsubscribe_token');
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->dropUnique(['unsubscribe_token']);
        });

        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->renameColumn('unsubscribe_token', 'confirmation_token');
        });

        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable();
        });

        // Backfill: старият scope active() изисква confirmed_at — без това
        // rollback би деактивирал целия списък. Оригиналните времена на
        // потвърждение са невъзстановими след drop-а на колоната.
        DB::table('newsletter_subscribers')
            ->whereNull('unsubscribed_at')
            ->update(['confirmed_at' => now()]);

        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->unique('confirmation_token');
            $table->index('confirmed_at');
        });
    }
};
