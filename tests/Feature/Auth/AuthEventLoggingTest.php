<?php

declare(strict_types=1);

use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Schema;

it('логва влизане', function () {
    $user = User::factory()->create();

    event(new Login('web', $user, false));

    $this->assertDatabaseHas('auth_events', [
        'user_id' => $user->id,
        'email' => $user->email,
        'type' => AuthEvent::TYPE_LOGIN,
    ]);
});

it('логва регистрация', function () {
    $user = User::factory()->create();

    event(new Registered($user));

    expect(AuthEvent::where('type', AuthEvent::TYPE_REGISTERED)->where('user_id', $user->id)->exists())
        ->toBeTrue();
});

it('логва неуспешен опит с подадения имейл (без потребител)', function () {
    event(new Failed('web', null, ['email' => 'attacker@example.com', 'password' => 'x']));

    $this->assertDatabaseHas('auth_events', [
        'user_id' => null,
        'email' => 'attacker@example.com',
        'type' => AuthEvent::TYPE_FAILED,
    ]);
});

it('логинът работи дори когато одит таблицата липсва (миграция не е пусната)', function () {
    // Реален прод инцидент: деплой без `php artisan migrate` → всеки login
    // POST гърмеше с 500, защото subscriber-ът пишеше в липсваща таблица.
    $user = User::factory()->create();
    Schema::drop('auth_events');

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});
