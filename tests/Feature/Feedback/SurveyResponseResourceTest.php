<?php

declare(strict_types=1);

use App\Filament\Resources\SurveyResponses\Pages\ListSurveyResponses;
use App\Filament\Resources\SurveyResponses\SurveyResponseResource;
use App\Models\SurveyResponse;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('админ отваря списъка с отговори', function () {
    $this->get(SurveyResponseResource::getUrl('index'))->assertOk();
});

it('показва отговорите в таблицата', function () {
    $responses = SurveyResponse::factory()->count(3)->create();

    Livewire::test(ListSurveyResponses::class)
        ->assertOk()
        ->assertCanSeeTableRecords($responses);
});

it('ресурсът е само за четене — няма създаване', function () {
    expect(SurveyResponseResource::canCreate())->toBeFalse();
});

it('админ може да изтрие отговор от таблицата (модерация)', function () {
    $response = SurveyResponse::factory()->create();

    Livewire::test(ListSurveyResponses::class)
        ->callAction(TestAction::make('delete')->table($response));

    expect(SurveyResponse::count())->toBe(0);
});

it('админ може да изтрие отговори масово', function () {
    $responses = SurveyResponse::factory()->count(3)->create();

    Livewire::test(ListSurveyResponses::class)
        ->selectTableRecords($responses->pluck('id')->toArray())
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(SurveyResponse::count())->toBe(0);
});

it('не-админ получава 403 за списъка', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get(SurveyResponseResource::getUrl('index'))->assertForbidden();
});
