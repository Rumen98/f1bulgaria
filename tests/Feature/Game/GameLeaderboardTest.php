<?php

declare(strict_types=1);

use App\Jobs\ValidateGameLapJob;
use App\Models\GameLapRecord;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['features.game' => true]);

    // Трейсът вече е задължителен → всеки POST пуска ValidateGameLapJob.
    // На sync опашката job-ът би тръгнал ИНЛАЙН и би викал node срещу
    // фиктивните трейсове — фалшив за тези тестове (e2e го покрива отделно).
    Queue::fake();
});

it('връща 404 за класацията когато флагът е изключен', function () {
    config(['features.game' => false]);

    $this->getJson('/game/leaderboard/monza')->assertNotFound();
});

it('връща 404 за непозната писта', function () {
    $this->getJson('/game/leaderboard/nonsense')->assertNotFound();
});

it('показва празни рекорди в началото', function () {
    $this->getJson('/game/leaderboard/monza')
        ->assertOk()
        ->assertJson([
            'bests' => ['lap_ms' => null, 'sectors_ms' => [null, null, null]],
            'top' => [],
            'authenticated' => false,
        ]);
});

it('иска вход за запис на обиколка', function () {
    $this->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 90000,
        'sectors' => [30000, 30000, 30000],
    ])->assertUnauthorized();
});

it('валидира че секторите съставят обиколката', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 90000,
        'sectors' => [10000, 10000, 10000],
    ])->assertJsonValidationErrors('sectors');
});

it('отхвърля непозната писта при запис', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'nope',
        'lap_ms' => 90000,
        'sectors' => [30000, 30000, 30000],
    ])->assertJsonValidationErrors('track');
});

it('отхвърля сектори които не са списък (не гърми с 500)', function () {
    $user = User::factory()->create();

    // Асоциативен обект с 3 елемента минаваше `array|size:3`, после record()
    // четеше индекси 0/1/2 → NULL в NOT NULL колони → 500.
    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 90000,
        'sectors' => ['a' => 30000, 'b' => 30000, 'c' => 30000],
    ])->assertStatus(422);
});

it('отхвърля сектори с разбъркани ключове (без байпас на прага)', function () {
    $user = User::factory()->create();

    // {"2":..,"0":..,"1":..} минава rules() (ключове 0/1/2 налични), но е
    // не-списък → трябва да се отхвърли, иначе прагът/сумата се байпасват и
    // фабрикувана 10-секундна обиколка минава.
    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 10000,
        'sectors' => ['2' => 3000, '0' => 3000, '1' => 4000],
    ])->assertJsonValidationErrors('sectors');

    expect(GameLapRecord::query()->count())->toBe(0);
});

it('отхвърля неправдоподобно бързо време за пистата', function () {
    $user = User::factory()->create();

    // 11 s за Монца (5788 m) е под дължина/макс. скорост → физически невъзможно.
    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 11000,
        'sectors' => [3000, 4000, 4000],
    ])->assertJsonValidationErrors('lap_ms');
});

it('записва първата обиколка като лилава на пистата и лична', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 90000,
        'sectors' => [28000, 31000, 31000],
        'trace' => '{"v":1,"start":{},"inputs":"AAAA"}',
        'sim_version' => 1,
    ])->assertOk();

    $response->assertJson([
        'purple_lap' => true,
        'purple_sectors' => [true, true, true],
        'personal_best' => true,
        'rank' => 1,
    ]);
    $response->assertJsonPath('bests.lap_ms', 90000);

    expect(GameLapRecord::query()->count())->toBe(1);
});

it('лилаво само за подобрените полета и пази идеалната обиколка', function () {
    $user = User::factory()->create();

    // Еталонна обиколка.
    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 90000,
        'sectors' => [30000, 30000, 30000],
        'trace' => '{"v":1,"start":{},"inputs":"AAAA"}',
        'sim_version' => 1,
    ])->assertOk();

    // По-бърз S1, по-бавен S3, по-добра обиколка общо.
    $response = $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 89000,
        'sectors' => [28000, 30000, 31000],
        'trace' => '{"v":1,"start":{},"inputs":"AAAA"}',
        'sim_version' => 1,
    ])->assertOk();

    $response->assertJson([
        'purple_lap' => true,
        'purple_sectors' => [true, false, false],
        // Зелено = личен рекорд: S1 подобрен, S2 изравнен (≤), S3 по-бавен.
        'green_sectors' => [true, true, false],
        'personal_best' => true,
    ]);

    // Лилавият S3 остава от първата обиколка — секторните рекорди са независими.
    $response->assertJsonPath('bests.sectors_ms', [28000, 30000, 30000]);
    $response->assertJsonPath('bests.lap_ms', 89000);
    // Личните рекорди по сектори (за зелено/жълто спрямо себе си).
    $response->assertJsonPath('user_bests.sectors_ms', [28000, 30000, 30000]);
});

it('връща личните рекорди на влезлия през класацията', function () {
    $user = User::factory()->create();
    GameLapRecord::factory()->for($user)->create([
        'lap_ms' => 91000,
        'sector1_ms' => 30000,
        'sector2_ms' => 30000,
        'sector3_ms' => 31000,
    ]);

    $this->actingAs($user)
        ->getJson('/game/leaderboard/monza')
        ->assertOk()
        ->assertJsonPath('user_bests.lap_ms', 91000)
        ->assertJsonPath('user_bests.sectors_ms', [30000, 30000, 31000]);
});

it('по-бавна обиколка не е нито лилава, нито личен рекорд', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 88000,
        'sectors' => [29000, 29000, 30000],
        'trace' => '{"v":1,"start":{},"inputs":"AAAA"}',
        'sim_version' => 1,
    ])->assertOk();

    $response = $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 95000,
        'sectors' => [31000, 32000, 32000],
        'trace' => '{"v":1,"start":{},"inputs":"AAAA"}',
        'sim_version' => 1,
    ])->assertOk();

    $response->assertJson([
        'purple_lap' => false,
        'purple_sectors' => [false, false, false],
        'personal_best' => false,
    ]);
});

it('нарежда класацията и маркира твоя ред', function () {
    $alice = User::factory()->create(['name' => 'Алиса']);
    $bob = User::factory()->create(['name' => 'Боби']);

    GameLapRecord::factory()->for($bob)->create(['lap_ms' => 95000]);
    GameLapRecord::factory()->for($alice)->create(['lap_ms' => 92000]);

    $this->actingAs($alice)
        ->getJson('/game/leaderboard/monza')
        ->assertOk()
        ->assertJsonPath('top.0.name', 'Алиса')
        ->assertJsonPath('top.0.lap_ms', 92000)
        ->assertJsonPath('top.0.is_you', true)
        ->assertJsonPath('top.1.name', 'Боби')
        ->assertJsonPath('top.1.is_you', false)
        ->assertJsonPath('authenticated', true);
});

it('пази трейса, маркира pending и пуска валидиращия job', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 90000,
        'sectors' => [30000, 30000, 30000],
        'trace' => '{"v":1,"start":{},"inputs":"AAA="}',
        'sim_version' => 1,
    ])->assertOk();

    $record = GameLapRecord::query()->firstOrFail();

    expect($record->input_trace)->not->toBeNull()
        ->and($record->verify_status)->toBe('pending')
        ->and($record->sim_version)->toBe(1);

    Queue::assertPushed(
        ValidateGameLapJob::class,
        fn (ValidateGameLapJob $job): bool => $job->recordId === $record->id,
    );
});

it('отхвърля запис без трейс — валидацията не е по желание', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/lap', [
        'track' => 'monza',
        'lap_ms' => 90000,
        'sectors' => [30000, 30000, 30000],
    ])->assertJsonValidationErrors(['trace', 'sim_version']);

    expect(GameLapRecord::query()->count())->toBe(0);
});

it('отхвърлените от преиграването обиколки изчезват от класацията', function () {
    $honest = User::factory()->create(['name' => 'Честният']);
    $cheat = User::factory()->create(['name' => 'Хитрецът']);

    GameLapRecord::factory()->for($honest)->create(['lap_ms' => 92000]);
    GameLapRecord::factory()->for($cheat)->create([
        'lap_ms' => 60000,
        'verify_status' => 'rejected',
    ]);

    $this->getJson('/game/leaderboard/monza')
        ->assertOk()
        ->assertJsonPath('bests.lap_ms', 92000)
        ->assertJsonPath('top.0.name', 'Честният')
        ->assertJsonMissing(['name' => 'Хитрецът']);
});

it('класацията носи user_id и has_ghost за дуелите', function () {
    $user = User::factory()->create(['name' => 'Духчо']);
    GameLapRecord::factory()->for($user)->create([
        'lap_ms' => 91000,
        'verify_status' => 'verified',
        'ghost_frames' => 'кадри-base64',
        'lap_ticks' => 10920,
    ]);

    // Без кадри → без дуел бутон.
    $ghostless = User::factory()->create(['name' => 'Безплътния']);
    GameLapRecord::factory()->for($ghostless)->create(['lap_ms' => 95000]);

    $this->getJson('/game/leaderboard/monza')
        ->assertOk()
        ->assertJsonPath('top.0.user_id', $user->id)
        ->assertJsonPath('top.0.has_ghost', true)
        ->assertJsonPath('top.1.user_id', $ghostless->id)
        ->assertJsonPath('top.1.has_ghost', false);
});

it('духът е най-бързата обиколка С кадри, дори по-бърза без кадри да съществува', function () {
    $user = User::factory()->create();
    // По-бърза, но без кадри (стар запис отпреди фийчъра).
    GameLapRecord::factory()->for($user)->create(['lap_ms' => 88000, 'verify_status' => 'verified']);
    GameLapRecord::factory()->for($user)->create([
        'lap_ms' => 90000,
        'sim_version' => 2,
        'verify_status' => 'verified',
        'ghost_frames' => 'кадри-на-90',
        'lap_ticks' => 10800,
    ]);

    $this->getJson("/game/ghost/monza/{$user->id}")
        ->assertOk()
        ->assertJsonPath('lap_ms', 90000)
        ->assertJsonPath('frames', 'кадри-на-90');
});

it('сервира духа на потребител за дуел', function () {
    $user = User::factory()->create(['name' => 'Призрак']);
    GameLapRecord::factory()->for($user)->create([
        'lap_ms' => 90500,
        'sim_version' => 2,
        'verify_status' => 'verified',
        'ghost_frames' => 'кадри-base64',
        'lap_ticks' => 10860,
    ]);

    $this->getJson("/game/ghost/monza/{$user->id}")
        ->assertOk()
        ->assertJson([
            'v' => 2,
            'lap_ms' => 90500,
            'lap_ticks' => 10860,
            'frames' => 'кадри-base64',
            'name' => 'Призрак',
        ]);
});

it('духът е 404 без кадри или за непозната писта', function () {
    $user = User::factory()->create();
    GameLapRecord::factory()->for($user)->create(['lap_ms' => 90500]);

    $this->getJson("/game/ghost/monza/{$user->id}")->assertNotFound();
    $this->getJson("/game/ghost/nope/{$user->id}")->assertNotFound();
});

it('отхвърлен запис не сервира дух', function () {
    $user = User::factory()->create();
    GameLapRecord::factory()->for($user)->create([
        'lap_ms' => 60000,
        'verify_status' => 'rejected',
        'ghost_frames' => 'фалшиви-кадри',
    ]);

    $this->getJson("/game/ghost/monza/{$user->id}")->assertNotFound();
});

it('pending и error обиколките продължават да се броят', function () {
    $user = User::factory()->create();

    GameLapRecord::factory()->for($user)->create(['lap_ms' => 91000, 'verify_status' => 'pending']);
    GameLapRecord::factory()->for($user)->create(['lap_ms' => 93000, 'verify_status' => 'error']);

    $this->getJson('/game/leaderboard/monza')
        ->assertOk()
        ->assertJsonPath('bests.lap_ms', 91000);
});
