<?php

declare(strict_types=1);

use App\Models\Badge;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function awardBadge(User $user, ?string $seenAt = null): Badge
{
    $badge = Badge::factory()->create();
    $user->badges()->attach($badge->id, ['awarded_at' => now(), 'seen_at' => $seenAt]);

    return $badge;
}

it('подава невидените значки като споделен prop на всяка страница', function () {
    $user = User::factory()->create();
    $badge = awardBadge($user);

    $this->actingAs($user)
        ->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page
            ->has('newBadges', 1)
            ->where('newBadges.0.slug', $badge->slug)
            ->where('newBadges.0.name', $badge->name)
        );
});

it('не подава вече видените значки', function () {
    $user = User::factory()->create();
    awardBadge($user, seenAt: now()->toDateTimeString());

    $this->actingAs($user)
        ->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page->has('newBadges', 0));
});

it('гостът получава празен списък', function () {
    $this->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page->has('newBadges', 0));
});

it('POST маркира значките като видени и тостът изчезва', function () {
    $user = User::factory()->create();
    awardBadge($user);

    $this->actingAs($user)->post('/badges/seen')->assertRedirect();

    $this->actingAs($user)
        ->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page->has('newBadges', 0));
});

it('POST не пипа чуждите значки', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    awardBadge($me);
    $otherBadge = awardBadge($other);

    $this->actingAs($me)->post('/badges/seen')->assertRedirect();

    $this->actingAs($other)
        ->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page
            ->has('newBadges', 1)
            ->where('newBadges.0.slug', $otherBadge->slug)
        );
});

it('гостът не може да маркира', function () {
    $this->post('/badges/seen')->assertRedirect('/login');
});
