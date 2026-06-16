<?php

declare(strict_types=1);

use App\Models\NewsletterSubscriber;

it('записва имейл за бюлетина', function () {
    $this->post('/newsletter/subscribe', ['email' => 'fan@example.bg', 'source' => 'footer'])
        ->assertRedirect();

    $sub = NewsletterSubscriber::first();
    expect($sub)->not->toBeNull()
        ->and($sub->email)->toBe('fan@example.bg')
        ->and($sub->source)->toBe('footer')
        ->and($sub->confirmation_token)->not->toBeNull()
        ->and($sub->confirmed_at)->toBeNull(); // double opt-in: още непотвърден
});

it('нормализира имейла и не дублира при повторно записване', function () {
    $this->post('/newsletter/subscribe', ['email' => 'Fan@Example.BG']);
    $this->post('/newsletter/subscribe', ['email' => 'fan@example.bg']);

    expect(NewsletterSubscriber::count())->toBe(1)
        ->and(NewsletterSubscriber::first()->email)->toBe('fan@example.bg');
});

it('отхвърля невалиден имейл', function () {
    $this->from('/')->post('/newsletter/subscribe', ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    expect(NewsletterSubscriber::count())->toBe(0);
});

it('отхвърля празен имейл', function () {
    $this->from('/')->post('/newsletter/subscribe', ['email' => ''])
        ->assertSessionHasErrors('email');
});

it('ограничава честотата на записванията (anti-spam throttle)', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/newsletter/subscribe', ['email' => "fan{$i}@example.bg"]);
    }

    $this->post('/newsletter/subscribe', ['email' => 'spam@example.bg'])->assertStatus(429);
});

it('потвърждава абонамента чрез токен', function () {
    $sub = NewsletterSubscriber::create([
        'email' => 'fan@example.bg',
        'confirmation_token' => 'tok123',
        'subscribed_at' => now(),
    ]);

    $this->get('/newsletter/confirm/tok123')->assertRedirect(route('home'));

    expect($sub->fresh()->confirmed_at)->not->toBeNull();
});

it('отписва чрез токен', function () {
    $sub = NewsletterSubscriber::create([
        'email' => 'fan@example.bg',
        'confirmation_token' => 'tok456',
        'subscribed_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->get('/newsletter/unsubscribe/tok456')->assertRedirect(route('home'));

    expect($sub->fresh()->unsubscribed_at)->not->toBeNull();
});

it('връща 404 за невалиден токен', function () {
    $this->get('/newsletter/confirm/nonexistent')->assertNotFound();
});

it('scope active връща само потвърдените и неотписани', function () {
    NewsletterSubscriber::create(['email' => 'a@x.bg', 'confirmed_at' => now()]);                          // активен
    NewsletterSubscriber::create(['email' => 'b@x.bg', 'confirmed_at' => null]);                           // непотвърден
    NewsletterSubscriber::create(['email' => 'c@x.bg', 'confirmed_at' => now(), 'unsubscribed_at' => now()]); // отписан

    expect(NewsletterSubscriber::active()->count())->toBe(1)
        ->and(NewsletterSubscriber::active()->first()->email)->toBe('a@x.bg');
});
