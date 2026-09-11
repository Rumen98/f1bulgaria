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
 * @property {CircuitLook} look            Светлина, въздух и далечина (виж CircuitLook)
 */

/**
 * Общата таблица „как изглежда пистата", четена от атмосферата, терена,
 * растителността, фона, настилките, фасадите и грейда. Всеки запис в CIRCUITS
 * се допълва с DEFAULT_LOOK при зареждане (lookFor/circuitFor), така че
 * консуматорите винаги виждат пълната схема; при съмнение четете с `??`.
 *
 * @typedef {object} CircuitLook
 * @property {'day'|'golden'|'overcast'|'dusk'|'night'} preset  Светлинен preset (atmosphere.js PRESETS)
 * @property {{sat: number, warm: number[], lift: number[], splitHigh: number[], contrast: number, vignette: number}} grade
 *           Грейдът на postfx.js (ключовете на GRADE_DEFAULTS)
 * @property {{density: number, height: number, sunScatter: number, falloff: number, floor: number, horizonMix: number}} fog
 *           density: мащаб на fogNear/fogFar (1 = авторските); height: дял на
 *           височинната мъгла 0..1; sunScatter: сияние към слънцето 0..1;
 *           falloff: 1/m (0.0055 → 35% по-рядка на +80 m); floor: отместване
 *           на пода спрямо най-ниската точка на трасето, m; horizonMix: тегло
 *           на семплираното небе спрямо fogColor
 * @property {number} cloudShadow          Сила на облачните сенки по земята 0..1 (0 = без)
 * @property {{cover: number, speed: number}} clouds  Покритие 0..1 и скорост на дрейфа, m/s
 * @property {{type: 'mountains'|'treeline'|'skyline'|'dunes'|'sea'|'none', colour: number, height: number, distance: number}} backdrop
 *           Силуетът на хоризонта: височина и радиус в метри
 * @property {{kind: 'grass'|'sand'|'mixed', scale: number, ridged: boolean, relief: number}} terrain
 *           Настилка на терена, дължина на вълната на релефа (×), ridged
 *           профил (дюни, хребети) и множител на амплитудата
 * @property {number} treeScale            Множител на височината на дърветата
 * @property {number} treeDensity          Множител на гъстотата (огледало на treeDensity)
 * @property {{colour: number, windows: boolean}} facade  OSM фасади: цвят и светещи прозорци
 * @property {{brands: string[]}} hoardings  Неутрални състезателни надписи по паната
 * @property {{x: number, z: number, speed: number}} wind  Единна посока на вятъра (дървета, флагове, дим, облаци) и скорост m/s
 * @property {number} haze                 Топлинна мараня над асфалта 0..1
 */

/** Описателни фрази без фирми, продукти и търговски марки. */
const TRACKSIDE_MESSAGES = Object.freeze(['ИГРА', 'АПЕКС', 'ЧИСТА ЛИНИЯ', 'СЕКТОР', 'ПИТ ЛЕЙН']);

/** Мек мащаб на пряката светлина по атмосферен режим. */
const DIRECT_LIGHT_SCALE = Object.freeze({ day: 0.76, golden: 0.66, overcast: 0.82, dusk: 0.7, night: 0.82 });

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
        // Златна септемврийска светлина; горите на парка затварят хоризонта.
        look: {
            preset: 'golden',
            grade: { sat: 1.12, warm: [1.05, 1, 0.94], lift: [0, 0.002, 0.003], splitHigh: [0.02, 0.01, 0], contrast: 1.04, vignette: 0.16 },
            fog: { density: 1, height: 0.6, sunScatter: 0.6 },
            cloudShadow: 0.3,
            clouds: { cover: 0.3, speed: 6 },
            backdrop: { type: 'treeline', colour: 0x2f4a2a, height: 26, distance: 900 },
            terrain: { kind: 'grass', scale: 1, ridged: false, relief: 1 },
            treeScale: 2.2,
            facade: { colour: 0xd9cdb8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.6, z: 0.8, speed: 3 },
            haze: 0.3,
        },
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
        // Арденско небе: облачно, хладно, с мъглив под в долините и хребети наоколо.
        look: {
            preset: 'overcast',
            grade: { sat: 0.9, warm: [0.97, 1, 1.04], lift: [0, 0.002, 0.005], splitHigh: [0, 0, 0], contrast: 0.96, vignette: 0.2 },
            fog: { density: 1.15, height: 0.85, sunScatter: 0.15 },
            cloudShadow: 0.7,
            clouds: { cover: 0.85, speed: 14 },
            backdrop: { type: 'mountains', colour: 0x2a3d33, height: 140, distance: 1300 },
            terrain: { kind: 'grass', scale: 2.2, ridged: true, relief: 1.3 },
            treeScale: 2.4,
            facade: { colour: 0xb8b4a8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.5, z: -0.85, speed: 9 },
            haze: 0.1,
        },
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
        // Голямо променливо небе над равнината: облачни сенки бягат по асфалта.
        look: {
            preset: 'day',
            grade: { sat: 1.02, warm: [1, 1, 1.01], lift: [0, 0.0015, 0.004], splitHigh: [0, 0, 0], contrast: 1, vignette: 0.16 },
            fog: { density: 0.9, height: 0.35, sunScatter: 0.3 },
            cloudShadow: 0.55,
            clouds: { cover: 0.6, speed: 12 },
            backdrop: { type: 'treeline', colour: 0x46603e, height: 14, distance: 1200 },
            terrain: { kind: 'grass', scale: 1.3, ridged: false, relief: 0.6 },
            treeScale: 1.4,
            facade: { colour: 0xc8c4b8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.8, z: 0.6, speed: 8 },
            haze: 0.2,
        },
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
        // Средиземноморска яснота: Тет дьо Шиен над града, охра фасади, лек бриз.
        look: {
            preset: 'day',
            grade: { sat: 1.08, warm: [1.03, 1, 0.97], lift: [0, 0.001, 0.003], splitHigh: [0.01, 0.005, 0], contrast: 1.02, vignette: 0.14 },
            fog: { density: 0.8, height: 0.4, sunScatter: 0.35 },
            cloudShadow: 0,
            clouds: { cover: 0.15, speed: 5 },
            backdrop: { type: 'mountains', colour: 0x8a8a7a, height: 220, distance: 1100 },
            terrain: { kind: 'mixed', scale: 1.4, ridged: true, relief: 1.2 },
            treeScale: 1.2,
            facade: { colour: 0xd9c7a8, windows: true },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.2, z: 0.98, speed: 3 },
            haze: 0.3,
        },
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
        // Гористи хребети на Мие, влажен въздух, купести облаци.
        look: {
            preset: 'day',
            grade: { sat: 1.06, warm: [1.02, 1, 0.98], lift: [0, 0.002, 0.004], splitHigh: [0, 0, 0], contrast: 1, vignette: 0.16 },
            fog: { density: 1, height: 0.55, sunScatter: 0.4 },
            cloudShadow: 0.35,
            clouds: { cover: 0.45, speed: 8 },
            backdrop: { type: 'mountains', colour: 0x3a5a3c, height: 120, distance: 1200 },
            terrain: { kind: 'grass', scale: 1.6, ridged: true, relief: 1 },
            treeScale: 1.8,
            facade: { colour: 0xc4c0b4, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.7, z: 0.7, speed: 5 },
            haze: 0.4,
        },
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
        // Щирийските Алпи: кристален въздух, високи хребети, смърчове.
        look: {
            preset: 'day',
            grade: { sat: 1.1, warm: [1, 1, 1.02], lift: [0, 0.0015, 0.004], splitHigh: [0, 0, 0], contrast: 1.03, vignette: 0.16 },
            fog: { density: 0.85, height: 0.5, sunScatter: 0.3 },
            cloudShadow: 0.45,
            clouds: { cover: 0.5, speed: 10 },
            backdrop: { type: 'mountains', colour: 0x4a5e50, height: 260, distance: 1400 },
            terrain: { kind: 'grass', scale: 2.2, ridged: true, relief: 1.4 },
            treeScale: 2.4,
            facade: { colour: 0xd0ccc0, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.6, z: -0.8, speed: 6 },
            haze: 0.1,
        },
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
        // Северно море: дюни, морска омара, силен западен вятър.
        look: {
            preset: 'day',
            grade: { sat: 1.05, warm: [1.02, 1, 1], lift: [0, 0.0015, 0.004], splitHigh: [0, 0, 0], contrast: 1, vignette: 0.16 },
            fog: { density: 1.1, height: 0.5, sunScatter: 0.4 },
            cloudShadow: 0.5,
            clouds: { cover: 0.55, speed: 15 },
            backdrop: { type: 'dunes', colour: 0x9d9270, height: 22, distance: 800 },
            terrain: { kind: 'sand', scale: 0.45, ridged: true, relief: 1 },
            treeScale: 0.9,
            facade: { colour: 0xc8bfa8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.95, z: 0.3, speed: 12 },
            haze: 0.2,
        },
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
        // Тропическа златна светлина; кулите на Сао Пауло на хоризонта.
        look: {
            preset: 'golden',
            grade: { sat: 1.1, warm: [1.04, 1, 0.95], lift: [0, 0.002, 0.003], splitHigh: [0.02, 0.01, 0], contrast: 1.02, vignette: 0.16 },
            fog: { density: 1, height: 0.5, sunScatter: 0.5 },
            cloudShadow: 0.3,
            clouds: { cover: 0.4, speed: 7 },
            backdrop: { type: 'skyline', colour: 0x9a9a9a, height: 60, distance: 1400 },
            terrain: { kind: 'grass', scale: 1.2, ridged: false, relief: 1 },
            treeScale: 1.5,
            facade: { colour: 0xc9c2b0, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.8, z: 0.6, speed: 5 },
            haze: 0.5,
        },
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
        // Пустинна нощ: студен грейд под прожекторите, ниски дюни в мрака.
        look: {
            preset: 'night',
            grade: { sat: 1.0, warm: [0.96, 0.99, 1.06], lift: [0, 0.004, 0.01], splitHigh: [0, 0, 0], contrast: 1.02, vignette: 0.22 },
            fog: { density: 0.9, height: 0.3, sunScatter: 0 },
            cloudShadow: 0,
            clouds: { cover: 0.2, speed: 4 },
            backdrop: { type: 'dunes', colour: 0x2a2620, height: 18, distance: 900 },
            terrain: { kind: 'sand', scale: 1.6, ridged: false, relief: 0.5 },
            treeScale: 1,
            facade: { colour: 0x5a5040, windows: true },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.7, z: 0.7, speed: 4 },
            haze: 0.3,
        },
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
        // Корнишът нощем: силует на кули по Червено море, влажен въздух.
        look: {
            preset: 'night',
            grade: { sat: 1.02, warm: [0.97, 0.99, 1.06], lift: [0.002, 0.004, 0.01], splitHigh: [0.01, 0, 0.02], contrast: 1.02, vignette: 0.22 },
            fog: { density: 1, height: 0.3, sunScatter: 0 },
            cloudShadow: 0,
            clouds: { cover: 0.15, speed: 5 },
            backdrop: { type: 'skyline', colour: 0x1a1e2a, height: 90, distance: 1200 },
            terrain: { kind: 'sand', scale: 1.6, ridged: false, relief: 0.3 },
            treeScale: 1.1,
            facade: { colour: 0x606a78, windows: true },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.9, z: -0.4, speed: 5 },
            haze: 0.4,
        },
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
        // Мелбърнска пролет: CBD-то отвъд езерото, чист южен въздух.
        look: {
            preset: 'day',
            grade: { sat: 1.06, warm: [1.01, 1, 1], lift: [0, 0.0015, 0.004], splitHigh: [0, 0, 0], contrast: 1, vignette: 0.16 },
            fog: { density: 0.95, height: 0.4, sunScatter: 0.35 },
            cloudShadow: 0.4,
            clouds: { cover: 0.5, speed: 11 },
            backdrop: { type: 'skyline', colour: 0x8a94a4, height: 120, distance: 1500 },
            terrain: { kind: 'grass', scale: 1, ridged: false, relief: 0.5 },
            treeScale: 1.6,
            facade: { colour: 0xc6c2b8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.5, z: 0.85, speed: 8 },
            haze: 0.2,
        },
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
            hdri: 'sky_overcast_2k', // млечното пролетно небе на Шанхай
        },
        landmark: null,
        // Млечна пролетна омара над оризовата равнина; облачно небе.
        look: {
            preset: 'overcast',
            grade: { sat: 0.92, warm: [0.99, 1, 1.02], lift: [0, 0.002, 0.004], splitHigh: [0, 0, 0], contrast: 0.97, vignette: 0.18 },
            fog: { density: 1.3, height: 0.4, sunScatter: 0.2 },
            cloudShadow: 0.1,
            clouds: { cover: 0.9, speed: 6 },
            backdrop: { type: 'skyline', colour: 0x9aa0a6, height: 80, distance: 1400 },
            terrain: { kind: 'grass', scale: 1, ridged: false, relief: 0.3 },
            treeScale: 1.1,
            facade: { colour: 0xc0c4c8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.7, z: 0.7, speed: 5 },
            haze: 0.7,
        },
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
        // Флорида: наситено, влажно слънце; кулите на даунтауна далеч на хоризонта.
        look: {
            preset: 'day',
            grade: { sat: 1.14, warm: [1.03, 1, 0.96], lift: [0, 0.001, 0.003], splitHigh: [0.01, 0.005, 0], contrast: 1.03, vignette: 0.14 },
            fog: { density: 0.9, height: 0.3, sunScatter: 0.35 },
            cloudShadow: 0.25,
            clouds: { cover: 0.45, speed: 9 },
            backdrop: { type: 'skyline', colour: 0xa8b4c0, height: 110, distance: 1500 },
            terrain: { kind: 'grass', scale: 1, ridged: false, relief: 0.3 },
            treeScale: 1.3,
            facade: { colour: 0xe0dcd0, windows: true },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.9, z: -0.4, speed: 7 },
            haze: 0.8,
        },
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
        // Емилия: топла светлина, предпланините на Апенините над Сантерно.
        look: {
            preset: 'day',
            grade: { sat: 1.08, warm: [1.04, 1, 0.96], lift: [0, 0.0015, 0.003], splitHigh: [0.015, 0.005, 0], contrast: 1.02, vignette: 0.16 },
            fog: { density: 1, height: 0.55, sunScatter: 0.45 },
            cloudShadow: 0.3,
            clouds: { cover: 0.35, speed: 6 },
            backdrop: { type: 'mountains', colour: 0x4a5a3a, height: 70, distance: 1000 },
            terrain: { kind: 'grass', scale: 1.4, ridged: false, relief: 1.1 },
            treeScale: 2.0,
            facade: { colour: 0xd4c4a8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.5, z: 0.85, speed: 4 },
            haze: 0.3,
        },
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
        // Сухи каталунски хълмове, Монсени в далечината, прашна мараня.
        look: {
            preset: 'day',
            grade: { sat: 1.08, warm: [1.03, 1, 0.97], lift: [0, 0.0015, 0.003], splitHigh: [0.01, 0.005, 0], contrast: 1.02, vignette: 0.16 },
            fog: { density: 0.9, height: 0.45, sunScatter: 0.4 },
            cloudShadow: 0.2,
            clouds: { cover: 0.3, speed: 7 },
            backdrop: { type: 'mountains', colour: 0x6a6e5a, height: 160, distance: 1400 },
            terrain: { kind: 'mixed', scale: 1.5, ridged: true, relief: 1.2 },
            treeScale: 1.3,
            facade: { colour: 0xd0c4a8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.3, z: 0.95, speed: 6 },
            haze: 0.6,
        },
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
        // Островът в Сейнт Лорънс: Монреал отвъд реката, свеж северен въздух.
        look: {
            preset: 'day',
            grade: { sat: 1.06, warm: [1.01, 1, 1], lift: [0, 0.0015, 0.004], splitHigh: [0, 0, 0], contrast: 1, vignette: 0.16 },
            fog: { density: 0.95, height: 0.4, sunScatter: 0.35 },
            cloudShadow: 0.4,
            clouds: { cover: 0.5, speed: 10 },
            backdrop: { type: 'skyline', colour: 0x8c96a4, height: 100, distance: 1500 },
            terrain: { kind: 'grass', scale: 1, ridged: false, relief: 0.2 },
            treeScale: 1.6,
            facade: { colour: 0xc4c0b8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.8, z: 0.6, speed: 8 },
            haze: 0.2,
        },
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
        // Прашната купа: изгоряла трева, лятна мараня, хълмове наоколо.
        look: {
            preset: 'day',
            grade: { sat: 1.1, warm: [1.04, 1, 0.95], lift: [0, 0.0015, 0.003], splitHigh: [0.015, 0.008, 0], contrast: 1.02, vignette: 0.16 },
            fog: { density: 0.9, height: 0.5, sunScatter: 0.45 },
            cloudShadow: 0.25,
            clouds: { cover: 0.35, speed: 6 },
            backdrop: { type: 'mountains', colour: 0x6a6a4a, height: 60, distance: 1000 },
            terrain: { kind: 'mixed', scale: 1.3, ridged: false, relief: 1.2 },
            treeScale: 1.4,
            facade: { colour: 0xd0c8b0, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.6, z: 0.8, speed: 5 },
            haze: 0.9,
        },
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
        // Каспийски вятър, пясъчник и Пламъчните кули над Стария град.
        look: {
            preset: 'day',
            grade: { sat: 1.04, warm: [1.02, 1, 0.98], lift: [0, 0.0015, 0.004], splitHigh: [0, 0, 0], contrast: 1, vignette: 0.16 },
            fog: { density: 1.05, height: 0.4, sunScatter: 0.35 },
            cloudShadow: 0.15,
            clouds: { cover: 0.3, speed: 9 },
            backdrop: { type: 'skyline', colour: 0x9a9484, height: 120, distance: 1200 },
            terrain: { kind: 'mixed', scale: 1.2, ridged: false, relief: 0.6 },
            treeScale: 1.2,
            facade: { colour: 0xc9b48a, windows: true },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.3, z: -0.95, speed: 11 },
            haze: 0.3,
        },
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
        // Нощен каньон от светлина: небостъргачите на залива, влажна тропическа омара.
        look: {
            preset: 'night',
            grade: { sat: 1.05, warm: [0.96, 0.99, 1.06], lift: [0, 0.006, 0.012], splitHigh: [0.02, 0, 0.02], contrast: 1.02, vignette: 0.22 },
            fog: { density: 1.1, height: 0.3, sunScatter: 0 },
            cloudShadow: 0,
            clouds: { cover: 0.3, speed: 3 },
            backdrop: { type: 'skyline', colour: 0x141a26, height: 200, distance: 1000 },
            terrain: { kind: 'grass', scale: 1, ridged: false, relief: 0.2 },
            treeScale: 1.3,
            facade: { colour: 0x3a4250, windows: true },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.6, z: 0.8, speed: 3 },
            haze: 0.5,
        },
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
        // Тексаска прерия: широко небе, ниски хълмове, лятна мараня.
        look: {
            preset: 'day',
            grade: { sat: 1.1, warm: [1.03, 1, 0.97], lift: [0, 0.0015, 0.003], splitHigh: [0.01, 0.005, 0], contrast: 1.02, vignette: 0.16 },
            fog: { density: 0.85, height: 0.45, sunScatter: 0.35 },
            cloudShadow: 0.3,
            clouds: { cover: 0.4, speed: 9 },
            backdrop: { type: 'mountains', colour: 0x707858, height: 60, distance: 1300 },
            terrain: { kind: 'mixed', scale: 1.5, ridged: false, relief: 1.1 },
            treeScale: 1.2,
            facade: { colour: 0xd4ccb8, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.2, z: -0.98, speed: 8 },
            haze: 0.8,
        },
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
        // 2200 m: разреден въздух, гъста градска мараня, вулканите на хоризонта.
        look: {
            preset: 'day',
            grade: { sat: 1.06, warm: [1.02, 1, 0.98], lift: [0, 0.002, 0.004], splitHigh: [0, 0, 0], contrast: 1, vignette: 0.16 },
            fog: { density: 1.3, height: 0.35, sunScatter: 0.45 },
            cloudShadow: 0.2,
            clouds: { cover: 0.35, speed: 5 },
            backdrop: { type: 'mountains', colour: 0x7a7e8a, height: 260, distance: 1500 },
            terrain: { kind: 'grass', scale: 1, ridged: false, relief: 0.4 },
            treeScale: 1.6,
            facade: { colour: 0xccc4b0, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.7, z: 0.7, speed: 4 },
            haze: 0.9,
        },
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
        // Стрип-ът: неонов грейд (студени сенки, топли светлини), стъклени кули.
        look: {
            preset: 'night',
            grade: { sat: 1.15, warm: [0.96, 0.99, 1.06], lift: [0, 0.01, 0.03], splitHigh: [0.03, 0, 0.02], contrast: 1.06, vignette: 0.22 },
            fog: { density: 0.9, height: 0.3, sunScatter: 0 },
            cloudShadow: 0,
            clouds: { cover: 0.1, speed: 4 },
            backdrop: { type: 'skyline', colour: 0x10141e, height: 180, distance: 900 },
            terrain: { kind: 'sand', scale: 1.6, ridged: false, relief: 0.3 },
            treeScale: 1,
            facade: { colour: 0x6a8bb0, windows: true },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.7, z: -0.7, speed: 5 },
            haze: 0.2,
        },
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
        // Пустинен пръстен под прожектори: злато и мрак, дюни в тъмното.
        look: {
            preset: 'night',
            grade: { sat: 1.0, warm: [0.96, 0.99, 1.06], lift: [0, 0.004, 0.01], splitHigh: [0, 0, 0], contrast: 1.02, vignette: 0.22 },
            fog: { density: 0.95, height: 0.3, sunScatter: 0 },
            cloudShadow: 0,
            clouds: { cover: 0.15, speed: 6 },
            backdrop: { type: 'dunes', colour: 0x26221c, height: 15, distance: 900 },
            terrain: { kind: 'sand', scale: 1.6, ridged: false, relief: 0.4 },
            treeScale: 1,
            facade: { colour: 0x5a5040, windows: false },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: -0.85, z: 0.5, speed: 6 },
            haze: 0.3,
        },
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
        // Марината нощем: хотелът и яхтите светят, палми, топъл залив.
        look: {
            preset: 'night',
            grade: { sat: 1.02, warm: [0.97, 0.99, 1.05], lift: [0.001, 0.004, 0.01], splitHigh: [0.015, 0.005, 0.01], contrast: 1.02, vignette: 0.22 },
            fog: { density: 1, height: 0.3, sunScatter: 0 },
            cloudShadow: 0,
            clouds: { cover: 0.15, speed: 4 },
            backdrop: { type: 'skyline', colour: 0x1c2030, height: 70, distance: 1100 },
            terrain: { kind: 'sand', scale: 1.6, ridged: false, relief: 0.3 },
            treeScale: 1.2,
            facade: { colour: 0x6a7080, windows: true },
            hoardings: { brands: TRACKSIDE_MESSAGES },
            wind: { x: 0.9, z: 0.4, speed: 4 },
            haze: 0.4,
        },
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
 * Пълната схема на look с неутрални стойности: умерен ден, лека мъгла,
 * горист хоризонт. Грейдът е този на postfx.js GRADE_DEFAULTS — писта без
 * запис изглежда точно както преди таблицата.
 *
 * @type {CircuitLook}
 */
export const DEFAULT_LOOK = Object.freeze({
    preset: 'day',
    grade: { sat: 1.08, warm: [1.02, 1, 0.985], lift: [0, 0.0015, 0.004], splitHigh: [0, 0, 0], contrast: 1, vignette: 0.16 },
    fog: { density: 1, height: 0.45, sunScatter: 0.35, falloff: 0.0055, floor: 0, horizonMix: 0.7 },
    cloudShadow: 0.3,
    clouds: { cover: 0.45, speed: 10 },
    backdrop: { type: 'treeline', colour: 0x3a5238, height: 20, distance: 1000 },
    terrain: { kind: 'grass', scale: 1, ridged: false, relief: 1 },
    treeScale: 1,
    treeDensity: 1,
    facade: { colour: 0xcfc8b8, windows: false },
    hoardings: { brands: TRACKSIDE_MESSAGES },
    wind: { x: 0.7, z: 0.7, speed: 5 },
    haze: 0.3,
});

/**
 * Измереният азимут (конвенцията на sunAzimuth, градуси) на запеченото слънце
 * във всеки HDRI файл — най-ярката колона на equirect-а, сканирана веднъж
 * офлайн. Game.js върти environment/background така, че то да застане на
 * аналитичното слънце; авторската стойност спестява сканирането при зареждане.
 * При облачното небе „слънцето" е най-светлото петно зад облаците.
 */
const HDRI_SUN_AZIMUTH = {
    sky_2k: 54.1,
    sky_overcast_2k: 60.4,
};

/**
 * DEFAULT_LOOK + частичен запис: вложените обекти се сливат по ключ (едно
 * ниво), масивите и скаларите се заменят.
 *
 * @param {Partial<CircuitLook>} [override]
 * @returns {CircuitLook}
 */
function mergeLook(override = {}) {
    const merged = {};
    for (const key of Object.keys(DEFAULT_LOOK)) {
        const base = DEFAULT_LOOK[key];
        const value = override[key];
        if (base !== null && typeof base === 'object' && !Array.isArray(base)) {
            merged[key] = { ...base, ...(value && typeof value === 'object' ? value : {}) };
        } else {
            merged[key] = value ?? base;
        }
    }

    return merged;
}

/**
 * Довършва записа при зареждане: пълен look, съгласуваност нощ ↔ preset
 * (декорът чете atmosphere.night, атмосферата — look.preset), огледало на
 * treeDensity и азимутът на HDRI слънцето по файл.
 *
 * @param {CircuitStyle} circuit
 */
function finalizeCircuit(circuit) {
    circuit.look = mergeLook(circuit.look);
    if (circuit.atmosphere.night === true) {
        circuit.look.preset = 'night';
    }
    circuit.atmosphere.sunIntensity *= DIRECT_LIGHT_SCALE[circuit.look.preset] ?? DIRECT_LIGHT_SCALE.day;
    if (circuit.look.treeDensity === DEFAULT_LOOK.treeDensity && typeof circuit.treeDensity === 'number') {
        circuit.look.treeDensity = circuit.treeDensity;
    }
    circuit.atmosphere.hdriSunAzimuth ??= HDRI_SUN_AZIMUTH[circuit.atmosphere.hdri ?? 'sky_2k'];
}

for (const circuit of Object.values(CIRCUITS)) {
    finalizeCircuit(circuit);
}
finalizeCircuit(DEFAULT_STYLE);

/**
 * @param {string} slug
 * @returns {CircuitStyle}
 */
export function circuitFor(slug) {
    return CIRCUITS[slug] ?? DEFAULT_STYLE;
}

/**
 * Пълният look по slug или по вече взет запис (чужд обект без look получава
 * стойностите по подразбиране — тестови стилове, бъдещи писти).
 *
 * @param {string|CircuitStyle} circuitOrSlug
 * @returns {CircuitLook}
 */
export function lookFor(circuitOrSlug) {
    if (typeof circuitOrSlug === 'string') {
        return circuitFor(circuitOrSlug).look;
    }
    if (circuitOrSlug === DEFAULT_STYLE || Object.values(CIRCUITS).includes(circuitOrSlug)) {
        return circuitOrSlug.look;
    }

    return mergeLook(circuitOrSlug?.look);
}
