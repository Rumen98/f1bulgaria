<?php

declare(strict_types=1);

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramPermanentException;
use App\Services\Telegram\TelegramText;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.telegram.bot_token', 'test-token-123');
    config()->set('services.telegram.chat_id', '-1001234567890');
});

it('публикува съобщение и връща message_id', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
    ]);

    expect(app(TelegramClient::class)->send('<b>Тест</b>'))->toBe(4242);

    Http::assertSent(function ($request) {
        expect($request['parse_mode'])->toBe('HTML')
            ->and($request['chat_id'])->toBe('-1001234567890')
            ->and($request['link_preview_options'])->toBe(['is_disabled' => true]);

        return true;
    });
});

it('изпраща тихо, когато е поискано', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    app(TelegramClient::class)->send('тренировка', silent: true);

    Http::assertSent(fn ($request) => $request['disable_notification'] === true);
});

it('повтаря при 429 и изчаква точно колкото каже retry_after', function () {
    Http::fake([
        'api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 1]], 429)
            ->push(['ok' => true, 'result' => ['message_id' => 7]], 200),
    ]);

    expect(app(TelegramClient::class)->send('тест'))->toBe(7);

    Http::assertSentCount(2);
});

it('не повтаря при дълъг retry_after, а връща временна грешка', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(
            ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 600]],
            429,
        ),
    ]);

    // Временна, не постоянна: редът трябва да остане в опашката за по-късно.
    expect(fn () => app(TelegramClient::class)->send('тест'))
        ->toThrow(TelegramException::class);

    Http::assertSentCount(1);
});

it('третира 403 като постоянна грешка без повтаряне', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(
            ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked from the channel chat'],
            403,
        ),
    ]);

    expect(fn () => app(TelegramClient::class)->send('тест'))
        ->toThrow(TelegramPermanentException::class);

    Http::assertSentCount(1);
});

it('повтаря при 5xx', function () {
    Http::fake([
        'api.telegram.org/*' => Http::sequence()
            ->push('', 502)
            ->push(['ok' => true, 'result' => ['message_id' => 9]], 200),
    ]);

    expect(app(TelegramClient::class)->send('тест'))->toBe(9);

    Http::assertSentCount(2);
});

it('никога не изнася токена в съобщението за грешка', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(
            ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'],
            400,
        ),
    ]);

    try {
        app(TelegramClient::class)->send('тест');
    } catch (TelegramException $e) {
        expect($e->getMessage())->not->toContain('test-token-123');

        return;
    }

    $this->fail('Очаквахме изключение.');
});

it('спира веднага при липсващ токен, без мрежова заявка', function () {
    config()->set('services.telegram.bot_token', null);
    Http::fake();

    expect(fn () => app(TelegramClient::class)->send('тест'))
        ->toThrow(TelegramPermanentException::class);

    Http::assertNothingSent();
});

it('екранира само трите знака, които Telegram изисква', function () {
    // Точки, плюсове и тирета са опасни само в MarkdownV2 — в HTML минават
    // непокътнати, което е цялата причина да ползваме HTML.
    expect(TelegramText::escape('1:23.456 +1.234s Hülkenberg (DNF)'))
        ->toBe('1:23.456 +1.234s Hülkenberg (DNF)')
        ->and(TelegramText::escape('Alfa & Sauber <test>'))
        ->toBe('Alfa &amp; Sauber &lt;test&gt;');
});

it('реже дългите съобщения по границата на ред', function () {
    $line = str_repeat('я', 100);
    $html = implode("\n", array_fill(0, 10, "<b>{$line}</b>"));

    $chunks = TelegramText::chunk($html, 250);

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect(TelegramText::visibleLength($chunk))->toBeLessThanOrEqual(250)
            // Нито един къс не бива да започва или свършва със счупен таг.
            ->and(substr_count($chunk, '<b>'))->toBe(substr_count($chunk, '</b>'));
    }
});

it('не брои HTML таговете към лимита', function () {
    // Лимитът важи за текста след парсване на entities — иначе бихме резали
    // много по-рано от нужното.
    expect(TelegramText::visibleLength('<b>абв</b>'))->toBe(3);
});
