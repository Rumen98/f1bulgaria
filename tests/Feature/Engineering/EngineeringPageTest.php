<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.engineering' => true]);
});

function engineeringTopics(): array
{
    return config('engineering-content.topics', []);
}

it('показва хъба с темите, групирани по системи', function () {
    $this->get('/inzhenerstvo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Engineering/Index')
            ->has('systems'));
});

it('отваря всяка тема', function () {
    foreach (engineeringTopics() as $topic) {
        $this->get("/inzhenerstvo/{$topic['slug']}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Engineering/Show')
                ->where('topic.slug', $topic['slug'])
                ->has('topic.sections'));
    }
})->skip(fn () => engineeringTopics() === [], 'Още няма теми.');

it('връща 404 за непозната тема', function () {
    $this->get('/inzhenerstvo/nyama-takava')->assertNotFound();
});

it('връзва съседните теми, без да излиза извън списъка', function () {
    $topics = engineeringTopics();

    $this->get("/inzhenerstvo/{$topics[0]['slug']}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('neighbours.prev', null)->has('neighbours.next'));

    $last = end($topics);

    $this->get("/inzhenerstvo/{$last['slug']}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('neighbours.next', null)->has('neighbours.prev'));
})->skip(fn () => count(engineeringTopics()) < 2, 'Нужни са поне две теми.');

/*
|--------------------------------------------------------------------------
| Валидация на самия конфиг
|--------------------------------------------------------------------------
|
| Съдържанието се пише на ръка в PHP масив, без админ и без база. Тези
| проверки заместват валидацията, която иначе би правил формуляр: счупен id
| или термин, който го няма в речника, се появяват като тих проблем на живо.
|
*/

it('конфигът е добре оформен', function () {
    $topics = engineeringTopics();
    $systems = config('engineering-content.systems', []);

    expect($topics)->not->toBeEmpty()
        ->and($systems)->not->toBeEmpty();

    $slugs = array_column($topics, 'slug');

    expect($slugs)->toHaveCount(count(array_unique($slugs)));

    foreach ($topics as $topic) {
        expect($topic['slug'])->toMatch('/^[a-z0-9-]+$/')
            ->and($systems)->toHaveKey($topic['system'])
            ->and($topic['title'])->not->toBeEmpty()
            ->and($topic['teaser'])->not->toBeEmpty()
            ->and(mb_strlen($topic['teaser']))->toBeLessThanOrEqual(200)
            ->and($topic['hero']['intro'] ?? '')->not->toBeEmpty()
            ->and(count($topic['sections']))->toBeGreaterThanOrEqual(3);

        foreach ($topic['sections'] as $section) {
            expect($section['id'])->toMatch('/^[a-z0-9-]+$/')
                ->and($section['heading'])->not->toBeEmpty()
                ->and($section['paragraphs'])->not->toBeEmpty();

            foreach ($section['paragraphs'] as $paragraph) {
                // Markdown в текста излиза като видими звездички: страницата
                // рендира с {{ }}, не с v-html.
                expect($paragraph)->not->toContain('**')
                    ->and($paragraph)->not->toContain('](');
            }
        }

        // Анкерите вътре в темата трябва да са уникални.
        $ids = array_column($topic['sections'], 'id');
        expect($ids)->toHaveCount(count(array_unique($ids)));
    }
});

it('всеки посочен термин наистина съществува в речника', function () {
    $known = collect(config('f1-glossary.terms', []))->pluck('term_en')->flip();

    foreach (engineeringTopics() as $topic) {
        foreach ($topic['glossary'] ?? [] as $term) {
            expect($known->has($term))->toBeTrue("Терминът „{$term}“ от темата „{$topic['slug']}“ го няма в речника.");
        }
    }
});

it('всяка тема сочи към първични източници', function () {
    foreach (engineeringTopics() as $topic) {
        expect($topic['sources'] ?? [])->not->toBeEmpty();

        foreach ($topic['sources'] as $source) {
            expect($source['url'])->toStartWith('https://')
                ->and($source['title'])->not->toBeEmpty();
        }
    }
});

it('търсачката намира тема по заглавие на секция', function () {
    $topic = engineeringTopics()[0] ?? null;

    if ($topic === null) {
        return;
    }

    $needle = mb_substr($topic['sections'][0]['heading'], 0, 12);
    $response = $this->get('/tarsene?q='.urlencode($needle))->assertOk();

    $group = collect($response->viewData('page')['props']['groups'])->firstWhere('key', 'engineering');

    expect($group['items'])->not->toBeEmpty();
});

it('sitemap-ът включва хъба и всички теми', function () {
    $this->artisan('sitemap:generate')->assertSuccessful();

    $xml = file_get_contents(public_path('sitemap-content.xml'));

    expect($xml)->toContain('/inzhenerstvo');

    foreach (engineeringTopics() as $topic) {
        expect($xml)->toContain("/inzhenerstvo/{$topic['slug']}");
    }
});
