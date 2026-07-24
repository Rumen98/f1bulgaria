<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Одит лог на автентикацията — регистрации, влизания, изходи и
     * неуспешни опити. Append-only; чете се в Filament + дневния отчет.
     */
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();           // за неуспешни опити / изтрит потребител
            $table->string('type')->index();               // login | logout | registered | failed
            $table->string('ip_address', 45)->nullable();  // IPv4/IPv6
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
