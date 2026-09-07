<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChannelPostKind;
use App\Models\Race;
use App\Services\RaceData\RaceRecapGenerator;
use App\Services\RaceData\RecapPublisher;
use App\Services\Telegram\ChannelQueue;
use App\Services\Telegram\Formatters\DataRecapFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Прави рекапа с данни за приключилите състезания.
 *
 * ДВА РЕЖИМА, които се държат различно:
 *
 * 1. От графика (без аргументи) — един кръг, между MIN_HOURS и MAX_HOURS след
 *    старта. Долната граница чака OpenF1 да публикува официалната класация и
 *    сесията да излезе от „живия“ прозорец (±30 минути около сесията), в който
 *    API-то иска токен. Горната позволява на пропуснат час да се навакса сам:
 *    командата върви на всеки час, така че заспал крон не значи липсващ рекап.
 *
 * 2. Наваксване назад (--season=2024) — десетки кръгове наведнъж. Тук трите
 *    неща, които в нормален режим помагат, започват да пречат: кешът налива
 *    стотици мегабайти в базата за данни, четени точно веднъж; LLM-ът пише
 *    текстове, които никой няма да прочете скоро; а липсата на пауза удря
 *    лимита на OpenF1. Режимът ги изключва сам — виж config/race-data.php.
 */
class GenerateRaceDataRecapCommand extends Command
{
    protected $signature = 'padok:race-data-recap
        {race? : Id на конкретно състезание (пренебрегва прозореца)}
        {--season= : Година — наваксва всички приключили кръгове от сезона}
        {--rebuild : Преизчислява и вече готов рекап}
        {--no-publish : Само смята; не пипа новините и канала}
        {--with-llm : При наваксване пуска и разказа през LLM (по подразбиране е изключен)}
        {--dry-run : Показва кои кръгове биха се обработили и спира}';

    protected $description = 'Сглобява рекапа с данни от OpenF1 и го публикува в новините и канала.';

    /** Толкова часа след старта данните вече са исторически и официални. */
    private const MIN_HOURS = 3;

    private const MAX_HOURS = 36;

    /** След толкова неуспешни опита спираме да опитваме и оставяме следа. */
    private const MAX_ATTEMPTS = 5;

    public function handle(
        RaceRecapGenerator $generator,
        RecapPublisher $publisher,
        DataRecapFormatter $formatter,
        ChannelQueue $queue,
    ): int {
        if (! config('features.data_recap')) {
            $this->info('FEATURE_DATA_RECAP е изключен — пропускаме.');

            return self::SUCCESS;
        }

        $backfill = $this->option('season') !== null;

        if ($backfill) {
            $this->prepareBackfill();
        }

        $races = $this->targets();

        if ($races->isEmpty()) {
            $this->line('Няма състезание за рекап.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Биха се обработили {$races->count()} кръга:");

            foreach ($races as $race) {
                $this->line('  · '.($race->race_datetime_utc?->format('d.m.Y') ?? '—')." — {$race->name_bg}");
            }

            return self::SUCCESS;
        }

        $total = $races->count();
        $done = 0;
        $failed = 0;

        foreach ($races->values() as $i => $race) {
            $prefix = $backfill ? sprintf('[%d/%d] ', $i + 1, $total) : '';
            $this->line("{$prefix}→ {$race->name_bg} (кръг {$race->round})");

            $outcome = $generator->generate($race);
            $recap = $outcome['recap'];

            if ($recap === null) {
                $failed++;
                $this->warn("  ✗ {$outcome['error']}");
                $this->pause($backfill);

                continue;
            }

            $done++;
            $bullets = count((array) ($recap->facts['bullets'] ?? []));
            $charts = count((array) $recap->charts);
            $source = ($recap->facts['narrative_by_llm'] ?? false) ? 'LLM' : 'шаблон';

            $this->info("  ✓ {$bullets} факта, {$charts} серии, текст: {$source}");

            if (! $this->option('no-publish')) {
                $item = $publisher->publish($recap);

                if ($item !== null) {
                    $this->line("  · статия: /news/{$item->slug}");
                }

                $body = $formatter->format($recap);

                if ($body !== '') {
                    $enqueued = $queue->enqueue($race, ChannelPostKind::F1DataRecap, $body);
                    $this->line("  · канал: {$enqueued->value}");
                }
            }

            $this->pause($backfill);
        }

        if ($backfill) {
            $this->newLine();
            $this->info("Готови: {$done} · паднали: {$failed} от {$total}.");
        }

        return self::SUCCESS;
    }

    /**
     * Превключва настройките в режим за наваксване.
     *
     * Прави се тук, а не в .env, за да не остане включено след командата — на
     * следващия крон нормалният режим трябва пак да кешира и да пише с LLM.
     */
    private function prepareBackfill(): void
    {
        config(['race-data.cache_historical' => false]);

        if (! $this->option('with-llm')) {
            config(['race-data.narrative_llm' => false]);
        }

        $pause = (int) config('race-data.backfill_pause_ms', 2000);
        $llm = $this->option('with-llm') ? 'с LLM' : 'без LLM';

        $this->info("Наваксване: кешът е изключен, {$llm}, пауза {$pause} ms между кръговете.");
    }

    /** Дишане между кръговете, за да не се удари лимитът на OpenF1. */
    private function pause(bool $backfill): void
    {
        $ms = (int) config('race-data.backfill_pause_ms', 2000);

        if ($backfill && $ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * @return Collection<int, Race>
     */
    private function targets(): Collection
    {
        $id = $this->argument('race');

        if ($id !== null) {
            return Race::query()->whereKey($id)->get();
        }

        $season = $this->option('season');

        if ($season !== null) {
            return Race::query()
                ->whereHas('season', fn ($q) => $q->where('year', (int) $season))
                ->whereNotNull('race_datetime_utc')
                ->where('race_datetime_utc', '<', now()->subHours(self::MIN_HOURS))
                ->when(! $this->option('rebuild'), function ($query): void {
                    $query->whereDoesntHave('dataRecap', fn ($r) => $r->whereNotNull('generated_at'));
                })
                // Същият предпазител като при почасовия режим: кръг, паднал пет
                // пъти, не бива да яде по шестнайсет заявки при всяко следващо
                // пускане на наваксването.
                ->whereDoesntHave('dataRecap', fn ($r) => $r->where('attempts', '>=', self::MAX_ATTEMPTS))
                ->orderBy('race_datetime_utc')
                ->get();
        }

        return Race::query()
            ->whereNotNull('race_datetime_utc')
            ->whereBetween('race_datetime_utc', [
                now()->subHours(self::MAX_HOURS),
                now()->subHours(self::MIN_HOURS),
            ])
            ->when(! $this->option('rebuild'), function ($query): void {
                $query->whereDoesntHave('dataRecap', fn ($r) => $r->whereNotNull('generated_at'));
            })
            ->whereDoesntHave('dataRecap', fn ($r) => $r->where('attempts', '>=', self::MAX_ATTEMPTS))
            ->orderBy('race_datetime_utc')
            ->get();
    }
}
