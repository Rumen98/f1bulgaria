<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `newsletter_sends` дава истинска идемпотентност на бюлетинните команди:
     * часовите прозорци сами по себе си или изпускат състезания (старт малко
     * преди неделния час), или пращат дубликати при дублиран cron.
     *
     * `users.email_opt_out_at` е опт-аут за потребители с акаунт — те получават
     * дайджест/preview/пулс без абонамент, а всяко масово писмо трябва да има
     * работещ начин за спиране (Gmail/Yahoo bulk sender изисквания + GDPR).
     */
    public function up(): void
    {
        Schema::create('newsletter_sends', function (Blueprint $table) {
            $table->id();
            $table->string('mail_type'); // digest | pulse
            $table->foreignId('race_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['mail_type', 'sent_at']);
            $table->index(['mail_type', 'race_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_sends');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_opt_out_at');
        });
    }
};
