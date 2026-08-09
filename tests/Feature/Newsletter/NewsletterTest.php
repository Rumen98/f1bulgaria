<?php

declare(strict_types=1);

use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\Mail;

it('записва имейл за бюлетина като директно активен', function () {
    $this->post('/newsletter/subscribe', ['email' => 'fan@example.bg', 'source' => 'footer'])
        ->assertRedirect();

    $sub = NewsletterSubscriber::first();
    expect($sub)->not->toBeNull()
        ->and($sub->email)->toBe('fan@example.bg')
        ->and($sub->source)->toBe('footer')
        ->and($sub->unsubscribe_token)->not->toBeNull()
        ->and(NewsletterSubscriber::active()->count())->toBe(1);
});

it('не праща имейл при записване (double opt-in е премахнат)', function () {
    Mail::fake();

    $this->post('/newsletter/subscribe', ['email' => 'fan@example.bg']);

    Mail::assertNothingQueued();
});

it('нормализира имейла и не дублира при повторно записване', function () {
    $this->post('/newsletter/subscribe', ['email' => 'Fan@Example.BG']);
    $this->post('/newsletter/subscribe', ['email' => 'fan@example.bg']);

    expect(NewsletterSubscriber::count())->toBe(1)
        ->and(NewsletterSubscriber::first()->email)->toBe('fan@example.bg');
});

it('активира наново отписан имейл при повторно записване', function () {
    NewsletterSubscriber::create([
        'email' => 'fan@example.bg',
        'unsubscribe_token' => 'tok111',
        'subscribed_at' => now()->subMonth(),
        'unsubscribed_at' => now()->subWeek(),
    ]);

    $this->post('/newsletter/subscribe', ['email' => 'fan@example.bg']);

    expect(NewsletterSubscriber::first()->unsubscribed_at)->toBeNull()
        ->and(NewsletterSubscriber::active()->count())->toBe(1);
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

it('старият confirm линк редиректва вместо 404 (legacy)', function () {
    NewsletterSubscriber::create([
        'email' => 'fan@example.bg',
        'unsubscribe_token' => 'tok123',
        'subscribed_at' => now(),
    ]);

    $this->get('/newsletter/confirm/tok123')->assertRedirect(route('home'));
});

it('старият confirm линк активира наново отписан абонат (изричен opt-in)', function () {
    $sub = NewsletterSubscriber::create([
        'email' => 'fan@example.bg',
        'unsubscribe_token' => 'tok999',
        'subscribed_at' => now()->subMonth(),
        'unsubscribed_at' => now()->subWeek(),
    ]);

    $this->get('/newsletter/confirm/tok999')->assertRedirect(route('home'));

    expect($sub->fresh()->unsubscribed_at)->toBeNull();
});

it('отписва чрез токен', function () {
    $sub = NewsletterSubscriber::create([
        'email' => 'fan@example.bg',
        'unsubscribe_token' => 'tok456',
        'subscribed_at' => now(),
    ]);

    $this->get('/newsletter/unsubscribe/tok456')->assertRedirect(route('home'));

    expect($sub->fresh()->unsubscribed_at)->not->toBeNull();
});

it('връща 404 за невалиден токен', function () {
    $this->get('/newsletter/unsubscribe/nonexistent')->assertNotFound();
});

it('scope active връща всички неотписани', function () {
    NewsletterSubscriber::create(['email' => 'a@x.bg']);
    NewsletterSubscriber::create(['email' => 'b@x.bg', 'unsubscribed_at' => now()]);

    expect(NewsletterSubscriber::active()->count())->toBe(1)
        ->and(NewsletterSubscriber::active()->first()->email)->toBe('a@x.bg');
});
