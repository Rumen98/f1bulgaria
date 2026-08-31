<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Значките на Хронометъра в профила следват feature флага: при изключен
 * модул профилът не бива да рекламира заключени награди за невидима функция.
 */
it('крие game значките при изключен Хронометър', function () {
    config(['features.game' => false]);
    $user = User::factory()->create();

    $this->get(route('profiles.show', $user))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.badges', fn ($badges) => collect($badges)
                ->every(fn ($badge) => ! str_starts_with($badge['slug'], 'game-')))
        );
});

it('показва game значките при включен Хронометър', function () {
    config(['features.game' => true]);
    $user = User::factory()->create();

    $this->get(route('profiles.show', $user))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.badges', fn ($badges) => collect($badges)
                ->contains(fn ($badge) => str_starts_with($badge['slug'], 'game-')))
        );
});
