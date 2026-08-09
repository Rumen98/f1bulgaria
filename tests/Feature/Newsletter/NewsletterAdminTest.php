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
        NewsletterSubscriber::create(['email' => 'a@x.bg']),
        NewsletterSubscriber::create(['email' => 'b@x.bg']),
    ]);

    Livewire::test(ListNewsletterSubscribers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($subs);
});

it('филтърът по отписани работи без грешка и показва правилния запис', function () {
    NewsletterSubscriber::create(['email' => 'aa@x.bg']);
    $unsubscribed = NewsletterSubscriber::create(['email' => 'bb@x.bg', 'unsubscribed_at' => now()]);

    Livewire::test(ListNewsletterSubscribers::class)
        ->filterTable('unsubscribed')
        ->assertCanSeeTableRecords([$unsubscribed]);
});

it('експортира избраните като CSV', function () {
    $subs = collect([
        NewsletterSubscriber::create(['email' => 'a@x.bg', 'source' => 'footer']),
        NewsletterSubscriber::create(['email' => 'b@x.bg', 'source' => 'homepage']),
    ]);

    Livewire::test(ListNewsletterSubscribers::class)
        ->callTableBulkAction('exportCsv', $subs)
        ->assertOk();
});
