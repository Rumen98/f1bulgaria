<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers_canonical', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();          // последен/доминиращ код (HAM, VER…)
            $table->string('first_name');
            $table->string('last_name');
            $table->string('slug')->unique();             // идентичност (един човек = един ред)
            $table->string('country_code', 3)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->integer('permanent_number')->nullable();
            $table->string('photo_url')->nullable();
            $table->text('bio_bg')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('total_wins')->default(0);
            $table->unsignedInteger('total_podiums')->default(0);
            $table->unsignedInteger('total_poles')->default(0);
            $table->unsignedInteger('total_races')->default(0);
            $table->date('first_race_at')->nullable();
            $table->date('last_race_at')->nullable();
            $table->timestamps();

            $table->index('code');
            $table->index('is_active');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->foreignId('canonical_id')->nullable()->after('id')
                ->constrained('drivers_canonical')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_id');
        });

        Schema::dropIfExists('drivers_canonical');
    }
};
