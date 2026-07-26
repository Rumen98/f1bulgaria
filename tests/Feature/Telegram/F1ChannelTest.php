<?php

declare(strict_types=1);

use App\Enums\ChannelPostKind;
use App\Enums\ResultSessionType;
use App\Enums\SessionType;
use App\Models\ChannelPost;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\SessionResult;
use App\Services\Telegram\F1ChannelEnqueuer;

beforeEach(function () {
    config()->set('channel.enabled', true);
    config()->set('channel.max_backfill_hours', 24);
    config()->set('channel.race_result_delay_minutes', 45);
});

function f1Race(): Race
{
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);

    return Race::factory()->create([
        'season_id' => $season->id,
        'jolpica_id' => 'hungaroring',
        'name' => 'Hungarian Grand Prix',
        'round' => 9,
        'qualifying_datetime_utc' => now()->subHours(20),
        'sprint_datetime_utc' => null,
        'race_datetime_utc' => now()->subHours(3),
        'has_sprint' => false,
    ]);
}

function f1Driver(Race $race, string $first, string $last, string $slug, string $team = 'Ferrari'): Driver
{
    $constructor = Constructor::factory()->create([
        'season_id' => $race->season_id,
        'name' => $team,
        'slug' => Str::slug($team),
    ]);

    return Driver::factory()->create([
        'season_id' => $race->season_id,
        'constructor_id' => $constructor->id,
        'first_name' => $first,
        'last_name' => $last,
        'slug' => $slug,
    ]);
}

it('поставя квалификацията в опашката от session_results', function () {
    $race = f1Race();
    $driver = f1Driver($race, 'Lewis', 'Hamilton', 'lewis-hamilton');

    SessionResult::query()->create([
        'race_id' => $race->id,
        'session_type' => SessionType::Qualifying->value,
        'driver_id' => $driver->id,
        'position' => 1,
        'q3' => '1:15.096',
    ]);

    expect(app(F1ChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(1);

    $post = ChannelPost::query()->first();

    expect($post->kind)->toBe(ChannelPostKind::F1Qualifying)
        // Име на Гран При-то и на пилота — и двете на български.
        ->and($post->body)->toContain('Гран При на Унгария · кръг 9')
        ->and($post->body)->toContain('Люис Хамилтън')
        ->and($post->body)->toContain('1:15.096');
});

it('отлага резултата от състезанието заради стюардите', function () {
    $race = f1Race();
    $driver = f1Driver($race, 'Max', 'Verstappen', 'max-verstappen');

    Result::factory()->create([
        'race_id' => $race->id,
        'driver_id' => $driver->id,
        'session_type' => ResultSessionType::Race->value,
        'position' => 1,
        'points' => 25,
        'dnf' => false,
    ]);

    app(F1ChannelEnqueuer::class)->enqueuePending();

    $post = ChannelPost::query()->where('kind', ChannelPostKind::F1Race->value)->first();

    // Jolpica няма поле „окончателна класация", затова отлагането е по часовник.
    expect($post->available_at->timestamp)
        ->toBe($race->race_datetime_utc->copy()->addMinutes(45)->timestamp);
});

it('подрежда квалификацията преди състезанието', function () {
    $race = f1Race();
    $driver = f1Driver($race, 'Charles', 'Leclerc', 'charles-leclerc');

    SessionResult::query()->create([
        'race_id' => $race->id,
        'session_type' => SessionType::Qualifying->value,
        'driver_id' => $driver->id,
        'position' => 1,
        'q3' => '1:15.096',
    ]);

    Result::factory()->create([
        'race_id' => $race->id,
        'driver_id' => $driver->id,
        'session_type' => ResultSessionType::Race->value,
        'position' => 1,
        'points' => 25,
        'dnf' => false,
    ]);

    app(F1ChannelEnqueuer::class)->enqueuePending();

    expect(ChannelPost::query()->ready()->pluck('kind')->map(fn ($k) => $k->value)->all())
        ->toBe(['f1_qualifying', 'f1_race']);
});

it('отбелязва най-бързата обиколка и отпадналите', function () {
    $race = f1Race();
    $winner = f1Driver($race, 'Max', 'Verstappen', 'max-verstappen', 'Red Bull');
    $retired = f1Driver($race, 'Lewis', 'Hamilton', 'lewis-hamilton', 'Ferrari');

    Result::factory()->create([
        'race_id' => $race->id, 'driver_id' => $winner->id,
        'session_type' => ResultSessionType::Race->value,
        'position' => 1, 'points' => 25, 'dnf' => false, 'fastest_lap' => true,
    ]);

    Result::factory()->create([
        'race_id' => $race->id, 'driver_id' => $retired->id,
        'session_type' => ResultSessionType::Race->value,
        'position' => null, 'points' => 0, 'dnf' => true, 'fastest_lap' => false,
    ]);

    app(F1ChannelEnqueuer::class)->enqueuePending();
    $body = ChannelPost::query()->where('kind', ChannelPostKind::F1Race->value)->first()->body;

    expect($body)->toContain('🥇')
        ->and($body)->toContain('Макс Верстапен')
        ->and($body)->toContain('25 т.')
        ->and($body)->toContain('🟣')
        ->and($body)->toContain('Отпаднали: Люис Хамилтън');
});

it('не наваксва състезания извън прозореца', function () {
    $race = f1Race();
    $race->update([
        'qualifying_datetime_utc' => now()->subDays(5),
        'race_datetime_utc' => now()->subDays(5),
    ]);

    $driver = f1Driver($race, 'Max', 'Verstappen', 'max-verstappen');

    Result::factory()->create([
        'race_id' => $race->id, 'driver_id' => $driver->id,
        'session_type' => ResultSessionType::Race->value,
        'position' => 1, 'points' => 25, 'dnf' => false,
    ]);

    expect(app(F1ChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(0);
});

it('не поставя сесия без резултати', function () {
    f1Race();

    expect(app(F1ChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(0);
});
