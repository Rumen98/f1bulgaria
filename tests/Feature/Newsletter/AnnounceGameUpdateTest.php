<?php

declare(strict_types=1);

use App\Mail\GameUpdateMail;
use App\Models\GameLapRecord;
use App\Models\GameSession;
use App\Models\NewsletterSend;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['features.game' => true]);
});

it('праща само на пробвалите играта — със старт или записана обиколка', function () {
    $starter = User::factory()->create(['name' => 'Стартирал']);
    GameSession::factory()->for($starter)->create();
    $lapper = User::factory()->create(['name' => 'С обиколка']);
    GameLapRecord::factory()->for($lapper)->create();
    User::factory()->create(['name' => 'Никога не е карал']);
    NewsletterSubscriber::create(['email' => 'abonat@example.bg', 'unsubscribe_token' => str_repeat('t', 48)]);

    $this->artisan('padok:announce-game-update')->assertSuccessful();

    Mail::assertSent(GameUpdateMail::class, 2);
    Mail::assertSent(GameUpdateMail::class, fn (GameUpdateMail $mail) => $mail->hasTo($starter->email) && $mail->name === 'Стартирал');
    Mail::assertSent(GameUpdateMail::class, fn (GameUpdateMail $mail) => $mail->hasTo($lapper->email));
});

it('прескача банати и спрели имейлите играчи', function () {
    GameSession::factory()->for(User::factory()->create(['banned_at' => now()]))->create();
    GameSession::factory()->for(User::factory()->create(['email_opt_out_at' => now()]))->create();

    $this->artisan('padok:announce-game-update')->assertSuccessful();

    Mail::assertNothingSent();
    expect(NewsletterSend::query()->count())->toBe(0);
});

it('не праща втори път при повторен пуск, но force повтаря', function () {
    GameSession::factory()->create();

    $this->artisan('padok:announce-game-update')->assertSuccessful();
    $this->artisan('padok:announce-game-update')->assertSuccessful();
    Mail::assertSent(GameUpdateMail::class, 1);

    $this->artisan('padok:announce-game-update', ['--force' => true])->assertSuccessful();
    Mail::assertSent(GameUpdateMail::class, 2);
});

it('dry-run изброява получателите, без да праща и маркира', function () {
    $session = GameSession::factory()->for(User::factory()->create(['name' => 'Пробвал Играта']))->create();

    $this->artisan('padok:announce-game-update', ['--dry-run' => true])
        ->expectsOutputToContain('1 играчи')
        ->expectsOutputToContain('Пробвал Играта')
        ->assertSuccessful();

    Mail::assertNothingSent();
    expect(NewsletterSend::query()->count())->toBe(0);
});

it('отказва при изключена игра', function () {
    GameSession::factory()->create();
    config(['features.game' => false]);

    $this->artisan('padok:announce-game-update')->assertFailed();

    Mail::assertNothingSent();
});

it('писмото се обръща по име, кани към състезание и има one-click unsubscribe', function () {
    $session = GameSession::factory()->for(User::factory()->create(['name' => 'Мартин']))->create();

    $this->artisan('padok:announce-game-update')->assertSuccessful();

    Mail::assertSent(GameUpdateMail::class, function (GameUpdateMail $mail) {
        $html = $mail->render();

        return str_contains($html, 'Здравей, Мартин!')
            && str_contains($html, 'Изиграй едно състезание')
            && str_contains($html, 'На телефон вече се кара')
            && str_contains($html, 'Спри имейлите')
            && $mail->headers()->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
    });
});
