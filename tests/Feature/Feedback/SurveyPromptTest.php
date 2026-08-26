<?php

declare(strict_types=1);

use App\Models\SurveyResponse;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('не подканя гост', function () {
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('survey.shouldPrompt', false));
});

it('не подканя акаунт, по-млад от 14 дни', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('survey.shouldPrompt', false));
});

it('подканя акаунт на поне 14 дни без предишно взаимодействие', function () {
    $user = User::factory()->create(['created_at' => now()->subDays(15)]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('survey.shouldPrompt', true));
});

it('не подканя при скорошен отговор', function () {
    $user = User::factory()->create(['created_at' => now()->subYear()]);
    SurveyResponse::factory()->for($user)->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('survey.shouldPrompt', false));
});

it('не подканя при скорошно скриване на картата', function () {
    $user = User::factory()->create(['created_at' => now()->subYear()]);
    SurveyResponse::factory()->for($user)->dismissed()->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('survey.shouldPrompt', false));
});

it('подканя отново при взаимодействие отпреди повече от 6 месеца', function () {
    $user = User::factory()->create(['created_at' => now()->subYear()]);
    SurveyResponse::factory()->for($user)->create(['created_at' => now()->subMonths(7)]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('survey.shouldPrompt', true));
});
