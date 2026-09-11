<?php

declare(strict_types=1);

use App\Enums\ChannelPostKind;
use App\Models\ChannelPost;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceDataRecap;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\LlmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['features.data_recap' => true]);
    // Историческите методи кешират за 24 часа — без това вторият тест би
    // получил отговора на първия.
    Cache::flush();

    // Разказът минава през LLM; в тестовете не искаме нито заявка, нито
    // зависимост от отговор — падането към шаблона е напълно валиден път.
    $this->mock(LlmClient::class, function ($mock) {
        $mock->shouldReceive('completeWithTool')->andThrow(new RuntimeException('без LLM в тестове'))->byDefault();
    });
});

/** Състезание в прозореца на командата (между 3 и 36 часа след старта). */
function recapRace(int $hoursAgo = 5): Race
{
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);
    $constructor = Constructor::factory()->create(['season_id' => $season->id, 'color_hex' => '#FF8000']);

    foreach (recapDrivers() as $i => $driver) {
        Driver::factory()->create([
            'season_id' => $season->id,
            'constructor_id' => $constructor->id,
            'permanent_number' => $driver['driver_number'],
            'driver_code' => $driver['name_acronym'],
            'slug' => 'driver-'.$i,
            'first_name' => 'Пилот',
            'last_name' => (string) $driver['driver_number'],
        ]);
    }

    return Race::factory()->create([
        'season_id' => $season->id,
        'round' => 14,
        'name' => 'Italian Grand Prix',
        'jolpica_id' => 'monza',
        'race_datetime_utc' => now()->subHours($hoursAgo),
    ]);
}

/** @return array<int, array<string, mixed>> */
function recapDrivers(): array
{
    $out = [];

    // Санитарните граници искат поне 12 пилота — по-малко значи счупен набор.
    foreach (range(1, 14) as $n) {
        $out[] = [
            'driver_number' => $n,
            'name_acronym' => 'D'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'full_name' => "Driver {$n}",
            'team_name' => 'Test Racing',
            'team_colour' => 'FF8000',
        ];
    }

    return $out;
}

/**
 * Пълен фалшив уикенд: 14 пилота, 25 обиколки, стинтове, питове, метео,
 * решетка и шампионат.
 */
function fakeRaceWeekend(array $overrides = []): void
{
    $laps = [];
    $stints = [];
    $pits = [];
    $result = [];
    $grid = [];
    $championship = [];

    foreach (recapDrivers() as $index => $driver) {
        $n = $driver['driver_number'];

        foreach (range(1, 25) as $lap) {
            $laps[] = [
                'driver_number' => $n,
                'lap_number' => $lap,
                // Всеки следващ пилот е с малко по-бавно темпо — така
                // подредбата по кумулативно време е предвидима.
                'lap_duration' => 80.0 + $index * 0.4 + ($lap === 13 ? 22.0 : 0.0),
                'date_start' => now()->subHours(5)->addSeconds($lap * 80)->toIso8601String(),
                'st_speed' => 330 - $index,
                'is_pit_out_lap' => $lap === 14,
                'duration_sector_1' => 26.0,
                'duration_sector_2' => 27.0,
                'duration_sector_3' => 27.0,
            ];
        }

        $stints[] = ['driver_number' => $n, 'stint_number' => 1, 'compound' => 'MEDIUM', 'lap_start' => 1, 'lap_end' => 13, 'tyre_age_at_start' => 0];
        $stints[] = ['driver_number' => $n, 'stint_number' => 2, 'compound' => 'HARD', 'lap_start' => 14, 'lap_end' => 25, 'tyre_age_at_start' => 0];
        $pits[] = ['driver_number' => $n, 'lap_number' => 13, 'lane_duration' => 21.0 + $index * 0.1, 'stop_duration' => null, 'date' => '2026-09-06T13:40:00+00:00'];

        $result[] = [
            'driver_number' => $n,
            'position' => $index + 1,
            'gap_to_leader' => $index === 0 ? 0 : round($index * 4.2, 3),
            'number_of_laps' => 25,
            'dnf' => false,
            'dns' => false,
            'dsq' => false,
        ];

        // Решетката е обърната спрямо финала — така има ясен „катерач“.
        $grid[] = ['driver_number' => $n, 'position' => 14 - $index, 'lap_duration' => 79.0];

        $championship[] = [
            'driver_number' => $n,
            'position_current' => $index + 1,
            'position_start' => $index + 1,
            'points_current' => 300 - $index * 20,
            'points_start' => 275 - $index * 20,
        ];
    }

    Http::fake(array_merge([
        '*/sessions*' => Http::response([
            ['session_key' => 7001, 'circuit_key' => 39, 'session_name' => 'Qualifying', 'session_type' => 'Qualifying', 'date_start' => now()->subHours(29)->toIso8601String(), 'meeting_key' => 900],
            ['session_key' => 7002, 'circuit_key' => 39, 'session_name' => 'Race', 'session_type' => 'Race', 'date_start' => now()->subHours(5)->toIso8601String(), 'meeting_key' => 900],
        ]),
        '*/drivers*' => Http::response(recapDrivers()),
        '*/session_result*' => Http::response($result),
        '*/laps*' => Http::response($laps),
        '*/stints*' => Http::response($stints),
        '*/pit*' => Http::response($pits),
        '*/race_control*' => Http::response([
            ['date' => '2026-09-06T13:20:00+00:00', 'category' => 'SafetyCar', 'message' => 'SAFETY CAR DEPLOYED', 'lap_number' => 8],
            ['date' => '2026-09-06T13:26:00+00:00', 'category' => 'Flag', 'flag' => 'GREEN', 'message' => 'SAFETY CAR IN THIS LAP', 'lap_number' => 10],
        ]),
        '*/weather*' => Http::response(array_map(fn (int $m) => [
            'date' => now()->subHours(6)->addMinutes($m)->toIso8601String(),
            'track_temperature' => 40.0 + $m * 0.1,
            'air_temperature' => 27.0,
            'rainfall' => 0,
        ], range(0, 20))),
        '*/starting_grid*' => Http::response($grid),
        '*/overtakes*' => Http::response([
            ['overtaking_driver_number' => 2, 'overtaken_driver_number' => 3, 'date' => '2026-09-06T13:30:00+00:00', 'position' => 2],
        ]),
        '*/championship_drivers*' => Http::response($championship),
        '*/championship_teams*' => Http::response([
            ['team_name' => 'Test Racing', 'position_current' => 1, 'position_start' => 1, 'points_current' => 500, 'points_start' => 450],
        ]),
        // Един пилот кара 4 минути на под секунда зад предния — това е
        // единственото, което intervals дава, а обиколките не могат.
        '*/intervals*' => Http::response(array_map(fn (int $i) => [
            'driver_number' => 2,
            'date' => now()->subHours(5)->addSeconds(400 + $i * 4)->toIso8601String(),
            'interval' => 0.6,
            'gap_to_leader' => 4.2,
        ], range(0, 60))),
        '*/team_radio*' => Http::response(array_map(fn (int $i) => [
            'driver_number' => 1,
            'date' => now()->subHours(5)->addSeconds(8 * 80 + $i)->toIso8601String(),
            'recording_url' => "https://example.test/radio-{$i}.mp3",
        ], range(0, 3))),
        '*/car_data*' => Http::response(telemetrySamples()),
        '*/location*' => Http::response(locationSamples()),
        '*multiviewer*' => Http::response([
            'x' => array_map(fn (int $i) => cos($i / 40 * M_PI * 2) * 1000, range(0, 79)),
            'y' => array_map(fn (int $i) => sin($i / 40 * M_PI * 2) * 600, range(0, 79)),
            'rotation' => 95,
            'corners' => [
                ['number' => 1, 'trackPosition' => ['x' => 1000, 'y' => 0]],
                ['number' => 2, 'trackPosition' => ['x' => -1000, 'y' => 0]],
            ],
        ]),
    ], $overrides));
}

/** @return array<int, array<string, mixed>> */
function telemetrySamples(): array
{
    return array_map(fn (int $i) => [
        'date' => now()->subHours(5)->addSeconds(80)->addMilliseconds($i * 270)->format('Y-m-d\TH:i:s.vP'),
        // Права с ускорение и спирачка в завоя — за да има какво да се види.
        'speed' => (int) (200 + 130 * abs(sin($i / 30))),
        'throttle' => $i % 30 < 20 ? 100 : 0,
        'brake' => $i % 30 >= 20 && $i % 30 < 25 ? 100 : 0,
        'n_gear' => 1 + ($i % 8),
        'rpm' => 9000 + $i % 3000,
        'drs' => null,
    ], range(0, 299));
}

/** @return array<int, array<string, mixed>> */
function locationSamples(): array
{
    return array_map(fn (int $i) => [
        'date' => now()->subHours(5)->addSeconds(80)->addMilliseconds($i * 270)->format('Y-m-d\TH:i:s.vP'),
        'x' => cos($i / 300 * M_PI * 2) * 1000,
        'y' => sin($i / 300 * M_PI * 2) * 600,
        'z' => 100,
    ], range(0, 299));
}

it('сглобява рекап от данните на OpenF1 и го публикува', function () {
    $race = recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();

    expect($recap)->not->toBeNull()
        ->and($recap->generated_at)->not->toBeNull()
        ->and($recap->openf1_session_key)->toBe(7002)
        // Решетката виси при квалификацията — ключът ѝ трябва да е намерен.
        ->and($recap->openf1_quali_session_key)->toBe(7001)
        ->and($recap->headline)->toContain('Гран при на Италия')
        ->and($recap->facts['top_speed']['kmh'])->toBe(330)
        ->and($recap->facts['laps'])->toBe(25)
        ->and($recap->facts['bullets'])->not->toBeEmpty()
        // Победителят е тръгнал 14-и и е финиширал първи.
        ->and($recap->facts['movers']['climber']['gained'])->toBe(13)
        ->and($recap->charts)->toHaveKeys(['positions', 'trace', 'stints', 'grid_vs_finish', 'championship', 'pace']);
});

it('прави статия в новините и пост в канала', function () {
    $race = recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $item = TeamNewsItem::query()->first();
    $post = ChannelPost::query()->where('kind', ChannelPostKind::F1DataRecap->value)->first();

    expect($item)->not->toBeNull()
        ->and($item->title_bg)->toContain('Гран при на Италия')
        // Под прага на канала за новини — рекапът си има собствен пост.
        ->and($item->importance_score)->toBe(3)
        ->and($post)->not->toBeNull()
        ->and($post->body)->toContain('Графиките в Падок')
        ->and($post->body)->toContain('OpenF1');
});

it('второто пускане не прави втора статия и втори пост', function () {
    recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();
    $this->artisan('padok:race-data-recap', ['--rebuild' => true])->assertSuccessful();

    expect(RaceDataRecap::query()->count())->toBe(1)
        ->and(TeamNewsItem::query()->count())->toBe(1)
        ->and(ChannelPost::query()->where('kind', ChannelPostKind::F1DataRecap->value)->count())->toBe(1);
});

it('не публикува нищо, ако данните не минат санитарните граници', function () {
    $race = recapRace();
    // Три пилота е под долната граница — наборът е счупен, не рядък.
    fakeRaceWeekend([
        '*/drivers*' => Http::response(array_slice(recapDrivers(), 0, 3)),
    ]);

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();

    expect($recap->generated_at)->toBeNull()
        ->and($recap->attempts)->toBe(1)
        ->and($recap->last_error)->toContain('санитарните граници')
        ->and(TeamNewsItem::query()->count())->toBe(0)
        ->and(ChannelPost::query()->count())->toBe(0);
});

it('изрязва обиколките под safety car от темпото', function () {
    $race = recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();

    expect($recap->facts['neutralisations']['count'])->toBe(1)
        ->and($recap->facts['neutralisations']['laps'])->toBe(3)   // обиколки 8, 9 и 10
        ->and($recap->charts['neutralisations'])->toBe([['from' => 8, 'to' => 10]]);
});

it('мълчи при изключен флаг', function () {
    config(['features.data_recap' => false]);
    recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    expect(RaceDataRecap::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('не пипа състезание извън прозореца', function () {
    recapRace(hoursAgo: 48);
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    expect(RaceDataRecap::query()->count())->toBe(0);
});

it('вади телеметрия и карта по скорост за най-бързата обиколка', function () {
    $race = recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();
    $telemetry = $recap->charts['telemetry'][0] ?? null;
    $map = $recap->charts['track_map'] ?? null;

    expect($telemetry)->not->toBeNull()
        ->and($telemetry['points'])->not->toBeEmpty()
        // [дистанция, скорост, газ, спирачка, предавка]
        ->and($telemetry['points'][0])->toHaveCount(5)
        // Дистанцията се интегрира от скоростта и трябва да расте.
        ->and($telemetry['points'][count($telemetry['points']) - 1][0])->toBeGreaterThan(1000)
        ->and($map)->not->toBeNull()
        ->and($map['points'][0])->toHaveCount(3)   // x, y, скорост
        ->and($map['outline'])->not->toBeNull()
        ->and($map['corners'])->toHaveCount(2);
});

it('вади най-дългата битка от интервалите и шампионата при конструкторите', function () {
    $race = recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();

    expect($recap->facts['battle']['minutes'])->toBe(4)
        ->and($recap->charts['championship_teams'])->toHaveCount(1)
        ->and($recap->charts['championship_teams'][0]['after'])->toBe(500);
});

it('не препубликува звука от радиото, а само кога са се обаждали', function () {
    $race = recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();

    expect($recap->facts['radio']['count'])->toBe(4)
        ->and($recap->facts['radio']['busiest_lap'])->toBe(8)
        // Записаните адреси към mp3 на Формула 1 не бива да стигат до базата.
        ->and(json_encode($recap->facts))->not->toContain('.mp3')
        ->and(json_encode($recap->charts))->not->toContain('.mp3');
});

it('картата липсва тихо, ако геометрията е недостъпна', function () {
    $race = recapRace();
    fakeRaceWeekend(['*multiviewer*' => Http::response('blocked', 403)]);

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();

    // Линията на болида си остава — пада само очертанието и завоите.
    expect($recap->charts['track_map']['points'])->not->toBeEmpty()
        ->and($recap->charts['track_map']['outline'])->toBeNull()
        ->and($recap->charts['track_map']['corners'])->toBe([]);
});

it('смята деградация на гумите с корекция за гориво', function () {
    $race = recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();
    $compounds = collect($recap->charts['tyre_degradation']['compounds'] ?? []);

    expect($compounds)->toHaveCount(2)
        ->and($compounds->pluck('compound')->sort()->values()->all())->toBe(['HARD', 'MEDIUM']);

    // Във фикстурата всички обиколки са с еднакво време за даден пилот, така че
    // БЕЗ корекция медианата би била плоска. С корекция късните обиколки са
    // по-бавни — точно това прави кривата четима.
    $medium = $compounds->firstWhere('compound', 'MEDIUM')['median'];

    expect(count($medium))->toBeGreaterThan(2)
        ->and($medium[count($medium) - 1][1])->toBeGreaterThan($medium[0][1]);
});

it('дава пунктир на втория пилот от отбор', function () {
    $race = recapRace();
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();
    $positions = collect($recap->charts['positions']);

    // Всички 14 са в един отбор във фикстурата — първият е плътен, останалите не.
    expect($positions->first()['dashed'])->toBeFalse()
        ->and($positions->get(1)['dashed'])->toBeTrue();
});

it('наваксването не вика LLM, не кешира и не публикува', function () {
    config(['race-data.backfill_pause_ms' => 0]);
    $race = recapRace(hoursAgo: 30);
    fakeRaceWeekend();

    // Ако разказът тръгне към LLM при наваксване, тестът пада тук.
    $this->mock(LlmClient::class, function ($mock) {
        $mock->shouldNotReceive('completeWithTool');
    });

    Cache::flush();

    $this->artisan('padok:race-data-recap', ['--season' => 2026, '--no-publish' => true])
        ->assertSuccessful();

    $recap = RaceDataRecap::query()->where('race_id', $race->id)->first();

    expect($recap->generated_at)->not->toBeNull()
        ->and($recap->facts['narrative_by_llm'])->toBeFalse()
        // Историческите отговори не оставят следа в кеша: при седемдесет
        // кръга това са стотици мегабайти в таблицата `cache`.
        ->and(Cache::get('openf1:hist:laps:7002'))->toBeNull()
        ->and(Cache::get('openf1:hist:intervals:7002'))->toBeNull()
        // --no-publish пази новините и канала.
        ->and(TeamNewsItem::query()->count())->toBe(0)
        ->and(ChannelPost::query()->count())->toBe(0);
});

it('нормалният режим продължава да кешира', function () {
    recapRace();
    fakeRaceWeekend();
    Cache::flush();

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    expect(Cache::get('openf1:hist:laps:7002'))->not->toBeNull();
});

it('--dry-run показва кръговете, без да пипа нищо', function () {
    recapRace(hoursAgo: 30);
    fakeRaceWeekend();

    $this->artisan('padok:race-data-recap', ['--season' => 2026, '--dry-run' => true])
        ->expectsOutputToContain('Гран при на Италия')
        ->assertSuccessful();

    expect(RaceDataRecap::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('рисува картата от друга обиколка, ако позициите за най-бързата липсват', function () {
    $race = recapRace();

    // Позиционният феед къса. Точно това се случи с Монако 2026: location
    // спря десет минути преди най-бързата обиколка, докато car_data за нея си
    // беше налично — телеметрията стана, картата не.
    //
    // Във фикстурата най-бързата обиколка е първата, затова тук ѝ отнемаме
    // позициите и очакваме картата да се нарисува от следващата най-бърза.
    $blind = now()->subHours(5)->addSeconds(80)->utc()->format('Y-m-d\TH:i:s');

    fakeRaceWeekend([
        '*/location*' => function ($request) use ($blind) {
            return str_contains(urldecode((string) $request->url()), "date>={$blind}")
                ? Http::response('', 404)
                : Http::response(locationSamples());
        },
    ]);

    $this->artisan('padok:race-data-recap')->assertSuccessful();

    $map = RaceDataRecap::query()->where('race_id', $race->id)->first()->charts['track_map'] ?? null;

    expect($map)->not->toBeNull()
        ->and($map['points'])->not->toBeEmpty()
        // Номерът пътува към страницата: картата не бива да твърди, че показва
        // най-бързата обиколка, когато показва друга.
        ->and($map['lap'])->toBe(2);
});
