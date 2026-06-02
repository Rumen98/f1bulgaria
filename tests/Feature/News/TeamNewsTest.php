<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\Constructor;
use App\Models\TeamNewsItem;
use App\Models\TeamNewsSource;
use Illuminate\Database\QueryException;

it('създава глобален източник без конструктор', function () {
    $source = TeamNewsSource::factory()->create();

    expect($source->constructor_id)->toBeNull()
        ->and($source->isGlobal())->toBeTrue()
        ->and($source->constructor)->toBeNull();
});

it('създава източник, обвързан с конструктор', function () {
    $constructor = Constructor::factory()->create();
    $source = TeamNewsSource::factory()->forConstructor($constructor)->create();

    expect($source->isGlobal())->toBeFalse()
        ->and($source->constructor->is($constructor))->toBeTrue();
});

it('налага уникален external_url', function () {
    TeamNewsItem::factory()->create(['external_url' => 'https://example.com/article-1']);

    expect(fn () => TeamNewsItem::factory()->create(['external_url' => 'https://example.com/article-1']))
        ->toThrow(QueryException::class);
});

it('свързва източник с неговите новини', function () {
    $source = TeamNewsSource::factory()->create();
    TeamNewsItem::factory()->count(3)->create(['source_id' => $source->id]);

    expect($source->items)->toHaveCount(3)
        ->and($source->items->first()->source->is($source))->toBeTrue();
});

it('свързва конструктор с неговите новини и източници', function () {
    $constructor = Constructor::factory()->create();

    TeamNewsSource::factory()->forConstructor($constructor)->create();
    TeamNewsItem::factory()->count(2)->create(['constructor_id' => $constructor->id]);

    expect($constructor->newsSources)->toHaveCount(1)
        ->and($constructor->newsItems)->toHaveCount(2)
        ->and($constructor->newsItems->first()->constructor->is($constructor))->toBeTrue();
});

it('кастова classification и status към enum-и', function () {
    $item = TeamNewsItem::factory()->create([
        'classification' => NewsClassification::Technical->value,
        'status' => NewsStatus::Approved->value,
    ]);

    expect($item->refresh()->classification)->toBe(NewsClassification::Technical)
        ->and($item->status)->toBe(NewsStatus::Approved);
});
