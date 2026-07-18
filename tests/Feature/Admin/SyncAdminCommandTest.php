<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('създава админ акаунт от env креденшълите', function () {
    config()->set('app.admin_email', 'admin@padok.bg');
    config()->set('app.admin_password', 'strong-password-123');

    $this->artisan('padok:sync-admin')->assertSuccessful();

    $user = User::where('email', 'admin@padok.bg')->first();
    expect($user)->not->toBeNull()
        ->and($user->is_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('strong-password-123', $user->password))->toBeTrue();
});

it('обновява паролата и промотира съществуващ акаунт', function () {
    $user = User::factory()->create([
        'email' => 'admin@padok.bg',
        'is_admin' => false,
        'banned_at' => now(),
    ]);

    config()->set('app.admin_email', 'admin@padok.bg');
    config()->set('app.admin_password', 'rotated-password-456');

    $this->artisan('padok:sync-admin')->assertSuccessful();

    $user->refresh();
    expect($user->is_admin)->toBeTrue()
        ->and($user->banned_at)->toBeNull()
        ->and(Hash::check('rotated-password-456', $user->password))->toBeTrue();
});

it('отказва при липсващи креденшъли в env', function () {
    config()->set('app.admin_email', '');
    config()->set('app.admin_password', '');

    $this->artisan('padok:sync-admin')->assertFailed();

    expect(User::count())->toBe(0);
});
