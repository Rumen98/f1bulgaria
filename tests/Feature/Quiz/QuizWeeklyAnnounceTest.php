<?php

declare(strict_types=1);

use App\Mail\QuizWeeklyMail;
use App\Models\NewsletterSend;
use App\Models\NewsletterSubscriber;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['features.quiz' => true]);

    $this->telegram = $this->mock(TelegramClient::class, function ($m) {
        $m->shouldReceive('hasCredentials')->andReturn(true)->byDefault();
        $m->shouldReceive('send')->andReturn(1)->byDefault();
    });
});

it('праща на всички и поства в канала', function () {
    QuizQuestion::factory()->count(5)->create();
    $user = User::factory()->create();
    NewsletterSubscriber::create(['email' => 'abonat@example.bg', 'unsubscribe_token' => str_repeat('t', 48)]);

    $this->telegram->shouldReceive('send')->once()->andReturn(1);

    $this->artisan('padok:quiz-monday')->assertSuccessful();

    Mail::assertSent(QuizWeeklyMail::class, 2);
    Mail::assertSent(QuizWeeklyMail::class, fn ($mail) => $mail->hasTo($user->email));
});

it('не дублира в същата седмица', function () {
    QuizQuestion::factory()->count(5)->create();
    User::factory()->create();

    $this->artisan('padok:quiz-monday')->assertSuccessful();
    $this->artisan('padok:quiz-monday')->assertSuccessful();

    Mail::assertSent(QuizWeeklyMail::class, 1);
});

it('мълчи без активни въпроси и при изключен флаг', function () {
    User::factory()->create();

    $this->artisan('padok:quiz-monday')->assertSuccessful();
    Mail::assertNothingQueued();

    QuizQuestion::factory()->create();
    config(['features.quiz' => false]);
    $this->artisan('padok:quiz-monday')->assertSuccessful();
    Mail::assertNothingQueued();
});

it('dry-run не праща, не поства и не маркира', function () {
    QuizQuestion::factory()->count(5)->create();
    User::factory()->create();

    $this->telegram->shouldNotReceive('send');

    $this->artisan('padok:quiz-monday', ['--dry-run' => true])->assertSuccessful();

    Mail::assertNothingQueued();
    expect(NewsletterSend::query()->count())->toBe(0);
});

it('писмото носи куиза и функцията на седмицата', function () {
    QuizQuestion::factory()->count(5)->create();
    $user = User::factory()->create();

    $this->artisan('padok:quiz-monday')->assertSuccessful();

    Mail::assertSent(QuizWeeklyMail::class, function (QuizWeeklyMail $mail) use ($user) {
        if (! $mail->hasTo($user->email)) {
            return false;
        }

        $html = $mail->render();

        return $mail->spotlight !== null
            && str_contains($html, 'Знаеш ли, че')
            && str_contains($html, $mail->spotlight['title'])
            && str_contains($html, 'Реши ги')
            && str_contains($html, 'Спри имейлите');
    });
});

it('абонатът без акаунт получава покана за регистрация', function () {
    QuizQuestion::factory()->count(5)->create();
    NewsletterSubscriber::create(['email' => 'abonat@example.bg', 'unsubscribe_token' => str_repeat('t', 48)]);

    $this->artisan('padok:quiz-monday')->assertSuccessful();

    Mail::assertSent(QuizWeeklyMail::class, function (QuizWeeklyMail $mail) {
        $html = $mail->render();

        return str_contains($html, 'Регистрирай се и играй')
            && str_contains($html, 'Отпиши се');
    });
});

it('провал на Telegram не проваля писмата', function () {
    QuizQuestion::factory()->count(5)->create();
    User::factory()->create();

    $this->telegram->shouldReceive('send')->andThrow(new RuntimeException('канал недостъпен'));

    $this->artisan('padok:quiz-monday')->assertSuccessful();

    Mail::assertSent(QuizWeeklyMail::class, 1);
});
