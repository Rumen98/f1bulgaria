<?php

declare(strict_types=1);

use App\Enums\ChannelPostKind;
use App\Enums\ChannelPostStatus;
use App\Enums\F2SessionType;
use App\Models\ChannelPost;
use App\Models\F2Driver;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Models\F2Season;
use App\Models\F2Team;
use App\Services\Telegram\ChannelPublisher;
use App\Services\Telegram\ChannelQueue;
use App\Services\Telegram\F2ChannelEnqueuer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('channel.enabled', true);
    config()->set('channel.post_sleep_ms', 0);
    config()->set('services.telegram.bot_token', 'test-token');
    config()->set('services.telegram.chat_id', '-100123');
});

function telegramOk(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]])]);
}

function makeF2Session(F2SessionType $type, ?string $version = null, ?string $endsAt = null): F2RaceSession
{
    $season = F2Season::query()->create(['year' => 2026, 'is_current' => true]);
    $team = F2Team::query()->create(['f2_season_id' => $season->id, 'name' => 'Campos Racing', 'slug' => 'campos-racing']);
    $race = F2Race::query()->create([
        'f2_season_id' => $season->id, 'round' => 9, 'location_name' => 'Budapest',
        'country_name' => 'Hungary', 'slug' => '2026-budapest',
    ]);

    $session = F2RaceSession::query()->create([
        'f2_race_id' => $race->id,
        'session_type' => $type->value,
        'state' => 'completed',
        'version' => $version,
        'ends_at_utc' => $endsAt ?? now()->subHour(),
    ]);

    $driver = F2Driver::query()->create([
        'f2_season_id' => $season->id, 'f2_team_id' => $team->id,
        'first_name' => 'Nikola', 'last_name' => 'Tsolov', 'slug' => 'nikola-tsolov',
    ]);

    F2Result::query()->create([
        'f2_race_session_id' => $session->id, 'f2_driver_id' => $driver->id,
        'position' => 1, 'time_or_gap' => '1:30.720', 'points' => 25,
    ]);

    return $session->fresh();
}

function pendingPost(string $body = '<b>Тест</b>'): ChannelPost
{
    return ChannelPost::query()->create([
        'channel' => 'telegram',
        'kind' => ChannelPostKind::F2Practice->value,
        'subject_type' => 'f2-session',
        'subject_id' => 1,
        'body' => $body,
    ]);
}

it('изпраща чакащата публикация и я маркира като изпратена', function () {
    telegramOk();
    $post = pendingPost();

    $stats = app(ChannelPublisher::class)->publish();

    expect($stats['sent'])->toBe(1);

    $post->refresh();

    expect($post->status)->toBe(ChannelPostStatus::Sent)
        ->and($post->telegram_message_id)->toBe(55)
        ->and($post->sent_at)->not->toBeNull();
});

it('не публикува същия ред втори път', function () {
    telegramOk();
    pendingPost();

    app(ChannelPublisher::class)->publish();
    Http::assertSentCount(1);

    app(ChannelPublisher::class)->publish();

    // Второто пускане не праща нищо — точно това пази канала от повторение
    // при почасовия синхрон.
    Http::assertSentCount(1);
});

it('не праща нищо, когато каналът е изключен', function () {
    config()->set('channel.enabled', false);
    Http::fake();
    pendingPost();

    $stats = app(ChannelPublisher::class)->publish();

    expect($stats['sent'])->toBe(0)
        ->and($stats['errors'])->not->toBeEmpty();

    Http::assertNothingSent();
});

it('не праща отложените публикации преди времето им', function () {
    telegramOk();
    $post = pendingPost();
    $post->update(['available_at' => now()->addHour()]);

    app(ChannelPublisher::class)->publish();

    Http::assertNothingSent();
    expect($post->fresh()->status)->toBe(ChannelPostStatus::Pending);
});

it('маркира постоянната грешка като провалена и не опитва пак', function () {
    Http::fake(['api.telegram.org/*' => Http::response(
        ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden'], 403,
    )]);
    $post = pendingPost();

    app(ChannelPublisher::class)->publish();

    expect($post->fresh()->status)->toBe(ChannelPostStatus::Failed);

    app(ChannelPublisher::class)->publish();
    Http::assertSentCount(1);
});

it('оставя временната грешка за следващото пускане', function () {
    Http::fake(['api.telegram.org/*' => Http::response('', 500)]);
    $post = pendingPost();

    app(ChannelPublisher::class)->publish();
    $post->refresh();

    expect($post->status)->toBe(ChannelPostStatus::Pending)
        ->and($post->attempts)->toBe(1)
        ->and($post->last_error)->not->toBeNull();
});

it('се предава след изчерпване на опитите', function () {
    config()->set('channel.max_attempts', 2);
    Http::fake(['api.telegram.org/*' => Http::response('', 500)]);
    $post = pendingPost();

    app(ChannelPublisher::class)->publish();
    expect($post->fresh()->status)->toBe(ChannelPostStatus::Pending);

    app(ChannelPublisher::class)->publish();
    expect($post->fresh()->status)->toBe(ChannelPostStatus::Failed);
});

it('разбива дългия пост на няколко съобщения', function () {
    telegramOk();
    config()->set('channel.max_message_length', 100);

    $body = implode("\n", array_fill(0, 12, str_repeat('я', 40)));
    pendingPost($body);

    app(ChannelPublisher::class)->publish();

    expect(count(Http::recorded()))->toBeGreaterThan(1);
});

it('поставя в опашката най-много веднъж за тема и вид', function () {
    $session = makeF2Session(F2SessionType::Practice);
    $queue = app(ChannelQueue::class);

    expect($queue->enqueue($session, ChannelPostKind::F2Practice, 'тест'))->toBeTrue()
        ->and($queue->enqueue($session, ChannelPostKind::F2Practice, 'тест'))->toBeFalse()
        ->and(ChannelPost::query()->count())->toBe(1);
});

it('поставя тренировката веднага', function () {
    makeF2Session(F2SessionType::Practice);

    expect(app(F2ChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(1);
});

it('чака окончателна класация, преди да публикува състезание', function () {
    makeF2Session(F2SessionType::FeatureRace, version: 'Provisional');

    // Временната класация не тръгва — стюардите още могат да разместят подиума.
    expect(app(F2ChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(0);
});

it('публикува състезанието, щом класацията стане окончателна', function () {
    makeF2Session(F2SessionType::FeatureRace, version: 'Final');

    expect(app(F2ChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(1);
});

it('не наваксва сесии извън прозореца назад', function () {
    config()->set('channel.max_backfill_hours', 24);
    makeF2Session(F2SessionType::Practice, endsAt: now()->subDays(5)->toDateTimeString());

    // Иначе първото включване на канала би изсипало целия изминал сезон.
    expect(app(F2ChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(0);
});

it('съставя четим пост с медал, отбор и време', function () {
    $session = makeF2Session(F2SessionType::Practice);

    app(F2ChannelEnqueuer::class)->enqueuePending();
    $body = ChannelPost::query()->first()->body;

    expect($body)->toContain('Формула 2 · Свободна тренировка')
        ->and($body)->toContain('Hungary · кръг 9')
        ->and($body)->toContain('🥇')
        ->and($body)->toContain('Nikola Tsolov')
        ->and($body)->toContain('Campos Racing')
        ->and($body)->toContain('1:30.720');
});

it('праща тренировките без звук', function () {
    telegramOk();
    $session = makeF2Session(F2SessionType::Practice);
    app(F2ChannelEnqueuer::class)->enqueuePending();

    app(ChannelPublisher::class)->publish();

    Http::assertSent(fn ($request) => $request['disable_notification'] === true);
});
