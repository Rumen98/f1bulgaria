/**
 * Визуална идентичност на пистите — това, което прави Монца да изглежда като
 * Монца, а не „някакво трасе с правилната форма".
 *
 * Формата и релефът идват от GPS данните; тук живее всичко останало:
 * страната на питлейна, характерът на run-off зоните, растителността,
 * теренът наоколо и светлината. Стойностите са проверени срещу реалните
 * писти (карти, гидове по трибуните, onboard обиколки) — вижте бележките.
 *
 * ВАЖНО за страните: 'right'/'left' са спрямо ПОСОКАТА НА КАРАНЕ. В кода
 * нормалата на трасето сочи надясно по посоката (виж track.js), така че
 * 'right' → +1 по нормалата, 'left' → -1.
 */

/**
 * @typedef {object} CircuitStyle
 * @property {'left'|'right'} pitSide      Страна на питлейна на старт/финалната права
 * @property {'gravel'|'asphalt'|'none'} runoff  Характер на run-off зоните в завоите
 * @property {boolean} streetWalls         Мантинели плътно по цялото трасе (градска писта)
 * @property {boolean} startGrandstands    Процедурни трибуни на старт/финала
 * @property {boolean} sausageKerbs        Оранжеви „наденички" зад кербовете на шиканите
 * @property {'deciduous'|'conifer'|'mixed'|'shrub'} trees
 * @property {number} [treeDensity]        >1 клонира дърветата (гъста гора)
 * @property {number} [buildingHeight]     Базова височина на OSM сградите, метри
 * @property {number} foliage              Цвят на короните
 * @property {number} grassTint            Тонира PBR текстурата на тревата
 * @property {number|null} crowdAccent     Доминиращ цвят на публиката (null = пъстра)
 * @property {{amplitude: number, base: number, accent: number}} terrain
 * @property {object} atmosphere           Слънце/мъгла/експозиция; hdri избира небето
 * @property {object|null} landmark        Специален обект (виенско колело, пристанище…)
 * @property {{from: number, to: number}} [tunnel]  Тунелна галерия (метри по обиколката)
 * @property {Array<{from: number, to: number, width: number}>} [widthProfile]
 *           Диапазони с различна ширина (метри по обиколката; OSM няма тези
 *           данни — стойностите са авторски, по реалните писти)
 * @property {Array<{from: number, to: number, deg: number}>} [banking]
 *           Банкирани завои: напречен наклон в градуси, посоката се извежда
 *           от кривината. Влияе на меша, колата И физиката (странична хватка).
 */

/** @type {Record<string, CircuitStyle>} */
export const CIRCUITS = {
    // Кралският парк: равна широколистна гора, златна септемврийска светлина,
    // чакъл в Лесмо/Аскари и прочутите оранжеви наденички на шиканите.
    monza: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: true,
        trees: 'deciduous',
        treeDensity: 2.4, // кралският парк е тунел от зеленина
        foliage: 0x3f6231,
        grassTint: 0xe6ecc8,
        crowdAccent: 0xd42a26, // тифозите
        terrain: { amplitude: 3, base: 0x2b452a, accent: 0x37552c },
        atmosphere: {
            sunElevation: 24,
            sunAzimuth: 140,
            sunIntensity: 2.7,
            sunColor: 0xffe2b0,
            fogColor: 0xcfd9d2,
            fogNear: 340,
            fogFar: 1200,
            exposure: 0.98,
        },
        landmark: null,
        // Спирачната зона на Rettifilo се разширява като фуния — старият път
        // на Монца е много по-широк от модерното трасе.
        widthProfile: [{ from: 430, to: 600, width: 18 }],
    },

    // Ардените: иглолистни хълмове, хладна светлина с лека мъгла в долината.
    spa: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'conifer',
        treeDensity: 2.6, // стените от смърчове на Ардените
        foliage: 0x2c4c2c,
        grassTint: 0xe4efdc,
        crowdAccent: null,
        terrain: { amplitude: 55, base: 0x24402a, accent: 0x2f5233 },
        atmosphere: {
            sunElevation: 34,
            sunAzimuth: 120,
            sunIntensity: 2.0,
            sunColor: 0xf2ead6,
            fogColor: 0xb8c4c8,
            fogNear: 260,
            fogFar: 900,
            exposure: 0.9,
            hdri: 'sky_overcast_2k', // Арденско небе — ниска облачност
        },
        landmark: null,
    },

    // Бивше летище: равно, огромно небе, широки асфалтови апрони, малко дървета.
    silverstone: {
        pitSide: 'right',
        runoff: 'asphalt',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: true,
        trees: 'deciduous',
        foliage: 0x40603a,
        grassTint: 0xeef0d8,
        crowdAccent: null,
        terrain: { amplitude: 4, base: 0x365234, accent: 0x585f38 },
        atmosphere: {
            sunElevation: 40,
            sunAzimuth: 150,
            sunIntensity: 2.5,
            sunColor: 0xfff4e0,
            fogColor: 0xccd8e4,
            fogNear: 380,
            fogFar: 1400,
            exposure: 1.0,
        },
        landmark: null,
    },

    // Градски каньон над Порт Еркюл: мантинели плътно до асфалта, нула чакъл,
    // яхти в пристанището, ярко средиземноморско слънце.
    monaco: {
        pitSide: 'right',
        runoff: 'none',
        streetWalls: true,
        startGrandstands: false, // няма място — трибуните на Монако са при пристанището
        sausageKerbs: false,
        buildingHeight: 16, // жилищните блокове правят градския каньон
        trees: 'deciduous',
        foliage: 0x4a6b3a,
        grassTint: 0xffffff,
        crowdAccent: null,
        // Градски склон, не плаж: сиво-маслинено, както теренът между сградите.
        terrain: { amplitude: 18, base: 0x6e7060, accent: 0x7c7e6c },
        atmosphere: {
            sunElevation: 52,
            sunAzimuth: 160,
            sunIntensity: 2.9,
            sunColor: 0xfff0d0,
            fogColor: 0xd6e0ea,
            fogNear: 420,
            fogFar: 1600,
            exposure: 1.02,
        },
        // Пристанището с яхтите — котвата на цялата сцена. Рамката е спрямо
        // трасето при `along` метра: център на `dist` метра по нормалата
        // (side=+1 → отдясно по посоката — водата е вдясно от шикана до
        // Rascasse), width по нормалата, depth по тангентата. Изчислено от
        // центроида на дъгата шикан→писин в данните.
        landmark: { type: 'harbor', along: 2660, side: 1, dist: 125, width: 160, depth: 210, waterY: -3.0 },
        // Тунелът под Fairmont: платото след Portier (виж височинния профил).
        tunnel: { from: 1140, to: 1500 },
    },

    // Японска провинция: гористи хребети, чакъл в почти всеки завой и виенското
    // колело на Мотопия зад стартовата права — силуетът на Сузука.
    suzuka: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: true, // Casio Triangle
        trees: 'mixed',
        treeDensity: 1.8,
        foliage: 0x35592c,
        grassTint: 0xe8f0d8,
        crowdAccent: null,
        terrain: { amplitude: 16, base: 0x2f4e2b, accent: 0x49682f },
        atmosphere: {
            sunElevation: 36,
            sunAzimuth: 135,
            sunIntensity: 2.4,
            sunColor: 0xfdeed6,
            fogColor: 0xc9d4d6,
            fogNear: 320,
            fogFar: 1150,
            exposure: 0.96,
        },
        landmark: { type: 'ferris_wheel', along: 220, side: 1, dist: 160 },
        // Сузука е по-тясна от модерните писти през по-голямата част от
        // обиколката (S-завоите, Degner, Spoon).
        widthProfile: [{ from: 1500, to: 4300, width: 11.5 }],
    },

    // Алпийско пасище в Щирия: ярка трева, смърчови хребети, кристален въздух.
    red_bull_ring: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'conifer',
        treeDensity: 2.0,
        foliage: 0x2e5030,
        grassTint: 0xe0f0cc,
        crowdAccent: 0xff7a1a, // оранжевата армия на Верстапен пътува и дотук
        terrain: { amplitude: 65, base: 0x3d5c2e, accent: 0x557636 },
        atmosphere: {
            sunElevation: 44,
            sunAzimuth: 150,
            sunIntensity: 2.8,
            sunColor: 0xfff6e4,
            fogColor: 0xc6d6e8,
            fogNear: 400,
            fogFar: 1500,
            exposure: 1.0,
        },
        landmark: null,
    },

    // Дюните на Северно море: пясък с трева, оранжеви трибуни, морска омара.
    zandvoort: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'shrub',
        treeDensity: 1.3,
        foliage: 0x5c6b3f,
        grassTint: 0xd8d8b0,
        crowdAccent: 0xff6a00, // оранжевата армия у дома
        // Дюните опират почти до банкета (rampNear/rampFar в метри от трасето).
        terrain: { amplitude: 24, base: 0x9a8a62, accent: 0x6f7a4b, rampNear: 35, rampFar: 130 },
        atmosphere: {
            sunElevation: 42,
            sunAzimuth: 145,
            sunIntensity: 2.6,
            sunColor: 0xfff0d8,
            fogColor: 0xd4dce2,
            fogNear: 420,
            fogFar: 1400,
            exposure: 1.03,
        },
        landmark: null,
        // Старата школа: тясна лента през дюните, с двата банкирани завоя —
        // Hugenholtz (T3) и финалният Arie Luyendijk (T14), по ~18°.
        widthProfile: [{ from: 950, to: 3450, width: 10.8 }],
        banking: [
            { from: 720, to: 830, deg: 18 },
            { from: 3540, to: 3660, deg: 18 },
        ],
    },

    // Амфитеатър в края на Сао Пауло: наситено зелено, тропическа омара,
    // питовете са ОТЛЯВО — трасето е обратно на часовника.
    interlagos: {
        pitSide: 'left',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'deciduous',
        treeDensity: 1.4,
        foliage: 0x3c6030,
        grassTint: 0xdcf0d0,
        crowdAccent: null,
        terrain: { amplitude: 10, base: 0x39572e, accent: 0x4c6339 },
        atmosphere: {
            sunElevation: 30,
            sunAzimuth: 115,
            sunIntensity: 2.3,
            sunColor: 0xffd9a8,
            fogColor: 0xc9c9bd,
            fogNear: 300,
            fogFar: 1100,
            exposure: 0.97,
        },
        landmark: null,
    },
    // Пустинята на Сахир: пясък до хоризонта, палмови туфи, златен здрач
    // (реалното състезание е по здрач/тъмно).
    bahrain: {
        pitSide: 'right',
        runoff: 'asphalt',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'shrub',
        foliage: 0x5d6b3a,
        grassTint: 0xd8cfa8,
        crowdAccent: null,
        terrain: { amplitude: 6, base: 0xb2925e, accent: 0xc4a878 },
        // Нощно състезание: прожекторите заливат пистата (directional-ът е
        // сборният им ефект), небето е тъмен здрач със звезди.
        atmosphere: {
            night: true,
            sunElevation: 56,
            sunAzimuth: 250,
            sunIntensity: 2.3,
            sunColor: 0xd8e6ff,
            fogColor: 0x10131c,
            fogNear: 260,
            fogFar: 950,
            exposure: 0.88,
        },
        landmark: null,
    },

    // Корнишът на Джеда: най-бързата градска писта, стени по цялото трасе,
    // Червено море отстрани, мека вечерна светлина.
    jeddah: {
        pitSide: 'right',
        runoff: 'none',
        streetWalls: true,
        startGrandstands: false,
        sausageKerbs: false,
        buildingHeight: 10,
        trees: 'shrub',
        foliage: 0x577049,
        grassTint: 0xdcd8b4,
        crowdAccent: null,
        terrain: { amplitude: 3, base: 0xa89a72, accent: 0xb8ab84 },
        // Нощната перла на Червено море.
        atmosphere: {
            night: true,
            sunElevation: 58,
            sunAzimuth: 255,
            sunIntensity: 2.4,
            sunColor: 0xdce8ff,
            fogColor: 0x0e1118,
            fogNear: 300,
            fogFar: 1050,
            exposure: 0.9,
        },
        landmark: null,
    },

    // Паркът Албърт около езерото: мелбърнска пролет, широколистни алеи.
    albert_park: {
        pitSide: 'right',
        runoff: 'asphalt',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'deciduous',
        treeDensity: 1.6,
        foliage: 0x3f6231,
        grassTint: 0xe2eec8,
        crowdAccent: null,
        terrain: { amplitude: 3, base: 0x2f4e2b, accent: 0x3f5c30 },
        atmosphere: {
            sunElevation: 38,
            sunAzimuth: 140,
            sunIntensity: 2.6,
            sunColor: 0xfff2da,
            fogColor: 0xcfdce6,
            fogNear: 380,
            fogFar: 1300,
            exposure: 1.0,
        },
        landmark: null,
    },

    // Шанхай: гигантски модерен комплекс в оризова равнина, млечна омара.
    shanghai: {
        pitSide: 'right',
        runoff: 'asphalt',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'mixed',
        foliage: 0x3a5c33,
        grassTint: 0xe0ead0,
        crowdAccent: null,
        terrain: { amplitude: 2, base: 0x37522f, accent: 0x475f33 },
        atmosphere: {
            sunElevation: 34,
            sunAzimuth: 145,
            sunIntensity: 2.1,
            sunColor: 0xf6ecd8,
            fogColor: 0xd2d6d2,
            fogNear: 240,
            fogFar: 900,
            exposure: 0.93,
        },
        landmark: null,
    },

    // Маями: около стадиона, палми и флоридско слънце.
    miami: {
        pitSide: 'right',
        runoff: 'asphalt',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'shrub',
        treeDensity: 1.2,
        foliage: 0x4c7a40,
        grassTint: 0xdff0cc,
        crowdAccent: null,
        terrain: { amplitude: 2, base: 0x3d5a31, accent: 0x527040 },
        atmosphere: {
            sunElevation: 56,
            sunAzimuth: 150,
            sunIntensity: 2.9,
            sunColor: 0xfff4dc,
            fogColor: 0xd8e4ec,
            fogNear: 420,
            fogFar: 1500,
            exposure: 1.04,
        },
        landmark: null,
    },

    // Имола: старата школа в парка край Сантерно — чакъл, гъста зеленина,
    // топла емилианска светлина. Наденички на Variante Alta.
    imola: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: true,
        trees: 'deciduous',
        treeDensity: 2.2,
        foliage: 0x3c6030,
        grassTint: 0xe4ecc6,
        crowdAccent: 0xd42a26, // тифозите и тук
        terrain: { amplitude: 14, base: 0x2e4c2a, accent: 0x3d5a2e },
        atmosphere: {
            sunElevation: 30,
            sunAzimuth: 140,
            sunIntensity: 2.5,
            sunColor: 0xffe8c0,
            fogColor: 0xccd6cc,
            fogNear: 320,
            fogFar: 1150,
            exposure: 0.97,
        },
        landmark: null,
    },

    // Каталуния: сухи хълмове над Барселона, прашна зеленина.
    catalunya: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'mixed',
        treeDensity: 1.4,
        foliage: 0x4a5e34,
        grassTint: 0xdcdcb0,
        crowdAccent: null,
        terrain: { amplitude: 18, base: 0x4e5a30, accent: 0x6a6b3c },
        atmosphere: {
            sunElevation: 46,
            sunAzimuth: 150,
            sunIntensity: 2.8,
            sunColor: 0xfff2d8,
            fogColor: 0xd4dce4,
            fogNear: 380,
            fogFar: 1350,
            exposure: 1.01,
        },
        landmark: null,
    },

    // Остров Нотр-Дам: тесен парков пръстен между гребния канал и Сейнт
    // Лорънс — стени близо до трасето, канадска зеленина.
    villeneuve: {
        pitSide: 'right',
        runoff: 'none',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'deciduous',
        treeDensity: 1.8,
        foliage: 0x37602f,
        grassTint: 0xe0eecb,
        crowdAccent: null,
        terrain: { amplitude: 2, base: 0x2e4c2b, accent: 0x3c5a30 },
        atmosphere: {
            sunElevation: 40,
            sunAzimuth: 145,
            sunIntensity: 2.6,
            sunColor: 0xfff2da,
            fogColor: 0xd0dee8,
            fogNear: 380,
            fogFar: 1300,
            exposure: 1.0,
        },
        landmark: null,
    },

    // Хунгароринг: прашната купа край Будапеща — амфитеатър от изгоряла трева.
    hungaroring: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'deciduous',
        treeDensity: 1.5,
        foliage: 0x46602f,
        grassTint: 0xdcd4a0,
        crowdAccent: null,
        terrain: { amplitude: 16, base: 0x50582e, accent: 0x6c683a },
        atmosphere: {
            sunElevation: 48,
            sunAzimuth: 150,
            sunIntensity: 2.9,
            sunColor: 0xfff0cc,
            fogColor: 0xdcd8c4,
            fogNear: 340,
            fogFar: 1200,
            exposure: 1.02,
        },
        landmark: null,
    },

    // Баку: крепостни стени и петролен вятър — тесният сектор край Стария
    // град, стени навсякъде.
    baku: {
        pitSide: 'right',
        runoff: 'none',
        streetWalls: true,
        startGrandstands: false,
        sausageKerbs: false,
        buildingHeight: 14,
        trees: 'mixed',
        foliage: 0x4c6438,
        grassTint: 0xd8d4ac,
        crowdAccent: null,
        terrain: { amplitude: 8, base: 0x8c845e, accent: 0x9c9270 },
        atmosphere: {
            sunElevation: 36,
            sunAzimuth: 150,
            sunIntensity: 2.5,
            sunColor: 0xfeeecd,
            fogColor: 0xd6d2c2,
            fogNear: 320,
            fogFar: 1150,
            exposure: 0.98,
        },
        landmark: null,
    },

    // Марина Бей: небостъргачи над залива — каньонът на нощното състезание.
    marina_bay: {
        pitSide: 'right',
        runoff: 'none',
        streetWalls: true,
        startGrandstands: false,
        sausageKerbs: false,
        buildingHeight: 26,
        trees: 'shrub',
        treeDensity: 1.2,
        foliage: 0x3f7040,
        grassTint: 0xd6ecc4,
        crowdAccent: null,
        terrain: { amplitude: 2, base: 0x50604a, accent: 0x606e54 },
        // Оригиналното нощно състезание: каньон от светлина под тъмно небе.
        atmosphere: {
            night: true,
            sunElevation: 54,
            sunAzimuth: 250,
            sunIntensity: 2.2,
            sunColor: 0xd6e4ff,
            fogColor: 0x121016,
            fogNear: 240,
            fogFar: 900,
            exposure: 0.87,
        },
        landmark: null,
    },

    // COTA: тексаска прерия, широки асфалтови зони и хълмът на завой 1.
    americas: {
        pitSide: 'right',
        runoff: 'asphalt',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: true,
        trees: 'shrub',
        foliage: 0x55703c,
        grassTint: 0xe0e0b0,
        crowdAccent: null,
        terrain: { amplitude: 14, base: 0x4c5c30, accent: 0x6c6c3c },
        atmosphere: {
            sunElevation: 44,
            sunAzimuth: 155,
            sunIntensity: 2.8,
            sunColor: 0xfff2d4,
            fogColor: 0xd8dce0,
            fogNear: 400,
            fogFar: 1400,
            exposure: 1.01,
        },
        landmark: null,
    },

    // Мексико Сити: паркът Магдалена Микстука на 2200 м — разредена мараня,
    // стадионът Форо Сол.
    rodriguez: {
        pitSide: 'right',
        runoff: 'asphalt',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'deciduous',
        treeDensity: 1.6,
        foliage: 0x3a5e31,
        grassTint: 0xdeeac8,
        crowdAccent: 0x159447, // зеленото на трибуните у дома
        terrain: { amplitude: 4, base: 0x37522e, accent: 0x475f34 },
        atmosphere: {
            sunElevation: 52,
            sunAzimuth: 150,
            sunIntensity: 2.7,
            sunColor: 0xfff0d2,
            fogColor: 0xd2cec2,
            fogNear: 280,
            fogFar: 1000,
            exposure: 0.99,
        },
        landmark: null,
    },

    // Вегас: Стрип-ът — стъклени кули, неон и студен пустинен здрач.
    vegas: {
        pitSide: 'right',
        runoff: 'none',
        streetWalls: true,
        startGrandstands: false,
        sausageKerbs: false,
        buildingHeight: 34,
        trees: 'shrub',
        foliage: 0x50663c,
        grassTint: 0xd4d0a8,
        crowdAccent: null,
        terrain: { amplitude: 3, base: 0x9a8c66, accent: 0xaa9c76 },
        // Стрип-ът нощем — най-студената, най-неоновата светлина в календара.
        atmosphere: {
            night: true,
            sunElevation: 52,
            sunAzimuth: 245,
            sunIntensity: 2.1,
            sunColor: 0xcfe0ff,
            fogColor: 0x0a0d16,
            fogNear: 280,
            fogFar: 1000,
            exposure: 0.85,
        },
        landmark: null,
    },

    // Лусаил: пустинен пръстен под прожектори — злато и мрак.
    losail: {
        pitSide: 'right',
        runoff: 'gravel',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'shrub',
        foliage: 0x5d6b3a,
        grassTint: 0xd8cea4,
        crowdAccent: null,
        terrain: { amplitude: 3, base: 0xb0925c, accent: 0xc2a674 },
        // Пустинен пръстен под прожектори.
        atmosphere: {
            night: true,
            sunElevation: 56,
            sunAzimuth: 252,
            sunIntensity: 2.3,
            sunColor: 0xd8e6ff,
            fogColor: 0x11131a,
            fogNear: 280,
            fogFar: 1000,
            exposure: 0.89,
        },
        landmark: null,
    },

    // Яс Марина: марината на Абу Даби — палми, вода и залязващо слънце.
    yas_marina: {
        pitSide: 'right',
        runoff: 'asphalt',
        streetWalls: false,
        startGrandstands: true,
        sausageKerbs: false,
        trees: 'shrub',
        treeDensity: 1.3,
        foliage: 0x4c7444,
        grassTint: 0xdcecc8,
        crowdAccent: null,
        terrain: { amplitude: 2, base: 0xa8955e, accent: 0xb8a878 },
        // Здрач → нощ: състезанието започва по залез и завършва под прожектори.
        atmosphere: {
            night: true,
            sunElevation: 55,
            sunAzimuth: 250,
            sunIntensity: 2.3,
            sunColor: 0xdae6ff,
            fogColor: 0x101219,
            fogNear: 300,
            fogFar: 1050,
            exposure: 0.9,
        },
        landmark: null,
    },
};

/** Неутрален стил за писта без запис — играта работи и без идентичност. */
const DEFAULT_STYLE = {
    pitSide: 'right',
    runoff: 'gravel',
    streetWalls: false,
    startGrandstands: true,
    sausageKerbs: false,
    trees: 'mixed',
    foliage: 0x2f5233,
    grassTint: 0xffffff,
    crowdAccent: null,
    terrain: { amplitude: 8, base: 0x24402a, accent: 0x315233 },
    atmosphere: {
        sunElevation: 32,
        sunAzimuth: 130,
        sunIntensity: 2.6,
        sunColor: 0xfff2d8,
        fogColor: 0xbcd3e6,
        fogNear: 300,
        fogFar: 1100,
        exposure: 0.95,
    },
    landmark: null,
};

/**
 * @param {string} slug
 * @returns {CircuitStyle}
 */
export function circuitFor(slug) {
    return CIRCUITS[slug] ?? DEFAULT_STYLE;
}
