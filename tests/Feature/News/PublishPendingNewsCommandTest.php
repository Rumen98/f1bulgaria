<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;

it('публикува обогатените pending новини', function () {
    $enriched = TeamNewsItem::factory()->create([
        'title_bg' => 'Преведено заглавие',
        'classification' => NewsClassification::Race->value,
    ]);

    $this->artisan('news:publish-pending')
        ->expectsOutputToContain('Публикувани новини: 1.')
        ->assertSuccessful();

    expect($enriched->fresh()->status)->toBe(NewsStatus::AutoPublished);
});

it('не публикува необогатени pending новини', function () {
    $raw = TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);
    $halfEnriched = TeamNewsItem::factory()->create([
        'title_bg' => 'Само заглавие',
        'classification' => null,
    ]);

    $this->artisan('news:publish-pending')
        ->expectsOutputToContain('Публикувани новини: 0.')
        ->assertSuccessful();

    expect($raw->fresh()->status)->toBe(NewsStatus::Pending)
        ->and($halfEnriched->fresh()->status)->toBe(NewsStatus::Pending);
});

it('не пипа отхвърлени и одобрени новини', function () {
    $rejected = TeamNewsItem::factory()->create([
        'title_bg' => 'Дубликат',
        'classification' => NewsClassification::Race->value,
        'status' => NewsStatus::Rejected->value,
    ]);
    $approved = TeamNewsItem::factory()->approved()->create([
        'classification' => NewsClassification::Driver->value,
    ]);

    $this->artisan('news:publish-pending')->assertSuccessful();

    expect($rejected->fresh()->status)->toBe(NewsStatus::Rejected)
        ->and($approved->fresh()->status)->toBe(NewsStatus::Approved);
});
