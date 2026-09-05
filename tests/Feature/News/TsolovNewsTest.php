<?php

declare(strict_types=1);

use App\Models\TeamNewsItem;
use App\Services\News\TsolovDetector;
use Inertia\Testing\AssertableInertia as Assert;

/** Статия за Цолов от Ф2 — вижда се само в неговия кът. */
function tsolovF2Item(array $overrides = []): TeamNewsItem
{
    return TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Цолов спечели спринта в Монца',
        'title_original' => 'Tsolov wins Monza sprint',
        'is_tsolov' => true,
        'is_f1_related' => false,
        ...$overrides,
    ]);
}

describe('разпознаване', function () {
    it('хваща фамилията в заглавието, на латиница и на кирилица', function () {
        $detector = new TsolovDetector;

        expect($detector->matchesTitle('Tsolov completed his first F1 test'))->toBeTrue()
            ->and($detector->matchesTitle('Никола Цолов води в шампионата'))->toBeTrue();
    });

    it('не се подвежда по други новини', function () {
        $detector = new TsolovDetector;

        expect($detector->matchesTitle('Verstappen wins the Italian Grand Prix'))->toBeFalse()
            ->and($detector->matchesTitle(null))->toBeFalse()
            ->and($detector->matchesTitle(''))->toBeFalse();
    });

    it('не хваща фамилията вътре в друга дума', function () {
        expect((new TsolovDetector)->matchesTitle('Kartsolov is a different person'))->toBeFalse();
    });

    it('НЕ хваща статия, в която той е само ред от таблица', function () {
        // Реален случай от продъкшън: „2026 F2 championship standings after
        // Monza Sprint Race" беше маркирана като новина за него, защото
        // името му е в класирането в тялото. Всяко класиране и всеки списък
        // с резултати го съдържа — кътът му щеше да е таблици, не истории.
        expect((new TsolovDetector)->matchesTitle('2026 F2 championship standings after Monza Sprint Race'))
            ->toBeFalse();
    });
});

describe('обхват на емисиите', function () {
    it('Ф2 новина за Цолов НЕ влиза в главната емисия', function () {
        tsolovF2Item();

        expect(TeamNewsItem::query()->inMainFeed()->count())->toBe(0);
    });

    it('но се вижда в кътa на Цолов', function () {
        tsolovF2Item();

        expect(TeamNewsItem::query()->aboutTsolov()->count())->toBe(1);
    });

    it('новина за Цолов, която Е и Ф1, влиза на двете места', function () {
        // Реален случай: тестът му с болид на Racing Bulls в Имола.
        tsolovF2Item([
            'title_bg' => 'Цолов кара за Рейсинг Булс в Имола',
            'is_f1_related' => true,
        ]);

        expect(TeamNewsItem::query()->inMainFeed()->count())->toBe(1)
            ->and(TeamNewsItem::query()->aboutTsolov()->count())->toBe(1);
    });

    it('обикновена Ф1 новина не влиза в кътa му', function () {
        TeamNewsItem::factory()->create([
            'status' => 'auto_published',
            'title_bg' => 'Верстапен спечели в Монца',
            'is_tsolov' => false,
            'is_f1_related' => true,
        ]);

        expect(TeamNewsItem::query()->inMainFeed()->count())->toBe(1)
            ->and(TeamNewsItem::query()->aboutTsolov()->count())->toBe(0);
    });

    it('sitemap-ът и търсачката виждат всичко публикувано, вкл. Ф2', function () {
        tsolovF2Item();

        expect(TeamNewsItem::query()->published()->count())->toBe(1);
    });

    it('нищо неодобрено не изтича никъде', function () {
        tsolovF2Item(['status' => 'pending', 'title_bg' => null]);
        tsolovF2Item(['status' => 'rejected']);

        expect(TeamNewsItem::query()->published()->count())->toBe(0)
            ->and(TeamNewsItem::query()->aboutTsolov()->count())->toBe(0)
            ->and(TeamNewsItem::query()->inMainFeed()->count())->toBe(0);
    });
});

describe('страници', function () {
    it('главният списък не показва Ф2 статия за Цолов', function () {
        tsolovF2Item();

        $this->get(route('news.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('News/Index')
                ->has('items.data', 0));
    });

    it('кътът на Цолов я показва', function () {
        tsolovF2Item();

        $this->get(route('tsolov'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tsolov')
                ->has('news', 1)
                ->where('news.0.title', 'Цолов спечели спринта в Монца')
                ->where('news.0.is_f1', false));
    });

    it('статията се отваря на собствения си URL, макар да не е в списъка', function () {
        // Иначе линкът от кътa му, sitemap-ът и Google щяха да удрят в 404.
        $item = tsolovF2Item();

        $this->get(route('news.show', $item->slug))->assertOk();
    });
});
