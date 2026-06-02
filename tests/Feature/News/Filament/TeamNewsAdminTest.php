<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Filament\Resources\TeamNewsItems\Pages\EditTeamNewsItem;
use App\Filament\Resources\TeamNewsItems\Pages\ListTeamNewsItems;
use App\Filament\Resources\TeamNewsSources\Pages\ListTeamNewsSources;
use App\Models\TeamNewsItem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('отваря списъка с новини', function () {
    $items = TeamNewsItem::factory()->count(3)->create();

    Livewire::test(ListTeamNewsItems::class)
        ->assertOk()
        ->assertCanSeeTableRecords($items);
});

it('филтрира по статус', function () {
    $approved = TeamNewsItem::factory()->create(['status' => NewsStatus::Approved->value]);
    $pending = TeamNewsItem::factory()->create(['status' => NewsStatus::Pending->value]);

    Livewire::test(ListTeamNewsItems::class)
        ->assertCanSeeTableRecords([$approved, $pending])
        ->filterTable('status', [NewsStatus::Approved->value])
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$pending]);
});

it('bulk одобрение променя статусите', function () {
    $items = TeamNewsItem::factory()->count(3)->create(['status' => NewsStatus::Pending->value]);

    Livewire::test(ListTeamNewsItems::class)
        ->callTableBulkAction('approveSelected', $items);

    $items->each(fn (TeamNewsItem $item) => expect($item->refresh()->status)->toBe(NewsStatus::Approved));
});

it('single одобрение променя статуса', function () {
    $item = TeamNewsItem::factory()->create(['status' => NewsStatus::Pending->value]);

    Livewire::test(ListTeamNewsItems::class)
        ->callTableAction('approve', $item);

    expect($item->refresh()->status)->toBe(NewsStatus::Approved);
});

it('re-enrich нулира българските полета и връща pending', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Преведено',
        'summary_bg' => 'Резюме',
        'classification' => NewsClassification::Race->value,
        'importance_score' => 4,
        'status' => NewsStatus::Approved->value,
    ]);

    Livewire::test(ListTeamNewsItems::class)
        ->callTableAction('reEnrich', $item);

    $item->refresh();
    expect($item->title_bg)->toBeNull()
        ->and($item->summary_bg)->toBeNull()
        ->and($item->classification)->toBeNull()
        ->and($item->importance_score)->toBeNull()
        ->and($item->status)->toBe(NewsStatus::Pending);
});

it('edit page запазва ръчни промени по превода', function () {
    $item = TeamNewsItem::factory()->create(['title_bg' => 'Старо', 'status' => NewsStatus::Pending->value]);

    Livewire::test(EditTeamNewsItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title_bg' => 'Ново заглавие', 'status' => NewsStatus::Approved->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $item->refresh();
    expect($item->title_bg)->toBe('Ново заглавие')
        ->and($item->status)->toBe(NewsStatus::Approved);
});

it('отваря списъка с източници', function () {
    Livewire::test(ListTeamNewsSources::class)->assertOk();
});
