<?php

declare(strict_types=1);

use App\Filament\Resources\NewsletterSubscribers\Pages\ListNewsletterSubscribers;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('отваря списъка с абонати', function () {
    $subs = collect([
        NewsletterSubscriber::create(['email' => 'a@x.bg', 'confirmed_at' => now()]),
        NewsletterSubscriber::create(['email' => 'b@x.bg']),
    ]);

    Livewire::test(ListNewsletterSubscribers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($subs);
});

it('филтрите по потвърждение работят без грешка и показват правилния запис', function () {
    $confirmed = NewsletterSubscriber::create(['email' => 'aa@x.bg', 'confirmed_at' => now()]);
    $pending = NewsletterSubscriber::create(['email' => 'bb@x.bg']);

    Livewire::test(ListNewsletterSubscribers::class)
        ->filterTable('confirmed')
        ->assertCanSeeTableRecords([$confirmed]);

    Livewire::test(ListNewsletterSubscribers::class)
        ->filterTable('unconfirmed')
        ->assertCanSeeTableRecords([$pending]);
});

it('експортира избраните като CSV', function () {
    $subs = collect([
        NewsletterSubscriber::create(['email' => 'a@x.bg', 'source' => 'footer', 'confirmed_at' => now()]),
        NewsletterSubscriber::create(['email' => 'b@x.bg', 'source' => 'homepage']),
    ]);

    Livewire::test(ListNewsletterSubscribers::class)
        ->callTableBulkAction('exportCsv', $subs)
        ->assertOk();
});
