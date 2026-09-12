<?php

declare(strict_types=1);

use App\Filament\Resources\GameFeedback\GameFeedbackResource;
use App\Filament\Resources\GameFeedback\Pages\ListGameFeedback;
use App\Filament\Resources\GameFeedback\Pages\ViewGameFeedback;
use App\Filament\Resources\GameSessions\GameSessionPresentation;
use App\Filament\Resources\GameSessions\GameSessionResource;
use App\Filament\Resources\GameSessions\Pages\ListGameSessions;
use App\Filament\Resources\GameSessions\Pages\ViewGameSession;
use App\Filament\Resources\GameSessions\RelationManagers\EventsRelationManager;
use App\Filament\Resources\GameSessions\Widgets\GameSessionsOverview;
use App\Models\GameFeedback;
use App\Models\GameSession;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('app.admin_access_key', '');
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('показва историята и различава старите неизвестни карания от финалите', function () {
    $old = GameSession::factory()->create();
    $race = GameSession::factory()->tracked()->race()->create([
        'status' => 'completed', 'lap_count' => 3, 'valid_lap_count' => 2,
        'invalid_lap_count' => 1, 'active_ms' => 280000, 'max_progress' => 1,
    ]);

    Livewire::test(ListGameSessions::class)->assertOk()
        ->assertCanSeeTableRecords([$old, $race])
        ->assertSee('Няма подробна история')
        ->assertSee('Завършено състезание')
        ->assertSee('3 общо · 2 чисти · 1 невалидни');

    Livewire::test(ViewGameSession::class, ['record' => $old->id])->assertOk()
        ->assertSee('Нямаме данни дали е потеглил, завършил или се е отказал.');

    expect(GameSessionPresentation::laps($old))->toBe('Няма данни')
        ->and(GameSessionResource::canCreate())->toBeFalse()
        ->and(GameSessionResource::canEdit($race))->toBeFalse()
        ->and(GameSessionResource::canDelete($race))->toBeFalse();
});

it('филтрира по устройство режим играч и реално отчетени финали', function () {
    $mobileRace = GameSession::factory()->tracked()->mobile()->race()->create(['lap_count' => 1, 'invalid_lap_count' => 1]);
    $desktopSolo = GameSession::factory()->tracked()->create();
    $old = GameSession::factory()->mobile()->create();

    Livewire::test(ListGameSessions::class)->filterTable('device', 'mobile')->filterTable('mode', 'race')
        ->assertCanSeeTableRecords([$mobileRace])->assertCanNotSeeTableRecords([$desktopSolo, $old])
        ->resetTableFilters()->filterTable('without_laps')
        ->assertCanSeeTableRecords([$desktopSolo])->assertCanNotSeeTableRecords([$mobileRace, $old])
        ->resetTableFilters()->filterTable('invalid_laps')
        ->assertCanSeeTableRecords([$mobileRace])->assertCanNotSeeTableRecords([$desktopSolo, $old])
        ->resetTableFilters()->filterTable('user_id', $desktopSolo->user_id)
        ->assertCanSeeTableRecords([$desktopSolo])->assertCanNotSeeTableRecords([$mobileRace, $old]);
});

it('оставя липсващия краен сигнал неизвестен вместо да обявява отказване', function () {
    $stale = GameSession::factory()->tracked()->create(['last_seen_at' => now()->subMinutes(3)]);
    $recent = GameSession::factory()->tracked()->create();
    $quit = GameSession::factory()->tracked()->create(['status' => 'quit', 'last_seen_at' => now()->subHour()]);
    $old = GameSession::factory()->create();

    Livewire::test(ListGameSessions::class)->filterTable('status', 'stale')
        ->assertCanSeeTableRecords([$stale])->assertCanNotSeeTableRecords([$recent, $quit, $old])
        ->resetTableFilters()->filterTable('status', 'active')
        ->assertCanSeeTableRecords([$recent])->assertCanNotSeeTableRecords([$stale, $quit, $old]);

    expect(GameSessionPresentation::status($stale))->toBe('Няма скорошни данни')
        ->and(GameSessionPresentation::interpretation($stale))->toContain('Това не доказва отказване.');
});

it('прилага периода по календарните дни в София', function () {
    $inside = GameSession::factory()->tracked()->create(['created_at' => '2026-09-11 21:30:00']);
    $before = GameSession::factory()->tracked()->create(['created_at' => '2026-09-11 20:59:59']);
    $after = GameSession::factory()->tracked()->create(['created_at' => '2026-09-12 21:00:00']);

    Livewire::test(ListGameSessions::class)->filterTable('period', ['from' => '2026-09-12', 'until' => '2026-09-12'])
        ->assertCanSeeTableRecords([$inside])->assertCanNotSeeTableRecords([$before, $after]);
});

it('подрежда хронологията по клиентската последователност и показва периодичните данни по избор', function () {
    $session = GameSession::factory()->tracked()->create();
    $finish = $session->events()->create(['sequence' => 3, 'type' => 'lap_completed', 'active_ms' => 85000, 'received_at' => now()->subMinute(), 'data' => ['lap_ms' => 83456, 'lap_valid' => false, 'quality_tier' => 'auto-balanced', 'render_scale' => 0.75, 'frame_ms' => 20]]);
    $start = $session->events()->create(['sequence' => 1, 'type' => 'started', 'active_ms' => 0, 'received_at' => now(), 'data' => []]);
    $heartbeat = $session->events()->create(['sequence' => 2, 'type' => 'heartbeat', 'active_ms' => 20000, 'received_at' => now(), 'data' => []]);

    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $session, 'pageClass' => ViewGameSession::class])
        ->assertOk()->assertCanSeeTableRecords([$start, $finish], inOrder: true)->assertCanNotSeeTableRecords([$heartbeat])
        ->assertSee('1:23.456')->assertSee('Невалидна обиколка')->assertSee('50 кадъра/сек')->assertSee('Рендериране 75%')
        ->removeTableFilter('milestones')->assertCanSeeTableRecords([$start, $heartbeat, $finish], inOrder: true);
});

it('намира неуспешен запис без да заличава завършената обиколка', function () {
    $failed = GameSession::factory()->tracked()->create(['status' => 'completed', 'lap_count' => 1, 'valid_lap_count' => 1]);
    $failed->events()->create(['sequence' => 3, 'type' => 'lap_save_failed', 'active_ms' => 90000, 'received_at' => now(), 'data' => ['error_code' => 'request_failed']]);
    $other = GameSession::factory()->tracked()->create();

    Livewire::test(ListGameSessions::class)->filterTable('save_failed')
        ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$other])->assertSee('1 общо · 1 чисти · 0 невалидни');
});

it('брои филтрираните карания с отделни знаменатели и изключва старите от процента на финали', function () {
    GameSession::factory()->count(2)->mobile()->create();
    $mobileRace = GameSession::factory()->tracked()->mobile()->race()->create(['status' => 'completed', 'lap_count' => 3, 'valid_lap_count' => 2, 'invalid_lap_count' => 1, 'active_ms' => 180000]);
    GameSession::factory()->tracked()->mobile()->create(['active_ms' => 60000]);
    GameSession::factory()->tracked()->create(['status' => 'completed', 'lap_count' => 1, 'valid_lap_count' => 1, 'active_ms' => 90000]);
    $mobileRace->events()->create(['sequence' => 1, 'type' => 'moving', 'active_ms' => 1000, 'received_at' => now(), 'data' => ['speed' => 5]]);

    $widget = Livewire::test(GameSessionsOverview::class, ['tableFilters' => ['device' => ['value' => 'mobile']]])->assertOk();
    $method = new ReflectionMethod(GameSessionsOverview::class, 'getStats');
    $stats = collect($method->invoke($widget->instance()))->keyBy(fn ($stat): string => $stat->getLabel());

    expect($stats['Опити / играчи']->getValue())->toBe('4 / 4')
        ->and($stats['Потеглили карания']->getValue())->toBe('1 / 2')
        ->and($stats['Карания с поне 1 финал']->getValue())->toBe(1)
        ->and($stats['Завършени обиколки']->getValue())->toBe(3)
        ->and($stats['Соло: с финал / стартове']->getValue())->toBe('0 / 1')
        ->and($stats['Състезание: с обиколка / стартове']->getValue())->toBe('1 / 1')
        ->and($stats['Телефон: с финал / стартове']->getValue())->toBe('1 / 2')
        ->and($stats['Компютър: с финал / стартове']->getValue())->toBe('0 / 0');
    $widget->assertSee('50% от подробните карания');
});

it('показва безопасно празна статистика', function () {
    Livewire::test(GameSessionsOverview::class)->assertOk()->assertSee('Все още няма подробни карания')->assertSee('Няма данни');
});

it('показва мнението с контекста на конкретното каране и филтрира само съответния играч', function () {
    $session = GameSession::factory()->tracked()->mobile()->create();
    $feedback = GameFeedback::factory()->forSession($session)->create(['rating' => 2, 'reason' => 'controls', 'comment' => 'Трудно завивам на телефона.']);
    $other = GameFeedback::factory()->create(['rating' => 5]);

    Livewire::test(ListGameFeedback::class)->assertOk()->filterTable('user_id', $session->user_id)
        ->assertCanSeeTableRecords([$feedback])->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()->filterTable('low_ratings')
        ->assertCanSeeTableRecords([$feedback])->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()->filterTable('device', 'mobile')
        ->assertCanSeeTableRecords([$feedback])->assertCanNotSeeTableRecords([$other]);
    Livewire::test(ViewGameFeedback::class, ['record' => $feedback->id])->assertOk()
        ->assertSee('Трудно управление')->assertSee('Трудно завивам на телефона.')->assertSee('Телефон');

    expect(GameFeedbackResource::canCreate())->toBeFalse()
        ->and(GameFeedbackResource::canEdit($feedback))->toBeFalse()
        ->and(GameFeedbackResource::canDelete($feedback))->toBeFalse();
});

it('показва общите мнения без измислено устройство и екранира HTML коментари', function () {
    $feedback = GameFeedback::factory()->create(['comment' => '<script>alert("feedback")</script>']);

    Livewire::test(ViewGameFeedback::class, ['record' => $feedback->id])->assertOk()
        ->assertSee('Общо мнение след предишно играене')
        ->assertSee('<script>alert("feedback")</script>')
        ->assertDontSee('<script>alert("feedback")</script>', escape: false);
});

it('отказва достъп до подробните карания и мнения на обикновени потребители', function () {
    $session = GameSession::factory()->tracked()->create();
    $feedback = GameFeedback::factory()->forSession($session)->create();
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    expect(GameSessionResource::canViewAny())->toBeFalse()->and(GameFeedbackResource::canViewAny())->toBeFalse();
    $this->get(GameSessionResource::getUrl('index'))->assertForbidden();
    $this->get(GameSessionResource::getUrl('view', ['record' => $session]))->assertForbidden();
    $this->get(GameFeedbackResource::getUrl('index'))->assertForbidden();
    $this->get(GameFeedbackResource::getUrl('view', ['record' => $feedback]))->assertForbidden();
});
