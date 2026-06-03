<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_news_items', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('external_guid');
        });

        $this->backfillSlugs();
    }

    public function down(): void
    {
        Schema::table('team_news_items', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    /**
     * Генерира slug за съществуващите статии от title_bg (или title_original),
     * като пази уникалност чрез in-memory set.
     */
    private function backfillSlugs(): void
    {
        $used = [];

        DB::table('team_news_items')
            ->select('id', 'title_bg', 'title_original')
            ->orderBy('id')
            ->each(function (object $item) use (&$used): void {
                $base = Str::slug($item->title_bg ?: $item->title_original ?: '') ?: 'novina';
                $slug = $base;
                $n = 2;

                while (isset($used[$slug])) {
                    $slug = "{$base}-{$n}";
                    $n++;
                }

                $used[$slug] = true;
                DB::table('team_news_items')->where('id', $item->id)->update(['slug' => $slug]);
            });
    }
};
