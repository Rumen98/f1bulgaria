<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    // Не към `dashboard` (публичния календар), а към мястото с действие —
    // следващия отворен кръг, или класирането, когато такъв няма.
    // Пълното покритие на избора е в RegistrationLandingTest.
    $response->assertRedirect(route('leaderboard', absolute: false));
});
