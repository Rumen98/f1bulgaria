<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('constructors_canonical', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();             // идентичност (един отбор = един ред)
            $table->string('color_hex')->nullable();
            $table->string('logo_url')->nullable();
            $table->text('bio_bg')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('total_wins')->default(0);
            $table->unsignedInteger('total_podiums')->default(0);
            $table->unsignedInteger('total_poles')->default(0);
            $table->unsignedInteger('total_races')->default(0);
            $table->date('first_race_at')->nullable();
            $table->date('last_race_at')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('constructors', function (Blueprint $table) {
            $table->foreignId('canonical_id')->nullable()->after('id')
                ->constrained('constructors_canonical')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('constructors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_id');
        });

        Schema::dropIfExists('constructors_canonical');
    }
};
