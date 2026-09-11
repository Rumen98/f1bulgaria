<?php

declare(strict_types=1);

use App\Filament\Resources\GamePlayers\GamePlayerResource;
use App\Filament\Resources\GamePlayers\Pages\ListGamePlayers;
use App\Filament\Resources\GamePlayers\Tables\GamePlayersTable;
use App\Models\GameLapRecord;
use App\Models\GameSession;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('показва само потребителите, пробвали играта', function () {
    $starter = User::factory()->create(['name' => 'Само стартирал']);
    GameSession::factory()->for($starter)->create();

    $lapper = User::factory()->create(['name' => 'Стара обиколка']);
    GameLapRecord::factory()->for($lapper)->create();

    $bystander = User::factory()->create(['name' => 'Никога не е карал']);

    Livewire::test(ListGamePlayers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$starter, $lapper])
        ->assertCanNotSeeTableRecords([$bystander]);
});

it('слива стартовете и обиколките в първо/последно каране и агрегатите', function () {
    $player = User::factory()->create(['name' => 'Пълен профил']);
    GameLapRecord::factory()->for($player)->create(['lap_ms' => 95000, 'created_at' => now()->subDays(10)]);
    GameLapRecord::factory()->for($player)->create(['lap_ms' => 91000, 'track_slug' => 'spa', 'created_at' => now()->subDays(9)]);
    // Отхвърлена от преиграването — не е „най-добра", колкото и бърза да е.
    GameLapRecord::factory()->for($player)->create(['lap_ms' => 60000, 'verify_status' => 'rejected', 'created_at' => now()->subDays(8)]);
    GameSession::factory()->for($player)->mobile()->create(['created_at' => now()->subDay()]);
    GameSession::factory()->for($player)->create(['created_at' => now()->subHour()]);

    // Редовете идват през заявката на таблицата — с подзаявките и агрегатите
    // на колоните, точно както ги вижда админът.
    $row = Livewire::test(ListGamePlayers::class)
        ->instance()
        ->getTableRecords()
        ->firstWhere('id', $player->id);

    expect($row)->not->toBeNull();

    expect((string) $row->first_played_at)->toStartWith(now()->subDays(10)->format('Y-m-d'))
        ->and((string) $row->last_played_at)->toStartWith(now()->subHour()->format('Y-m-d'))
        ->and((int) $row->game_sessions_count)->toBe(2)
        ->and((int) $row->mobile_sessions_count)->toBe(1)
        ->and((int) $row->game_lap_records_count)->toBe(3)
        ->and((int) $row->tracks_count)->toBe(2)
        ->and((int) $row->game_lap_records_min_lap_ms)->toBe(91000);
});

it('филтрира стартиралите без завършена обиколка и каралите на телефон', function () {
    $quitter = User::factory()->create(['name' => 'Отказал се']);
    GameSession::factory()->for($quitter)->mobile()->create();

    $finisher = User::factory()->create(['name' => 'Завършил']);
    GameSession::factory()->for($finisher)->create();
    GameLapRecord::factory()->for($finisher)->create();

    Livewire::test(ListGamePlayers::class)
        ->filterTable('without_laps')
        ->assertCanSeeTableRecords([$quitter])
        ->assertCanNotSeeTableRecords([$finisher])
        ->resetTableFilters()
        ->filterTable('mobile')
        ->assertCanSeeTableRecords([$quitter])
        ->assertCanNotSeeTableRecords([$finisher])
        ->resetTableFilters()
        ->filterTable('with_laps')
        ->assertCanSeeTableRecords([$finisher])
        ->assertCanNotSeeTableRecords([$quitter]);
});

it('сортира по последно каране и търси по име', function () {
    $recent = User::factory()->create(['name' => 'Скорошен']);
    GameSession::factory()->for($recent)->create(['created_at' => now()->subMinute()]);
    $older = User::factory()->create(['name' => 'Отдавнашен']);
    GameSession::factory()->for($older)->create(['created_at' => now()->subWeek()]);

    Livewire::test(ListGamePlayers::class)
        ->assertCanSeeTableRecords([$recent, $older], inOrder: true)
        ->sortTable('game_sessions_count', 'desc')
        ->assertCanSeeTableRecords([$recent, $older])
        ->searchTable('Отдавн')
        ->assertCanSeeTableRecords([$older])
        ->assertCanNotSeeTableRecords([$recent]);
});

it('значката в менюто брои пробвалите играта', function () {
    GameSession::factory()->count(2)->create();
    GameLapRecord::factory()->create();
    User::factory()->create();

    expect(GamePlayerResource::getNavigationBadge())->toBe('3');
});

it('експортира избраните като CSV с форматирано най-добро време', function () {
    $player = User::factory()->create(['name' => 'Експорт', 'email' => 'export@example.bg']);
    GameLapRecord::factory()->for($player)->create(['lap_ms' => 83456]);
    GameSession::factory()->for($player)->create();

    Livewire::test(ListGamePlayers::class)
        ->callTableBulkAction('exportCsv', [$player])
        ->assertOk()
        ->assertFileDownloaded('game-players.csv');

    expect(GamePlayersTable::formatLap(83456))->toBe('1:23.456');
});

it('обезврежда формули в CSV клетките', function () {
    expect(GamePlayersTable::csvSafe('=HYPERLINK("http://x")'))->toBe("'=HYPERLINK(\"http://x\")")
        ->and(GamePlayersTable::csvSafe('+1'))->toBe("'+1")
        ->and(GamePlayersTable::csvSafe('Иван'))->toBe('Иван')
        ->and(GamePlayersTable::csvSafe(null))->toBe('');
});
