<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeamNewsItem;
use Illuminate\Console\Command;

/**
 * Поправя известни грешни транслитерации и преведени собствени имена в
 * българския текст на новините (генериран от LLM). Идемпотентно и
 * преизпълнимо след всеки news:enrich.
 *
 * Защо съществува: по-малките модели (ministral-14b, след като
 * mistral-large отпадна от безплатната тарифа) грешат имена по два начина —
 * пишат едно и също име различно в съседни статии, и понякога ПРЕВЕЖДАТ
 * фамилия като нарицателно („Leclerc" → „Лекар"). Промптът вече го
 * забранява, но инструкция не е гаранция; тук е машинната мрежа отдолу.
 */
class NormalizeNewsBulgarianCommand extends Command
{
    protected $signature = 'news:normalize-bg';

    protected $description = 'Поправя грешни български транслитерации и преведени имена в новините.';

    /**
     * Колони с български текст, които се нормализират.
     *
     * @var list<string>
     */
    private const TEXT_COLUMNS = ['title_bg', 'summary_bg', 'full_article_bg', 'our_analysis_bg'];

    /**
     * Замени по подниз — безопасни са, защото низовете вляво не са част от
     * друга българска дума. Разширявай при нужда.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'Алпайн' => 'Алпин',
        'Ферарри' => 'Ферари',
        'Макларен' => 'Макларън',
        'Хамилтон' => 'Хамилтън',
        'Зандвоорт' => 'Зандворт',
        // Правописно е с главна само първата дума — „при" е част от
        // заемката, не собствено име.
        'Гран При' => 'Гран при',
    ];

    /**
     * Замени, които изискват граница на думата, защото низът вляво Е
     * съществуваща българска дума. Без границата „Лекар" би счупило
     * „Лекарят прегледа пилота" на „Леклерят".
     *
     * PCRE-то ползва \p{L} lookaround, а не \b: с модификатор /u в PHP
     * \b пак стъпва на ASCII \w и би сложило граница между кирилски букви.
     *
     * @var array<string, string>
     */
    private const WORD_REPLACEMENTS = [
        'Лекар' => 'Леклер',
    ];

    /**
     * Предлози, след които съществителното/прилагателното от мъжки род НЕ
     * може да носи пълен член.
     *
     * @var list<string>
     */
    private const PREPOSITIONS = [
        'в', 'във', 'на', 'за', 'от', 'до', 'с', 'със', 'по', 'при', 'над',
        'под', 'пред', 'зад', 'през', 'без', 'край', 'към', 'из', 'около',
        'покрай', 'срещу', 'между', 'според', 'чрез', 'заради', 'освен',
        'въпреки', 'относно', 'спрямо', 'върху', 'сред', 'извън',
    ];

    /**
     * Пълен → кратък член. Окончание → замяна.
     *
     * Редът има значение: „-ият" свършва и на „-ят", затова се проверява пръв.
     *
     * @var array<string, string>
     */
    private const ARTICLE_ENDINGS = [
        'ият' => 'ия',   // прилагателно: трудният → трудния
        'ът' => 'а',     // съществително: храмът → храма
        'ят' => 'я',     // съществително: денят → деня
    ];

    public function handle(): int
    {
        $fixed = 0;

        // Нарочно БЕЗ LIKE предфилтър. `key_facts` е JSON, а Laravel го
        // сериализира с json_encode, който екранира кирилицата в \uXXXX
        // форма — заявка `like '%Лекар%'` никога не съвпада там и тихо
        // изпуска точно редовете, които трябва да поправим. При този
        // размер на таблицата (хиляди редове, ~40 нови на ден) пълното
        // обхождане е без значение, а сравнението в PHP решава кое се записва.
        TeamNewsItem::query()
            ->whereNotNull('title_bg')
            ->chunkById(100, function ($items) use (&$fixed): void {
                foreach ($items as $item) {
                    $changes = [];

                    foreach (self::TEXT_COLUMNS as $column) {
                        $normalized = $this->normalize($item->{$column});

                        if ($normalized !== $item->{$column}) {
                            $changes[$column] = $normalized;
                        }
                    }

                    $facts = $this->normalizeFacts($item->key_facts);

                    if ($facts !== $item->key_facts) {
                        $changes['key_facts'] = $facts;
                    }

                    if ($changes !== []) {
                        $item->update($changes);
                        $fixed++;
                    }
                }
            });

        $this->info("Поправени новини: {$fixed}");

        return self::SUCCESS;
    }

    private function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = str_replace(array_keys(self::REPLACEMENTS), array_values(self::REPLACEMENTS), $text);

        foreach (self::WORD_REPLACEMENTS as $bad => $good) {
            $text = preg_replace('/(?<!\p{L})'.preg_quote($bad, '/').'(?!\p{L})/u', $good, $text) ?? $text;
        }

        return $this->fixDefiniteArticle($text);
    }

    /**
     * Сваля пълния член до кратък след предлог.
     *
     * Правилото е синтактично, не смислово, и затова е безопасно за машина:
     * именна фраза, управлявана от предлог, НИКОГА не е подлог или именна
     * част от сказуемото — а това са единствените две позиции, които пълният
     * член маркира. Няма изключение според глагола, регистъра или стила.
     *
     * Обратната посока (добавяне на пълен член в позиция на подлог) НЕ се
     * прави: там членът е самият падежен маркер, тоест подлогът не може да
     * се разпознае без смислов разбор, а сгрешена добавка би внесла НОВА
     * грешка в текст, който вече е публикуван.
     *
     * Целевата дума е само с малки букви — така собствените имена остават
     * недокоснати.
     */
    private function fixDefiniteArticle(string $text): string
    {
        $prepositions = implode('|', array_map(
            fn (string $p): string => preg_quote($p, '/'),
            self::PREPOSITIONS,
        ));

        foreach (self::ARTICLE_ENDINGS as $full => $short) {
            $pattern = '/(?<!\p{L})('.$prepositions.')(\s+)((?:[а-я]+-)*[а-я]+)'.$full.'(?!\p{L})/u';

            $text = preg_replace($pattern, '$1$2$3'.$short, $text) ?? $text;
        }

        return $text;
    }

    /**
     * key_facts е JSON масив от кратки факти — нормализира се елемент по
     * елемент, за да не се сериализира обратно счупен.
     *
     * @param  array<int, mixed>|null  $facts
     * @return array<int, mixed>|null
     */
    private function normalizeFacts(?array $facts): ?array
    {
        if ($facts === null) {
            return null;
        }

        return array_map(
            fn (mixed $fact): mixed => is_string($fact) ? $this->normalize($fact) : $fact,
            $facts,
        );
    }
}
