<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Season;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->season = Season::factory()->current()->create(['year' => 2026]);
    $this->race = Race::factory()->create([
        'season_id' => $this->season->id,
        'name' => 'Гран при на Бахрейн',
        'circuit' => 'Bahrain International Circuit',
        'country' => 'Bahrain',
        'race_datetime_utc' => Carbon::create(2026, 3, 8, 15),
    ]);
    RaceSession::factory()->create([
        'race_id' => $this->race->id,
        'type' => SessionType::Qualifying->value,
        'scheduled_at_utc' => Carbon::create(2026, 3, 7, 16),
    ]);
    RaceSession::factory()->create([
        'race_id' => $this->race->id,
        'type' => SessionType::Race->value,
        'scheduled_at_utc' => Carbon::create(2026, 3, 8, 15),
    ]);
});

it('връща валиден iCalendar с правилен content-type', function () {
    $response = $this->get('/calendar.ics');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/calendar');

    $body = $response->getContent();
    expect($body)->toContain('BEGIN:VCALENDAR')
        ->and($body)->toContain('END:VCALENDAR')
        ->and($body)->toContain('BEGIN:VEVENT')
        ->and($body)->toContain('UID:padok-'.$this->race->id.'-race@padok.bg')
        ->and($body)->toContain('CATEGORIES:F1')
        ->and($body)->toContain('DTSTART:20260308T150000Z');
});

it('включва събитие за всяка сесия на състезанието', function () {
    $body = $this->get('/calendar.ics')->getContent();

    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(2) // квалификация + състезание
        ->and($body)->toContain('-qualifying@padok.bg')
        ->and($body)->toContain('-race@padok.bg');
});

it('връща .ics за конкретен пилот от текущия сезон', function () {
    $driver = Driver::factory()->create(['season_id' => $this->season->id, 'slug' => 'max-verstappen', 'first_name' => 'Max', 'last_name' => 'Verstappen']);

    $response = $this->get('/calendar/max-verstappen.ics');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($response->getContent())->toContain('BEGIN:VCALENDAR');
});

it('връща .ics за конкретен отбор от текущия сезон', function () {
    Constructor::factory()->create(['season_id' => $this->season->id, 'slug' => 'ferrari', 'name' => 'Ferrari']);

    $this->get('/calendar/team/ferrari.ics')
        ->assertOk()
        ->assertSee('BEGIN:VCALENDAR', false);
});

it('брандира календара като „Падок“, без „F1“ в името и PRODID', function () {
    Driver::factory()->create(['season_id' => $this->season->id, 'slug' => 'max-verstappen', 'first_name' => 'Max', 'last_name' => 'Verstappen']);
    Constructor::factory()->create(['season_id' => $this->season->id, 'slug' => 'ferrari', 'name' => 'Ferrari']);

    $feeds = [
        '/calendar.ics' => 'X-WR-CALNAME:Падок — Календар',
        '/calendar/max-verstappen.ics' => 'X-WR-CALNAME:Падок — Календар на ',
        '/calendar/team/ferrari.ics' => 'X-WR-CALNAME:Падок — Календар на Ferrari',
    ];

    foreach ($feeds as $url => $expectedName) {
        $body = $this->get($url)->assertOk()->getContent();

        expect($body)->toContain($expectedName)
            ->and($body)->toContain('PRODID:-//Падок//Календар//BG')
            ->and($body)->not->toContain('F1 Календар');
    }
});

it('връща 404 за несъществуващ пилот', function () {
    $this->get('/calendar/nonexistent.ics')->assertNotFound();
});

it('връща 404 за несъществуващ отбор', function () {
    $this->get('/calendar/team/nonexistent.ics')->assertNotFound();
});
