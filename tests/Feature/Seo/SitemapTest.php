<?php

declare(strict_types=1);

use App\Console\Commands\GenerateSitemapCommand;
use App\Models\DriverCanonical;
use Illuminate\Support\Facades\File;

it('urls() включва статични и динамични страници', function () {
    DriverCanonical::query()->create(['first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton']);

    $urls = app(GenerateSitemapCommand::class)->urls();

    expect($urls)->toContain(route('home'))
        ->and($urls)->toContain(route('tsolov'))
        ->and($urls)->toContain(route('f2'))
        ->and($urls)->toContain(route('drivers.show', 'lewis-hamilton'))
        ->and($urls->count())->toBe($urls->unique()->count()); // без дубликати
});

it('командата записва валиден sitemap.xml', function () {
    $path = public_path('sitemap.xml');
    File::delete($path);

    $this->artisan('sitemap:generate')->assertSuccessful();

    expect(File::exists($path))->toBeTrue();
    $xml = File::get($path);
    expect($xml)->toContain('<urlset')
        ->and($xml)->toContain('<loc>')
        ->and(simplexml_load_string($xml))->not->toBeFalse(); // валиден XML

    File::delete($path); // почистване на артефакта
});
