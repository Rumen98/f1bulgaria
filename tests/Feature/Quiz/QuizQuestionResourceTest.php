<?php

declare(strict_types=1);

use App\Filament\Resources\QuizQuestionResource;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('админ отваря списъка с въпроси', function () {
    $this->get(QuizQuestionResource::getUrl('index'))->assertOk();
});

it('админ отваря формата за създаване', function () {
    $this->get(QuizQuestionResource::getUrl('create'))->assertOk();
});
