<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Seo;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * „Инженерство" — обяснителната рубрика за техниката зад Формула 1.
 *
 * Съдържанието живее в config/engineering-content.php, а не в базата, по
 * същата причина като История и Речника: пише се рядко, чете се често, ревизира
 * се през git и не иска нито админ панел, нито миграция.
 */
class EngineeringController extends Controller
{
    public function index(): Response
    {
        app(Seo::class)
            ->title('Инженерството зад Формула 1')
            ->description('Как работи болидът от Формула 1, обяснено на български: активна аеродинамика, силова установка, гуми, спирачки, под и стратегия.')
            ->canonical(route('engineering.index'));

        return Inertia::render('Engineering/Index', [
            'systems' => $this->systemsWithTopics(),
        ]);
    }

    public function show(string $slug): Response
    {
        $topics = $this->topics();
        $topic = $topics->firstWhere('slug', $slug);

        if ($topic === null) {
            throw new NotFoundHttpException;
        }

        app(Seo::class)
            ->title($topic['title'])
            ->description($topic['teaser'])
            ->canonical(route('engineering.show', $slug));

        $index = $topics->search(fn (array $t) => $t['slug'] === $slug);

        return Inertia::render('Engineering/Show', [
            'topic' => [
                ...$topic,
                'system_label' => config('engineering-content.systems')[$topic['system']] ?? null,
                'glossary' => $this->glossary($topic['glossary'] ?? []),
            ],
            'neighbours' => [
                'prev' => $this->shape($topics->get($index - 1)),
                'next' => $this->shape($topics->get($index + 1)),
            ],
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function topics(): Collection
    {
        return collect(config('engineering-content.topics', []));
    }

    /**
     * Системите с темите под тях. Празна система не се показва — хъб с празни
     * рубрики изглежда изоставен, а рубриката тръгва с шест теми, не с двайсет.
     *
     * @return array<int, array<string, mixed>>
     */
    private function systemsWithTopics(): array
    {
        $bySystem = $this->topics()->groupBy('system');

        return collect(config('engineering-content.systems', []))
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'topics' => $bySystem->get($key, collect())
                    ->map(fn (array $t) => [
                        'slug' => $t['slug'],
                        'title' => $t['title'],
                        'teaser' => $t['teaser'],
                        'minutes' => $t['reading_minutes'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $system) => $system['topics'] !== [])
            ->values()
            ->all();
    }

    /**
     * Термините от речника, употребени в темата — с българското изписване и
     * определението, за да не се налага човек да отваря друга страница.
     *
     * @param  array<int, string>  $wanted
     * @return array<int, array<string, mixed>>
     */
    private function glossary(array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }

        $terms = collect(config('f1-glossary.terms', []))->keyBy('term_en');

        return collect($wanted)
            ->map(fn (string $en) => $terms->get($en))
            ->filter()
            ->map(fn (array $term) => [
                'term_bg' => $term['term_bg'],
                'term_en' => $term['term_en'],
                'definition_bg' => $term['definition_bg'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $topic
     * @return array<string, mixed>|null
     */
    private function shape(?array $topic): ?array
    {
        return $topic === null ? null : ['slug' => $topic['slug'], 'title' => $topic['title']];
    }
}
