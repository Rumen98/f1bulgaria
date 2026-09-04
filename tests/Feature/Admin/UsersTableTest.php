<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Tables\Table;

it('подрежда потребителите по регистрация без ръчно сортиране', function () {
    $table = UserResource::table(new Table(new ListUsers));

    expect($table->getDefaultSortColumn())->toBe('created_at')
        ->and($table->getDefaultSortDirection())->toBe('desc');
});

it('показва достатъчно потребители на страница, за да не се прелиства', function () {
    $table = UserResource::table(new Table(new ListUsers));

    expect($table->getDefaultPaginationPageOption())->toBe(50)
        ->and($table->getPaginationPageOptions())->toContain('all');
});

it('значката в менюто показва точния брой потребители', function () {
    User::factory()->count(7)->create();

    expect(UserResource::getNavigationBadge())->toBe('7');
});

it('подзаглавието показва точния брой — видимо и на телефон', function () {
    User::factory()->count(5)->create();

    expect((new ListUsers)->getSubheading())->toBe('Общо 5 потребители');
});

it('подзаглавието отделя блокираните, когато има такива', function () {
    User::factory()->count(4)->create();
    User::factory()->count(2)->create(['banned_at' => now()]);

    expect((new ListUsers)->getSubheading())->toBe('Общо 6 потребители, от които 2 блокирани');
});
