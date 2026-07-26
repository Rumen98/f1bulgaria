<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Български имена на пилотите
|--------------------------------------------------------------------------
|
| Ключът е slug-ът на каноничния пилот (Str::slug на латинското име).
|
| ЗАЩО СЪЩЕСТВУВА: българинът търси „Люис Хамилтън статистика", не
| „Lewis Hamilton". Google транслитерира слабо, а при нов домейн това не
| компенсира нищо. Кирилското име влиза в <h1>, <title> и описанието;
| латинското остава видимо като подзаглавие, за да хващаме и двете заявки.
|
| ЗАЩО НЕ Е АВТОМАТИЧНО: транслитерацията по правила бърка систематично —
| „Leclerc" дава „Леклерк" вместо „Льоклер", „Hamilton" дава „Хамилтон"
| вместо „Хамилтън". Грешното име не хваща търсенето И изглежда аматьорско.
|
| ОБХВАТ: настоящата решетка + шампиони + разпознаваеми имена. Останалите
| ~850 исторически пилоти остават на латиница — никой не ги търси на кирилица.
| Изписването следва нормата на българската спортна журналистика.
*/

return [

    // --- Настояща решетка ---
    'max-verstappen' => 'Макс Верстапен',
    'lewis-hamilton' => 'Люис Хамилтън',
    'charles-leclerc' => 'Шарл Льоклер',
    'lando-norris' => 'Ландо Норис',
    'oscar-piastri' => 'Оскар Пиастри',
    'george-russell' => 'Джордж Ръсел',
    'fernando-alonso' => 'Фернандо Алонсо',
    'carlos-sainz' => 'Карлос Сайнс',
    'sergio-perez' => 'Серхио Перес',
    'andrea-kimi-antonelli' => 'Андреа Кими Антонели',
    'kimi-antonelli' => 'Кими Антонели',
    'yuki-tsunoda' => 'Юки Цунода',
    'pierre-gasly' => 'Пиер Гасли',
    'esteban-ocon' => 'Естебан Окон',
    'lance-stroll' => 'Ланс Строл',
    'alexander-albon' => 'Алекс Албон',
    'liam-lawson' => 'Лиъм Лоусън',
    'oliver-bearman' => 'Оливър Беърман',
    'isack-hadjar' => 'Исак Хаджар',
    'gabriel-bortoleto' => 'Габриел Бортолето',
    'jack-doohan' => 'Джак Дуън',
    'franco-colapinto' => 'Франко Колапинто',
    'nico-hulkenberg' => 'Нико Хюлкенберг',
    'valtteri-bottas' => 'Валтери Ботас',
    'zhou-guanyu' => 'Джоу Гуанюй',
    'guanyu-zhou' => 'Джоу Гуанюй',
    'kevin-magnussen' => 'Кевин Магнусен',
    'daniel-ricciardo' => 'Даниел Рикардо',
    'logan-sargeant' => 'Логан Сарджънт',
    'arvid-lindblad' => 'Арвид Линдблад',
    'paul-aron' => 'Паул Арон',
    'nikola-tsolov' => 'Никола Цолов',

    // --- Световни шампиони ---
    'michael-schumacher' => 'Михаел Шумахер',
    'sebastian-vettel' => 'Себастиан Фетел',
    'ayrton-senna' => 'Айртон Сена',
    'alain-prost' => 'Ален Прост',
    'niki-lauda' => 'Ники Лауда',
    'nelson-piquet' => 'Нелсон Пике',
    'jackie-stewart' => 'Джеки Стюарт',
    'jim-clark' => 'Джим Кларк',
    'juan-manuel-fangio' => 'Хуан Мануел Фанджо',
    'nico-rosberg' => 'Нико Розберг',
    'jenson-button' => 'Дженсън Бътън',
    'kimi-raikkonen' => 'Кими Райконен',
    'mika-hakkinen' => 'Мика Хакинен',
    'damon-hill' => 'Деймън Хил',
    'jacques-villeneuve' => 'Жак Вилньов',
    'graham-hill' => 'Греъм Хил',
    'emerson-fittipaldi' => 'Емерсон Фитипалди',
    'mario-andretti' => 'Марио Андрети',
    'alan-jones' => 'Алън Джоунс',
    'keke-rosberg' => 'Кеке Розберг',
    'nigel-mansell' => 'Найджъл Мансел',
    'james-hunt' => 'Джеймс Хънт',
    'john-surtees' => 'Джон Съртийс',
    'jack-brabham' => 'Джак Брабам',
    'phil-hill' => 'Фил Хил',
    'denny-hulme' => 'Дени Хълм',
    'jochen-rindt' => 'Йохен Риндт',
    'mike-hawthorn' => 'Майк Хоторн',
    'alberto-ascari' => 'Алберто Аскари',
    'giuseppe-farina' => 'Джузепе Фарина',
    'jody-scheckter' => 'Джоди Шектър',

    // --- Разпознаваеми имена ---
    'felipe-massa' => 'Фелипе Маса',
    'gilles-villeneuve' => 'Жил Вилньов',
    'rubens-barrichello' => 'Рубенс Барикело',
    'david-coulthard' => 'Дейвид Култард',
    'ralf-schumacher' => 'Ралф Шумахер',
    'juan-pablo-montoya' => 'Хуан Пабло Монтоя',
    'mark-webber' => 'Марк Уебър',
    'romain-grosjean' => 'Ромен Грожан',
    'stirling-moss' => 'Стърлинг Мос',
    'ronnie-peterson' => 'Роние Петерсон',
    'carlos-reutemann' => 'Карлос Ройтеман',
    'clay-regazzoni' => 'Клей Регацони',
    'rene-arnoux' => 'Рьоне Арну',
    'jean-alesi' => 'Жан Алези',
    'gerhard-berger' => 'Герхард Бергер',
    'eddie-irvine' => 'Еди Ъруайн',
    'heinz-harald-frentzen' => 'Хайнц-Харалд Френцен',
    'giancarlo-fisichella' => 'Джанкарло Физикела',
    'jarno-trulli' => 'Ярно Трули',
    'nick-heidfeld' => 'Ник Хайдфелд',
    'robert-kubica' => 'Роберт Кубица',
    'sergio-sette-camara' => 'Сержио Сете Камара',
    'pastor-maldonado' => 'Пастор Малдонадо',
    'bruno-senna' => 'Бруно Сена',
    'jules-bianchi' => 'Жул Бианки',
    'anthoine-hubert' => 'Антоан Юбер',

];
