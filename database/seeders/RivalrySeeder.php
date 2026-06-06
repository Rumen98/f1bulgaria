<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DriverCanonical;
use App\Models\Rivalry;
use Illuminate\Database\Seeder;

class RivalrySeeder extends Seeder
{
    /**
     * Засява 8 емблематични съперничества. Свързва пилотите по slug — ако някой
     * каноничен запис липсва, съперничеството се пропуска (идемпотентно по slug).
     */
    public function run(): void
    {
        foreach ($this->rivalries() as $data) {
            $one = DriverCanonical::query()->where('slug', $data['one'])->first();
            $two = DriverCanonical::query()->where('slug', $data['two'])->first();

            if ($one === null || $two === null) {
                continue;
            }

            Rivalry::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'driver_one_canonical_id' => $one->id,
                    'driver_two_canonical_id' => $two->id,
                    'era_start_year' => $data['start'],
                    'era_end_year' => $data['end'],
                    'title_bg' => $data['title'],
                    'description_bg' => $data['description'],
                    'notable_moments' => $data['moments'],
                    'is_featured' => $data['featured'] ?? false,
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rivalries(): array
    {
        return [
            [
                'slug' => 'senna-vs-prost', 'one' => 'ayrton-senna', 'two' => 'alain-prost',
                'start' => 1988, 'end' => 1993, 'featured' => true,
                'title' => 'Сена срещу Прост',
                'description' => 'Може би най-интензивното съперничество в историята на Формула 1 — страстта на Сена '
                    .'срещу пресметливостта на Прост, първоначално като съотборници в Макларън, а после като заклети врагове.',
                'moments' => [
                    ['year' => 1989, 'description' => 'Сблъсъкът в Сузука решава титлата в полза на Прост.'],
                    ['year' => 1990, 'description' => 'Реваншът на Сена в Сузука — отново контакт, този път шампион е бразилецът.'],
                ],
            ],
            [
                'slug' => 'hamilton-vs-rosberg', 'one' => 'lewis-hamilton', 'two' => 'nico-rosberg',
                'start' => 2014, 'end' => 2016, 'featured' => true,
                'title' => 'Хамилтън срещу Розберг',
                'description' => 'Двама приятели от картинга се превръщат в съперници за титлата в доминиращия Мерцедес. '
                    .'Три сезона напрежение, което приключва с оттеглянето на Розберг веднага след шампионската му титла.',
                'moments' => [
                    ['year' => 2014, 'description' => 'Контакт в Спа изостря отношенията в отбора.'],
                    ['year' => 2016, 'description' => 'Розберг печели титлата и се оттегля на върха.'],
                ],
            ],
            [
                'slug' => 'schumacher-vs-hakkinen', 'one' => 'michael-schumacher', 'two' => 'mika-hakkinen',
                'start' => 1998, 'end' => 2001, 'featured' => true,
                'title' => 'Шумахер срещу Хакинен',
                'description' => 'Уважителна, но безкомпромисна битка между Ферари и Макларън на границата на хилядолетието. '
                    .'Шумахер смята финландеца за най-достойния си съперник.',
                'moments' => [
                    ['year' => 2000, 'description' => 'Изпреварването на Хакинен в Спа — едно от най-великите в историята.'],
                ],
            ],
            [
                'slug' => 'verstappen-vs-hamilton', 'one' => 'max-verstappen', 'two' => 'lewis-hamilton',
                'start' => 2021, 'end' => 2021, 'featured' => true,
                'title' => 'Верстапен срещу Хамилтън',
                'description' => 'Сезон 2021 — сблъсък на поколения и стилове, решен в последната обиколка на последното '
                    .'състезание в Абу Даби. Едно от най-противоречивите финали в историята на спорта.',
                'moments' => [
                    ['year' => 2021, 'description' => 'Финалът в Абу Даби дава титлата на Верстапен в последната обиколка.'],
                ],
            ],
            [
                'slug' => 'senna-vs-mansell', 'one' => 'ayrton-senna', 'two' => 'nigel-mansell',
                'start' => 1986, 'end' => 1992, 'featured' => false,
                'title' => 'Сена срещу Менсъл',
                'description' => 'Две от най-агресивните имена на своето време, чиито дуели по пистата винаги бяха на ръба.',
                'moments' => [
                    ['year' => 1992, 'description' => 'Легендарната битка в Монако — Менсъл атакува, Сена удържа победата.'],
                ],
            ],
            [
                'slug' => 'schumacher-vs-hill', 'one' => 'michael-schumacher', 'two' => 'damon-hill',
                'start' => 1994, 'end' => 1995, 'featured' => false,
                'title' => 'Шумахер срещу Хил',
                'description' => 'Възходящият Шумахер срещу британеца Деймън Хил — съперничество, белязано от спорния '
                    .'сблъсък в Аделаида през 1994 г.',
                'moments' => [
                    ['year' => 1994, 'description' => 'Контактът в Аделаида решава титлата за Шумахер.'],
                ],
            ],
            [
                'slug' => 'vettel-vs-webber', 'one' => 'sebastian-vettel', 'two' => 'mark-webber',
                'start' => 2010, 'end' => 2013, 'featured' => false,
                'title' => 'Фетел срещу Уебър',
                'description' => 'Вътрешнокомандно напрежение в Ред Бул — „Multi 21" се превърна в символ на съперничество '
                    .'между съотборници.',
                'moments' => [
                    ['year' => 2013, 'description' => '„Multi 21" — Фетел игнорира заповедта и изпреварва Уебър в Малайзия.'],
                ],
            ],
            [
                'slug' => 'prost-vs-mansell', 'one' => 'alain-prost', 'two' => 'nigel-mansell',
                'start' => 1990, 'end' => 1991, 'featured' => false,
                'title' => 'Прост срещу Менсъл',
                'description' => 'Съотборници във Ферари, чиито различни характери и амбиции доведоха до напрежение в '
                    .'легендарния тим от Маранело.',
                'moments' => [
                    ['year' => 1990, 'description' => 'Сезон на вътрешни battles във Ферари между двете звезди.'],
                ],
            ],
        ];
    }
}
