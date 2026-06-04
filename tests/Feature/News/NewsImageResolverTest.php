<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\News\NewsImageResolver;

function imageResolver(): NewsImageResolver
{
    return app(NewsImageResolver::class);
}

beforeEach(function () {
    $this->season = Season::factory()->current()->create();
});

it('избира снимка на споменат пилот за driver новина', function () {
    $team = Constructor::factory()->create(['season_id' => $this->season->id, 'color_hex' => '#ff1801']);
    Driver::factory()->create([
        'season_id' => $this->season->id,
        'constructor_id' => $team->id,
        'first_name' => 'Max',
        'last_name' => 'Verstappen',
        'photo_url' => 'https://upload.wikimedia.org/ver.jpg',
    ]);

    $item = TeamNewsItem::factory()->create([
        'classification' => 'driver',
        'constructor_id' => null,
        'title_original' => 'Verstappen signs new deal',
        'content_snippet' => 'Big news for Max.',
    ]);

    $meta = imageResolver()->resolve($item);

    expect($meta['type'])->toBe('driver_photo')
        ->and($meta['data']['photo_url'])->toBe('https://upload.wikimedia.org/ver.jpg')
        ->and($meta['data']['name'])->toBe('Max Verstappen');
});

it('избира банер на отбора когато новината е обвързана с него', function () {
    $team = Constructor::factory()->create(['season_id' => $this->season->id, 'name' => 'Ferrari', 'color_hex' => '#dc0000']);

    $item = TeamNewsItem::factory()->create([
        'classification' => 'business',
        'constructor_id' => $team->id,
        'title_original' => 'New title sponsor announced',
    ]);

    $meta = imageResolver()->resolve($item);

    expect($meta['type'])->toBe('team_banner')
        ->and($meta['data']['name'])->toBe('Ferrari')
        ->and($meta['data']['color'])->toBe('#dc0000');
});

it('избира очертание на пистата при спомената писта', function () {
    Race::factory()->create([
        'season_id' => $this->season->id,
        'jolpica_id' => 'monaco',
        'circuit' => 'Circuit de Monaco',
        'country' => 'Monaco',
    ]);

    $item = TeamNewsItem::factory()->create([
        'classification' => 'race',
        'constructor_id' => null,
        'title_original' => 'Thrilling battle expected in Monaco this weekend',
    ]);

    $meta = imageResolver()->resolve($item);

    expect($meta['type'])->toBe('circuit_outline')
        ->and($meta['data']['slug'])->toBe('monaco');
});

it('пада към generic банер с цвят по категория', function () {
    $item = TeamNewsItem::factory()->create([
        'classification' => 'rumor',
        'constructor_id' => null,
        'title_original' => 'Vague speculation about silly season',
        'content_snippet' => 'Sources say maybe.',
    ]);

    $meta = imageResolver()->resolve($item);

    expect($meta['type'])->toBe('generic')
        ->and($meta['data']['color'])->toBe(NewsClassification::Rumor->color());
});

it('driver новина без налична снимка не връща driver_photo', function () {
    Driver::factory()->create([
        'season_id' => $this->season->id,
        'last_name' => 'Verstappen',
        'photo_url' => null,
    ]);

    $item = TeamNewsItem::factory()->create([
        'classification' => 'driver',
        'constructor_id' => null,
        'title_original' => 'Verstappen comments on the race',
        'content_snippet' => 'Some quotes.',
    ]);

    expect(imageResolver()->resolve($item)['type'])->not->toBe('driver_photo');
});
