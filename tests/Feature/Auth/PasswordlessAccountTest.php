<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('Google акаунт без парола изтрива акаунта си без парола', function () {
    $user = User::factory()->create(['google_id' => 'g-1', 'password' => null]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'))
        ->assertRedirect('/');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('акаунт с парола продължава да изисква парола за изтриване', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    expect(User::count())->toBe(1);
});

it('Google акаунт без парола си задава първа парола без текуща', function () {
    $user = User::factory()->create(['google_id' => 'g-1', 'password' => null]);

    $this->actingAs($user)
        ->put(route('password.update'), [
            'password' => 'nova-parola-123',
            'password_confirmation' => 'nova-parola-123',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('nova-parola-123', $user->fresh()->password))->toBeTrue();
});

it('акаунт с парола продължава да изисква текущата при смяна', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('profile.edit'))
        ->put(route('password.update'), [
            'current_password' => 'wrong',
            'password' => 'nova-parola-123',
            'password_confirmation' => 'nova-parola-123',
        ])
        ->assertSessionHasErrors('current_password');
});

it('вход с парола не работи за акаунт без парола', function () {
    User::factory()->create(['email' => 'fen@gmail.com', 'google_id' => 'g-1', 'password' => null]);

    $this->post('/login', ['email' => 'fen@gmail.com', 'password' => 'anything'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

it('профилът подава hasPassword флага', function () {
    $google = User::factory()->create(['google_id' => 'g-1', 'password' => null]);

    $this->actingAs($google)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('hasPassword', false));

    $regular = User::factory()->create();

    $this->actingAs($regular)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('hasPassword', true));
});
