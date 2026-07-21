<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeGoogleUser(string $id = 'g-123', string $email = 'fen@gmail.com', string $name = 'Иван Фенов'): SocialiteUser
{
    return (new SocialiteUser)->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
    ]);
}

function mockGoogleCallback(SocialiteUser $user): void
{
    Socialite::shouldReceive('driver->user')->andReturn($user);
}

it('пренасочва към Google за вход', function () {
    $this->get(route('google.redirect'))
        ->assertRedirect()
        ->assertRedirectContains('accounts.google.com');
});

it('създава нов акаунт от Google с потвърден имейл', function () {
    mockGoogleCallback(fakeGoogleUser());

    $this->get(route('google.callback'))->assertRedirect(route('home'));

    $user = User::first();
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('fen@gmail.com')
        ->and($user->name)->toBe('Иван Фенов')
        ->and($user->google_id)->toBe('g-123')
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('свързва Google към съществуващ акаунт със същия имейл (без дубликат)', function () {
    $existing = User::factory()->create(['email' => 'fen@gmail.com']);

    mockGoogleCallback(fakeGoogleUser());

    $this->get(route('google.callback'))->assertRedirect(route('home'));

    expect(User::count())->toBe(1)
        ->and($existing->fresh()->google_id)->toBe('g-123');

    $this->assertAuthenticatedAs($existing);
});

it('логва директно съществуващ Google потребител', function () {
    $user = User::factory()->create(['email' => 'fen@gmail.com', 'google_id' => 'g-123']);

    mockGoogleCallback(fakeGoogleUser());

    $this->get(route('google.callback'))->assertRedirect(route('home'));

    expect(User::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

it('връща към login при отказано съгласие', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new RuntimeException('denied'));

    $this->get(route('google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});
