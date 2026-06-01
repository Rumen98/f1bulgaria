<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Допълва Breeze users таблицата с F1 профилни полета. Изпълнява се СЛЕД
     * drivers и constructors, за да са валидни външните ключове.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('favorite_driver_id')->nullable()->after('email')
                ->constrained('drivers')->nullOnDelete();
            $table->foreignId('favorite_constructor_id')->nullable()->after('favorite_driver_id')
                ->constrained('constructors')->nullOnDelete();
            $table->text('bio')->nullable()->after('favorite_constructor_id');
            $table->string('avatar_path')->nullable()->after('bio');
            // Достъп до Filament админ панела (Румен е единственият админ за MVP).
            $table->boolean('is_admin')->default(false)->after('avatar_path');
            // Модерация: ненулево = потребителят е блокиран и не може да влиза.
            $table->timestamp('banned_at')->nullable()->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('favorite_driver_id');
            $table->dropConstrainedForeignId('favorite_constructor_id');
            $table->dropColumn(['bio', 'avatar_path', 'is_admin', 'banned_at']);
        });
    }
};
