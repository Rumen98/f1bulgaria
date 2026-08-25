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
 * @property {object} atmosphere           Слънце/мъгла/експозиция
 * @property {object|null} landmark        Специален обект (виенско колело, пристанище…)
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
