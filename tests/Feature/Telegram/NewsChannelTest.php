<?php

declare(strict_types=1);

use App\Enums\ChannelPostKind;
use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\ChannelPost;
use App\Models\TeamNewsItem;
use App\Services\Telegram\ChannelPublisher;
use App\Services\Telegram\NewsChannelEnqueuer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('channel.enabled', true);
    config()->set('channel.post_sleep_ms', 0);
    config()->set('channel.news_min_importance', 4);
    config()->set('services.telegram.bot_token', 'test-token');
    config()->set('services.telegram.chat_id', '-100123');
});

function newsItem(int $importance, array $attributes = []): TeamNewsItem
{
    return TeamNewsItem::factory()->approved()->create([
        'importance_score' => $importance,
        'classification' => NewsClassification::Driver->value,
        ...$attributes,
    ]);
}

it('поставя в опашката само новините над прага', function () {
    newsItem(5, ['title_bg' => 'Историческа новина']);
    newsItem(4, ['title_bg' => 'Голяма новина']);
    newsItem(3, ['title_bg' => 'Значима новина']);
    newsItem(1, ['title_bg' => 'Рутинна новина']);

    expect(app(NewsChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(2);

    $titles = ChannelPost::query()->pluck('body')->implode(' ');

    expect($titles)->toContain('Историческа новина')
        ->and($titles)->toContain('Голяма новина')
        ->and($titles)->not->toContain('Значима новина')
        ->and($titles)->not->toContain('Рутинна новина');
});

it('прагът над максимума на скалата не публикува нищо', function () {
    // Скалата е 1–5. Праг 7 (както беше по подразбиране) значи тих канал
    // завинаги, без нито едно съобщение за грешка.
    config()->set('channel.news_min_importance', 7);
    newsItem(5);

    expect(app(NewsChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(0);
});

it('не публикува новини, които не са видими на сайта', function () {
    newsItem(5, ['status' => NewsStatus::Pending->value]);
    newsItem(5, ['status' => NewsStatus::Rejected->value]);

    expect(app(NewsChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(0);
});

it('публикува и авто-публикуваните, не само ръчно одобрените', function () {
    newsItem(5, ['status' => NewsStatus::AutoPublished->value]);

    expect(app(NewsChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(1);
});

it('не наваксва стари новини извън прозореца', function () {
    // Прозорецът гледа кога сме вписали статията, не датата на източника:
    // прясно взета новина може да носи стара published_at.
    $old = newsItem(5);
    $old->forceFill(['created_at' => now()->subDays(5)])->save();

    expect(app(NewsChannelEnqueuer::class)->enqueuePending()['queued'])->toBe(0);
});

it('съставя пост със заглавие, резюме, контекст и линк', function () {
    $item = newsItem(4, [
        'title_bg' => 'Ферари обявява нов пилот',
        'summary_bg' => 'Отборът потвърди договора днес следобед.',
    ]);

    app(NewsChannelEnqueuer::class)->enqueuePending();
    $body = ChannelPost::query()->first()->body;

    expect($body)->toContain('📰')
        ->and($body)->toContain('Ферари обявява нов пилот')
        ->and($body)->toContain('Отборът потвърди договора днес следобед.')
        ->and($body)->toContain('Пилот')
        ->and($body)->toContain(route('news.show', $item->slug));
});

it('отбелязва историческите новини по-силно', function () {
    newsItem(5, ['title_bg' => 'Нов шампион']);

    expect(ChannelPost::query()->count())->toBe(0);

    app(NewsChannelEnqueuer::class)->enqueuePending();

    expect(ChannelPost::query()->first()->body)->toStartWith('🚨');
});

it('показва превюто на линка при новина, но не при класация', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    newsItem(5);
    app(NewsChannelEnqueuer::class)->enqueuePending();
    app(ChannelPublisher::class)->publish();

    // Превюто носи заглавната снимка — при новина то е самото съдържание.
    Http::assertSent(fn ($request) => $request['link_preview_options'] === ['is_disabled' => false]);

    expect(ChannelPostKind::News->showsLinkPreview())->toBeTrue()
        ->and(ChannelPostKind::F1Race->showsLinkPreview())->toBeFalse();
});

it('не поставя една и съща новина два пъти', function () {
    newsItem(5);

    app(NewsChannelEnqueuer::class)->enqueuePending();
    app(NewsChannelEnqueuer::class)->enqueuePending();

    expect(ChannelPost::query()->count())->toBe(1);
});
