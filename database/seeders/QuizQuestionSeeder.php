<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\QuizQuestion;
use Illuminate\Database\Seeder;

class QuizQuestionSeeder extends Seeder
{
    public function run(): void
    {
        // `code` е стабилният идентификатор: редакция на текста при re-seed
        // обновява съществуващия ред вместо да създаде дубликат. `is_active`
        // умишлено НЕ е в payload-а — деактивация от админа трябва да
        // преживява re-seed (при създаване DB default-ът true я покрива).
        foreach ($this->questions() as $q) {
            QuizQuestion::query()->updateOrCreate(
                ['code' => $q['code']],
                [
                    'question' => $q['question'],
                    'option_1' => $q['options'][0],
                    'option_2' => $q['options'][1],
                    'option_3' => $q['options'][2],
                    'option_4' => $q['options'][3],
                    'correct_option' => $q['correct'],
                ],
            );
        }
    }

    /**
     * @return array<int, array{code: string, question: string, options: array<int, string>, correct: int}>
     */
    protected function questions(): array
    {
        return [
            // ——— Класика / правила ———
            ['code' => 'most-wins', 'question' => 'Кой пилот държи рекорда за най-много победи в Гран При във Формула 1?', 'options' => ['Михаел Шумахер', 'Люис Хамилтън', 'Себастиан Фетел', 'Айртон Сена'], 'correct' => 2],
            ['code' => 'first-season', 'question' => 'През коя година се провежда първият официален световен шампионат във Формула 1?', 'options' => ['1946', '1950', '1954', '1961'], 'correct' => 2],
            ['code' => 'monaco-city', 'question' => 'В кой град се провежда Гран При на Монако?', 'options' => ['Монца', 'Монте Карло', 'Спа', 'Маранело'], 'correct' => 2],
            ['code' => 'win-points', 'question' => 'Колко точки получава победителят в едно състезание (текуща система)?', 'options' => ['10', '25', '50', '40'], 'correct' => 2],
            ['code' => 'constructor-titles', 'question' => 'Кой отбор има най-много титли при конструкторите?', 'options' => ['Ферари', 'Макларън', 'Мерцедес', 'Уилямс'], 'correct' => 1],
            ['code' => 'schumacher-titles', 'question' => 'Колко световни титли при пилотите има Михаел Шумахер?', 'options' => ['5', '6', '7', '8'], 'correct' => 3],
            ['code' => 'temple-of-speed', 'question' => 'Коя писта е наричана „Храмът на скоростта"?', 'options' => ['Монца', 'Монако', 'Силвърстоун', 'Спа'], 'correct' => 1],
            ['code' => 'drs-meaning', 'question' => 'Какво означава съкращението DRS?', 'options' => ['Drive Response System', 'Drag Reduction System', 'Direct Racing Speed', 'Dynamic Rev System'], 'correct' => 2],
            ['code' => 'senna-country', 'question' => 'От коя държава е пилотът Айртон Сена?', 'options' => ['Аржентина', 'Португалия', 'Бразилия', 'Испания'], 'correct' => 3],
            ['code' => 'finish-flag', 'question' => 'Кой флаг сигнализира края на състезанието?', 'options' => ['Жълт', 'Червен', 'Син', 'Кариран (каре)'], 'correct' => 4],
            ['code' => 'professor-nickname', 'question' => 'Кой пилот носи прякора „Професора"?', 'options' => ['Ален Прост', 'Ники Лауда', 'Найджъл Менсъл', 'Джим Кларк'], 'correct' => 1],
            ['code' => 'alonso-titles', 'question' => 'Колко световни титли има Фернандо Алонсо?', 'options' => ['1', '2', '3', '4'], 'correct' => 2],
            ['code' => 'ferrari-country', 'question' => 'От коя държава е отборът Ферари?', 'options' => ['Италия', 'Германия', 'Франция', 'Великобритания'], 'correct' => 1],
            ['code' => 'drivers-per-team', 'question' => 'Колко пилоти има всеки отбор в едно състезание?', 'options' => ['1', '2', '3', '4'], 'correct' => 2],
            ['code' => 'youngest-champion', 'question' => 'Кой е най-младият световен шампион във Формула 1?', 'options' => ['Люис Хамилтън', 'Ландо Норис', 'Себастиан Фетел', 'Макс Верстапен'], 'correct' => 3],
            ['code' => 'senna-titles', 'question' => 'Колко световни титли има Айртон Сена?', 'options' => ['1', '2', '3', '4'], 'correct' => 3],

            // ——— Пилоти и отбори ———
            ['code' => 'hamilton-team-2026', 'question' => 'За кой отбор се състезава Люис Хамилтън през сезон 2026?', 'options' => ['Мерцедес', 'Ферари', 'Ред Бул', 'Макларън'], 'correct' => 2],
            ['code' => 'champion-2023', 'question' => 'Кой спечели световната титла при пилотите през 2023 г.?', 'options' => ['Макс Верстапен', 'Люис Хамилтън', 'Шарл Льоклер', 'Серхио Перес'], 'correct' => 1],
            ['code' => 'hamilton-number', 'question' => 'С кой номер се състезава Люис Хамилтън?', 'options' => ['1', '44', '7', '33'], 'correct' => 2],
            ['code' => 'leclerc-country', 'question' => 'От коя държава е Шарл Льоклер?', 'options' => ['Франция', 'Монако', 'Италия', 'Швейцария'], 'correct' => 2],
            ['code' => 'norris-team', 'question' => 'За кой отбор кара Ландо Норис?', 'options' => ['Ферари', 'Мерцедес', 'Ред Бул', 'Макларън'], 'correct' => 4],
            ['code' => 'alonso-country', 'question' => 'От коя държава е Фернандо Алонсо?', 'options' => ['Испания', 'Португалия', 'Италия', 'Мексико'], 'correct' => 1],
            ['code' => 'russell-team', 'question' => 'За кой отбор кара Джордж Ръсел?', 'options' => ['Уилямс', 'Астън Мартин', 'Мерцедес', 'Алпайн'], 'correct' => 3],
            ['code' => 'soft-tyre-colour', 'question' => 'Какъв цвят е обозначението на най-меките сухи гуми на Pirelli?', 'options' => ['Бял', 'Червен', 'Жълт', 'Син'], 'correct' => 2],
            ['code' => 'silverstone-country', 'question' => 'В коя държава се намира пистата Силвърстоун?', 'options' => ['Германия', 'Италия', 'Великобритания', 'Белгия'], 'correct' => 3],
        ];
    }
}
