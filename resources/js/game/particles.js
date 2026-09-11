/**
 * Частици: дим от гумите, lock-up дим отпред, титаниеви искри от планката,
 * прах/камъни/туфи по повърхност, спрей в дъжд, конфети и искри при удар.
 *
 * Три pool-а от InstancedMesh билбордове (PlaneGeometry × capacity) с общ
 * шейдър: „дим" (normal blending, осветен, депт-мек), „искри" (additive, HDR,
 * velocity-stretched) и „спрей" (лениво, само при мокро). Един draw call на
 * pool. Всички буфери са заделени веднъж; spawn-ът върти ring указател,
 * мъртвите частици са с размер 0 (дегенериран quad). Нула алокации на кадър.
 *
 * ЗАЩО билбордове вместо gl.POINTS: точката се реже ЦЯЛА щом центърът ѝ излезе
 * от кадъра (онборд камерата вижда как облакът изчезва на ръба), а размерът ѝ
 * е ограничен от драйвера (511 px на iOS/Metal, 64-256 на някои Android) —
 * пораснал облак „подскача". Quad-ът няма нито едно от двете, а размерът му е
 * в метри: проекцията го смалява сама, без емпирична константа, независимо от
 * FOV анимацията, DPR и стъпката на резолюционния governor.
 *
 * Мъглата на atmosphere.js важи (fog chunk-овете са глобални). При наличен
 * depth texture (десктоп, composerTarget.depthTexture) частиците гаснат меко
 * там, където пресичат пътя/дифузьора; без него (телефон) резултатът е твърд,
 * но идентичен като форма.
 *
 * Модулът е чисто презентационен: чете sim state, никога не го пипа.
 *
 * Публично:
 *   new ParticleEffects(scene, { lowPower, quality, circuit, sunDir, sunColor, sunIntensity })
 *   effects.createEmitter(rig)         → емитер за една кола (играч, съперник, реплей)
 *   emitter.emit(dt, state, surface, sim, input, out)
 *   effects.update(dt, camera)         → остаряване + bounding sphere, веднъж на кадър
 *   effects.setDepth(textureOrGetter)  → меки частици (null = твърди)
 *   effects.setSun / setAmbient / setNight / setWet / setWind / setDensity
 *   effects.burst / impactSparks / confetti
 *   createTyreSignals / updateTyreSignals → общите евристики (и за skidmarks.js)
 *
 * Реплеят: replayDriver.js подава ReplayOut (x, z, heading, vForward, yawRate,
 * slip, height, gradient, bank, offSurface, onKerb, brake, throttle) — един и
 * същ обект върши работа за state, surface, sim и input на emit().
 */

import * as THREE from 'three';
import { getNoiseTexture } from './noiseTex.js';

// ─── Sprite sheet (4×4 клетки, 512², процедурно) ─────────────────────────────

const SHEET_SIZE = 512;
const CELLS = 4;
const CELL_PX = SHEET_SIZE / CELLS;

/** Индекси на клетките в листа. Пуфовете и прахът са по 4 варианта. */
const CELL = Object.freeze({
    puff: 0, // 0..3 меки FBM пуфове (дим)
    stone: 4, // 4..5 твърди камъни
    clump: 6, // 6..7 туфи трева
    streak: 8, // искра: ярка глава, гаснеща опашка по +u
    droplet: 9, // капка спрей
    confetti: 10, // конфета (твърд правоъгълник)
    disc: 11, // мек диск (пясъчна диря, lock-up мараня)
    dust: 12, // 12..15 по-груби пуфове (прах)
});

/**
 * Билинеен прочит на канал от споделения шум (CPU страна, за генериране на
 * листа и на alphaMap-а на следите). Координатите са в texel-и, wrap по size.
 *
 * @param {Uint8Array} data RGBA texel-и (getNoiseTexture().image.data)
 * @param {number} size Страна на текстурата (степен на двойката)
 * @param {number} x @param {number} y
 * @param {number} channel 0..3
 * @returns {number} 0..1
 */
export function sampleNoiseData(data, size, x, y, channel) {
    const x0 = Math.floor(x);
    const y0 = Math.floor(y);
    const fx = x - x0;
    const fy = y - y0;
    const at = (ix, iy) => data[(((iy & (size - 1)) * size + (ix & (size - 1))) << 2) + channel] / 255;
    const a = at(x0, y0);
    const b = at(x0 + 1, y0);
    const c = at(x0, y0 + 1);
    const d = at(x0 + 1, y0 + 1);
    return (a + (b - a) * fx) * (1 - fy) + (c + (d - c) * fx) * fy;
}

function smoothstep(e0, e1, x) {
    const t = Math.min(1, Math.max(0, (x - e0) / (e1 - e0)));
    return t * t * (3 - 2 * t);
}

/**
 * Листът: alpha = плътност (за ерозията по възраст), rgb = вътрешно засенчване.
 * Чисто аритметичен (без canvas) — работи и в node за selftest-ове.
 *
 * @returns {THREE.DataTexture}
 */
function buildSpriteSheet() {
    const noise = getNoiseTexture(256);
    const nd = noise.image.data;
    const ns = noise.image.width;
    const data = new Uint8Array(SHEET_SIZE * SHEET_SIZE * 4);

    const fbm = (x, y, channel, octaves) => {
        let sum = 0;
        let amp = 0.5;
        let freq = 1;
        let norm = 0;
        for (let o = 0; o < octaves; o++) {
            sum += sampleNoiseData(nd, ns, x * freq, y * freq, channel) * amp;
            norm += amp;
            amp *= 0.5;
            freq *= 2;
        }
        return sum / norm;
    };

    for (let cell = 0; cell < CELLS * CELLS; cell++) {
        const cx = (cell % CELLS) * CELL_PX;
        const cy = Math.floor(cell / CELLS) * CELL_PX;
        const variant = cell & 3;
        for (let py = 0; py < CELL_PX; py++) {
            for (let px = 0; px < CELL_PX; px++) {
                // u, v ∈ [0,1) в клетката; ux, uy ∈ [-0.5, 0.5).
                const u = (px + 0.5) / CELL_PX;
                const v = (py + 0.5) / CELL_PX;
                const ux = u - 0.5;
                const uy = v - 0.5;
                const r = Math.hypot(ux, uy);
                let alpha = 0;
                let shade = 1;

                if (cell < 4 || cell >= CELL.dust) {
                    // Пуф: радиален спад × FBM. Прахът е по-груб (по-малко октави,
                    // по-силен контраст) — на ТВ прахта е на парцали, димът е коприна.
                    // Шумът е с праг: под него пуфът е на дупки (парцаливи
                    // ръбове, не мек диск — дискът чете като сапунен мехур).
                    const coarse = cell >= CELL.dust;
                    const n = fbm(cx * 0.5 + px * (coarse ? 1.6 : 1.0), cy * 0.5 + py * (coarse ? 1.6 : 1.0), variant, coarse ? 2 : 3);
                    const radial = smoothstep(0.5, coarse ? 0.2 : 0.1, r);
                    alpha = Math.min(1, radial * (coarse ? 1.7 * n - 0.4 : 1.25 * n - 0.12));
                    shade = 0.75 + 0.5 * (n - 0.5) + 0.12 * (0.5 - r);
                } else if (cell === CELL.stone || cell === CELL.stone + 1) {
                    // Камък: неправилен твърд контур, осветен отгоре.
                    const ang = Math.atan2(uy, ux);
                    const wobble = sampleNoiseData(nd, ns, 40 + Math.cos(ang) * 9 + variant * 50, 40 + Math.sin(ang) * 9, 1);
                    const radius = 0.24 + 0.1 * (wobble - 0.5) * 2;
                    alpha = smoothstep(radius + 0.012, radius - 0.012, r);
                    shade = 0.45 + 0.55 * smoothstep(0.4, -0.3, uy) + 0.15 * (wobble - 0.5);
                } else if (cell === CELL.clump || cell === CELL.clump + 1) {
                    // Туфа: 5-6 стръка от долния център, ветрило нагоре.
                    const blades = 5 + (variant & 1);
                    let inside = 0;
                    for (let b = 0; b < blades; b++) {
                        const dir = -0.35 + (0.7 * b) / (blades - 1) + 0.12 * (sampleNoiseData(nd, ns, b * 17 + variant * 7, 3, 2) - 0.5);
                        const bx = ux - dir * (uy + 0.45) * 1.1;
                        const len = 0.55 + 0.3 * sampleNoiseData(nd, ns, b * 13, variant * 5, 3);
                        const along = (uy + 0.45) / len;
                        if (along < 0 || along > 1) continue;
                        const width = 0.035 * (1 - along * 0.8);
                        inside = Math.max(inside, smoothstep(width + 0.008, width - 0.008, Math.abs(bx)));
                    }
                    alpha = inside;
                    shade = 0.7 + 0.5 * (uy + 0.45);
                } else if (cell === CELL.streak) {
                    // Искра: главата е при +u, опашката гасне към -u.
                    const across = Math.max(0, 1 - Math.abs(uy) * 2.2);
                    const head = 1 - smoothstep(0.86, 1.0, u);
                    alpha = Math.pow(u, 1.6) * head * across * across;
                    shade = 0.55 + 0.45 * u;
                } else if (cell === CELL.droplet) {
                    alpha = smoothstep(0.34, 0.08, r) * 0.9;
                    shade = 0.9 + 0.2 * (0.34 - r);
                } else if (cell === CELL.confetti) {
                    alpha = Math.abs(ux) < 0.4 && Math.abs(uy) < 0.22 ? 1 : 0;
                    shade = 0.85 + 0.3 * (uy + 0.22);
                } else if (cell === CELL.disc) {
                    const n = fbm(cx + px * 0.7, cy + py * 0.7, 0, 2);
                    alpha = smoothstep(0.5, 0.05, r) * (0.55 + 0.45 * n);
                    shade = 0.9 + 0.2 * (n - 0.5);
                }

                const i = ((cy + py) * SHEET_SIZE + cx + px) << 2;
                const s = Math.round(Math.min(1, Math.max(0, shade)) * 255);
                data[i] = s;
                data[i + 1] = s;
                data[i + 2] = s;
                data[i + 3] = Math.round(Math.min(1, Math.max(0, alpha)) * 255);
            }
        }
    }

    const texture = new THREE.DataTexture(data, SHEET_SIZE, SHEET_SIZE, THREE.RGBAFormat, THREE.UnsignedByteType);
    texture.colorSpace = THREE.NoColorSpace;
    texture.minFilter = THREE.LinearMipmapLinearFilter;
    texture.magFilter = THREE.LinearFilter;
    texture.generateMipmaps = true;
    texture.wrapS = THREE.ClampToEdgeWrapping;
    texture.wrapT = THREE.ClampToEdgeWrapping;
    texture.needsUpdate = true;
    return texture;
}

// ─── Шейдъри ─────────────────────────────────────────────────────────────────

const VERTEX = /* glsl */ `
    attribute vec3 aPos;
    attribute vec3 aVel;
    attribute vec3 aColor;
    // x: размер (м), y: alpha, z: ротация (rad), w: възраст 0..1
    attribute vec4 aParam;
    // x: клетка от листа, y: вид (0 билборд, 1 опънат по скоростта, 2 пърхащ)
    attribute vec2 aSprite;

    // projectionMatrix[5] · bufferHeight / 2 — пиксели на метър при z = 1.
    uniform float uPixelScale;
    uniform float uMinPixels;

    varying vec2 vUv;
    varying vec2 vLocal;
    varying vec3 vColor;
    varying float vAlpha;
    varying float vAge;
    varying float vViewZ;
    #include <fog_pars_vertex>

    void main() {
        vec4 mvPosition = modelViewMatrix * vec4(aPos, 1.0);
        float size = aParam.x;
        float alpha = aParam.y;
        float rot = aParam.z;
        float age = aParam.w;
        float kind = aSprite.y;
        vec2 corner = position.xy;

        // Под ~1.5 px билбордът трепти/изчезва при MSAA — държим минимален
        // екранен размер и компенсираме с alpha (същата енергия на пиксел).
        float dist = max(0.05, -mvPosition.z);
        float minSize = uMinPixels * dist / uPixelScale;
        float drawSize = max(size, minSize);
        alpha *= size / max(drawSize, 1e-5);

        vec2 offset;
        if (kind > 0.5 && kind < 1.5) {
            // Опънат по скоростта в екранната равнина: искри и капки са
            // комети, не топчета. Дължина от view-space скоростта.
            vec3 vView = mat3(viewMatrix) * aVel;
            float planar = length(vView.xy);
            vec2 dir = planar > 1e-4 ? vView.xy / planar : vec2(1.0, 0.0);
            float len = clamp(length(vView) * 0.02, drawSize, 0.6);
            vec2 perp = vec2(-dir.y, dir.x);
            offset = dir * (corner.x * len) + perp * (corner.y * drawSize);
        } else {
            // Конфетите пърхат: видимата ширина осцилира с възрастта.
            float flutter = kind > 1.5 ? mix(0.25, 1.0, abs(cos(age * 11.0 + rot))) : 1.0;
            float c = cos(rot);
            float s = sin(rot);
            vec2 local = vec2(corner.x * flutter, corner.y) * drawSize;
            offset = vec2(c * local.x - s * local.y, s * local.x + c * local.y);
        }
        mvPosition.xyz += vec3(offset, 0.0);

        float cell = aSprite.x;
        vec2 cellUv = vec2(mod(cell, 4.0), floor(cell / 4.0));
        vUv = (corner + 0.5 + cellUv) * 0.25;
        vLocal = corner;
        vColor = aColor;
        vAlpha = alpha;
        vAge = age;
        vViewZ = mvPosition.z;
        gl_Position = projectionMatrix * mvPosition;
        #include <fog_vertex>
    }
`;

const FRAGMENT = /* glsl */ `
    uniform sampler2D uSheet;
    uniform vec3 uSunView;
    uniform vec3 uSunColor;
    uniform vec3 uAmbient;
    uniform float uErode;
    #ifdef SOFT
        uniform sampler2D uDepth;
        uniform vec2 uResolution;
        uniform float uNear;
        uniform float uFar;
        uniform float uSoftRange;
        #include <packing>
    #endif

    varying vec2 vUv;
    varying vec2 vLocal;
    varying vec3 vColor;
    varying float vAlpha;
    varying float vAge;
    varying float vViewZ;
    #include <fog_pars_fragment>

    void main() {
        vec4 tex = texture2D(uSheet, vUv);
        // Ерозия: с възрастта първо изчезват редките части на пуфа, накрая
        // ядрото — облакът се разкъсва, не избледнява като плака. Подът 0.1
        // държи ръба парцалив и на прясно роден пуф.
        float erode = 0.1 + vAge * uErode;
        float alpha = smoothstep(erode, erode + 0.25, tex.a) * vAlpha;

        #ifdef SOFT
            // Депт-меки частици: гаснат в 0.6 m преди повърхността зад тях,
            // вместо да се режат по права линия от пътя/дифузьора.
            vec2 suv = gl_FragCoord.xy / uResolution;
            float sceneZ = perspectiveDepthToViewZ(texture2D(uDepth, suv).r, uNear, uFar);
            alpha *= smoothstep(0.0, uSoftRange, vViewZ - sceneZ);
        #endif

        if (alpha < 0.003) discard;

        #ifdef UNLIT
            vec3 rgb = vColor * tex.rgb;
        #else
            // Фалшива нормала от билборда: слънчевата страна на пуфа е светла,
            // сенчестата тъмна. Плоска (z = 1.1) и с мек диапазон — сферична
            // нормала прави пуфа на лъскава топка, не на облак.
            vec3 n = normalize(vec3(vLocal * 0.8, 1.1));
            float ndl = dot(n, uSunView) * 0.5 + 0.5;
            vec3 rgb = vColor * tex.rgb * (uAmbient + uSunColor * mix(0.5, 1.0, ndl));
        #endif
        gl_FragColor = vec4(rgb, alpha);

        #ifdef ADDITIVE
            vec3 unfogged = gl_FragColor.rgb;
        #endif
        #include <fog_fragment>
        #if defined(ADDITIVE) && defined(USE_FOG)
            // Адитивен материал не бива да СВЕТИ с цвета на мъглата — гасне
            // към нула. fogFactor е локалната от chunk-а (и в override-а на
            // atmosphere.js, и в оригинала на three).
            gl_FragColor.rgb = unfogged * (1.0 - fogFactor);
        #endif
        #include <tonemapping_fragment>
        #include <colorspace_fragment>
    }
`;

// ─── Пресети на частиците ────────────────────────────────────────────────────

/**
 * @typedef {object} ParticlePreset
 * @property {number} lifeMin
 * @property {number} lifeMax
 * @property {number} growth Растеж на размера, m/s
 * @property {number} gravity m/s²
 * @property {number} drag 1/s — спад на скоростта към вятъра (или нулата)
 * @property {number} bounce Коефициент на отскок от земята (0 = без)
 * @property {number} cell Първа клетка от листа
 * @property {number} cellCount Брой варианти след нея
 * @property {number} kind 0 билборд, 1 опънат, 2 пърхащ
 * @property {number} spinMax Максимална скорост на въртене, rad/s
 * @property {number} fade 0 линейно гаснене, 1 задържане и бързо гаснене
 * @property {number} wind Дял на вятъра в дрейфа (0..1)
 * @property {number} erode Сила на ерозията (0..1)
 */

/** @type {Record<string, ParticlePreset>} */
const PRESET = Object.freeze({
    smoke: { lifeMin: 0.9, lifeMax: 1.3, growth: 2.2, gravity: -0.4, drag: 2.5, bounce: 0, cell: CELL.puff, cellCount: 4, kind: 0, spinMax: 0.8, fade: 0, wind: 1, erode: 0.8 },
    lockSmoke: { lifeMin: 0.9, lifeMax: 1.2, growth: 3.0, gravity: -0.5, drag: 2.5, bounce: 0, cell: CELL.puff, cellCount: 4, kind: 0, spinMax: 1.0, fade: 0, wind: 1, erode: 0.8 },
    scrub: { lifeMin: 0.5, lifeMax: 0.8, growth: 1.6, gravity: -0.3, drag: 3.0, bounce: 0, cell: CELL.puff, cellCount: 4, kind: 0, spinMax: 0.6, fade: 0, wind: 1, erode: 0.8 },
    dust: { lifeMin: 1.3, lifeMax: 1.9, growth: 2.5, gravity: -0.15, drag: 1.8, bounce: 0, cell: CELL.dust, cellCount: 4, kind: 0, spinMax: 0.5, fade: 0, wind: 1, erode: 0.7 },
    wake: { lifeMin: 1.0, lifeMax: 1.4, growth: 1.5, gravity: -0.1, drag: 2.0, bounce: 0, cell: CELL.disc, cellCount: 1, kind: 0, spinMax: 0.3, fade: 0, wind: 1, erode: 0.5 },
    stone: { lifeMin: 0.5, lifeMax: 0.8, growth: 0, gravity: 14, drag: 0.3, bounce: 0.35, cell: CELL.stone, cellCount: 2, kind: 2, spinMax: 12, fade: 1, wind: 0, erode: 0.2 },
    clump: { lifeMin: 0.6, lifeMax: 0.9, growth: 0, gravity: 9, drag: 1.2, bounce: 0.15, cell: CELL.clump, cellCount: 2, kind: 2, spinMax: 6, fade: 1, wind: 0, erode: 0.2 },
    spark: { lifeMin: 0.25, lifeMax: 0.45, growth: 0, gravity: 22, drag: 0.6, bounce: 0.4, cell: CELL.streak, cellCount: 1, kind: 1, spinMax: 0, fade: 0, wind: 0, erode: 0.3 },
    spray: { lifeMin: 0.45, lifeMax: 0.65, growth: 1.5, gravity: 3, drag: 3.0, bounce: 0, cell: CELL.droplet, cellCount: 1, kind: 1, spinMax: 0, fade: 0, wind: 0.3, erode: 0.5 },
    confetti: { lifeMin: 3.5, lifeMax: 5.5, growth: 0, gravity: 1.6, drag: 1.4, bounce: 0, cell: CELL.confetti, cellCount: 1, kind: 2, spinMax: 7, fade: 1, wind: 1, erode: 0.1 },
});

const CONFETTI_COLORS = [
    [0.9, 0.08, 0.1],
    [0.95, 0.95, 0.95],
    [0.95, 0.72, 0.1],
    [0.1, 0.45, 0.9],
    [0.15, 0.7, 0.3],
];

// ─── Pool ────────────────────────────────────────────────────────────────────

const _v2 = new THREE.Vector2();
const _sunView = new THREE.Vector3();

class ParticlePool {
    /**
     * @param {number} capacity
     * @param {THREE.Texture} sheet
     * @param {{blending: THREE.Blending, unlit: boolean, additive: boolean, uploadsVelocity: boolean}} options
     */
    constructor(capacity, sheet, options) {
        this.capacity = capacity;
        this.head = 0;
        this.alive = 0;
        this.uploadsVelocity = options.uploadsVelocity;

        // GPU атрибути (InstancedBufferAttribute).
        this.positions = new Float32Array(capacity * 3);
        this.velocities = new Float32Array(capacity * 3);
        this.colors = new Float32Array(capacity * 3);
        this.params = new Float32Array(capacity * 4);
        this.sprites = new Float32Array(capacity * 2);

        // Симулационно състояние (не отива към GPU).
        this.life = new Float32Array(capacity);
        this.maxLife = new Float32Array(capacity);
        this.growth = new Float32Array(capacity);
        this.gravity = new Float32Array(capacity);
        this.drag = new Float32Array(capacity);
        this.bounce = new Float32Array(capacity);
        this.baseAlpha = new Float32Array(capacity);
        this.ground = new Float32Array(capacity);
        this.spin = new Float32Array(capacity);
        this.fade = new Uint8Array(capacity);
        this.windWeight = new Float32Array(capacity);

        const geometry = new THREE.InstancedBufferGeometry().copy(new THREE.PlaneGeometry(1, 1));
        geometry.instanceCount = capacity;
        const attr = (array, itemSize) =>
            new THREE.InstancedBufferAttribute(array, itemSize).setUsage(THREE.DynamicDrawUsage);
        geometry.setAttribute('aPos', attr(this.positions, 3));
        geometry.setAttribute('aVel', attr(this.velocities, 3));
        geometry.setAttribute('aColor', attr(this.colors, 3));
        geometry.setAttribute('aParam', attr(this.params, 4));
        geometry.setAttribute('aSprite', attr(this.sprites, 2));

        // Мъглата на three: fogColor/fogNear/fogFar + допълнителните uniforms на
        // atmosphere.js (голи обекти, копирани по референция от clone()).
        // Нашите uniforms се добавят ПО РЕФЕРЕНЦИЯ — UniformsUtils.clone би
        // клонирал и depth текстурата.
        const uniforms = Object.assign(THREE.UniformsUtils.clone(THREE.UniformsLib.fog), {
            uSheet: { value: sheet },
            uSunView: { value: new THREE.Vector3(0, 1, 0) },
            uSunColor: { value: new THREE.Color(0.9, 0.85, 0.75) },
            uAmbient: { value: new THREE.Color(0.3, 0.32, 0.36) },
            uErode: { value: 0.8 },
            uPixelScale: { value: 500 },
            uMinPixels: { value: 1.5 },
            uDepth: { value: null },
            uResolution: { value: new THREE.Vector2(1, 1) },
            uNear: { value: 0.5 },
            uFar: { value: 2200 },
            uSoftRange: { value: 0.6 },
        });

        const defines = {};
        if (options.unlit) defines.UNLIT = '';
        if (options.additive) defines.ADDITIVE = '';

        const material = new THREE.ShaderMaterial({
            vertexShader: VERTEX,
            fragmentShader: FRAGMENT,
            uniforms,
            defines,
            transparent: true,
            depthWrite: false,
            depthTest: true,
            blending: options.blending,
            side: THREE.DoubleSide,
            fog: true,
        });

        this.mesh = new THREE.InstancedMesh(geometry, material, capacity);
        this.mesh.count = capacity;
        // Обектната сфера (InstancedMesh я ползва пред геометрията) се сеща
        // всеки кадър от живите частици — камерата на реплея реже облака,
        // когато не го вижда.
        this.mesh.boundingSphere = new THREE.Sphere(new THREE.Vector3(), 1);
        this.mesh.frustumCulled = true;
        this.mesh.renderOrder = 3;
        this.mesh.visible = false;
        this.mesh.matrixAutoUpdate = false;

        this.uniforms = uniforms;
        this.spawned = false;
        this.wind = { x: 0, z: 0 };
    }

    /**
     * @param {number} x @param {number} y @param {number} z
     * @param {number} vx @param {number} vy @param {number} vz
     * @param {ParticlePreset} preset
     * @param {number} size
     * @param {number} alpha
     * @param {number} r @param {number} g @param {number} b
     * @param {number} rot
     * @param {number} ground Височина на земята под частицата (за отскок)
     */
    spawn(x, y, z, vx, vy, vz, preset, size, alpha, r, g, b, rot, ground) {
        const i = this.head;
        this.head = (this.head + 1) % this.capacity;

        this.positions[i * 3] = x;
        this.positions[i * 3 + 1] = y;
        this.positions[i * 3 + 2] = z;
        this.velocities[i * 3] = vx;
        this.velocities[i * 3 + 1] = vy;
        this.velocities[i * 3 + 2] = vz;
        this.colors[i * 3] = r;
        this.colors[i * 3 + 1] = g;
        this.colors[i * 3 + 2] = b;

        const life = preset.lifeMin + Math.random() * (preset.lifeMax - preset.lifeMin);
        this.life[i] = life;
        this.maxLife[i] = life;
        this.growth[i] = preset.growth;
        this.gravity[i] = preset.gravity;
        this.drag[i] = preset.drag;
        this.bounce[i] = preset.bounce;
        this.baseAlpha[i] = alpha;
        this.ground[i] = ground;
        this.spin[i] = (Math.random() * 2 - 1) * preset.spinMax;
        this.fade[i] = preset.fade;
        this.windWeight[i] = preset.wind;

        this.params[i * 4] = size;
        this.params[i * 4 + 1] = 0; // първият update я вдига (fade-in)
        this.params[i * 4 + 2] = rot;
        this.params[i * 4 + 3] = 0;
        this.sprites[i * 2] = preset.cell + Math.floor(Math.random() * preset.cellCount);
        this.sprites[i * 2 + 1] = preset.kind;
        this.spawned = true;
    }

    /** @param {number} dt */
    update(dt) {
        const n = this.capacity;
        const pos = this.positions;
        const vel = this.velocities;
        const params = this.params;
        const wx = this.wind.x;
        const wz = this.wind.z;
        let alive = 0;
        let minX = Infinity;
        let minY = Infinity;
        let minZ = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;
        let maxZ = -Infinity;

        for (let i = 0; i < n; i++) {
            if (this.life[i] <= 0) {
                continue;
            }

            this.life[i] -= dt;
            if (this.life[i] <= 0) {
                params[i * 4] = 0;
                params[i * 4 + 1] = 0;
                continue;
            }
            alive++;

            const i3 = i * 3;
            const i4 = i * 4;
            const t = this.life[i] / this.maxLife[i];
            const age = 1 - t;

            // Съпротивление към вятъра (дим) или към нулата (искри/камъни).
            const k = Math.min(1, this.drag[i] * dt);
            const ww = this.windWeight[i];
            vel[i3] += (wx * ww - vel[i3]) * k;
            vel[i3 + 1] -= vel[i3 + 1] * k * 0.5 + this.gravity[i] * dt;
            vel[i3 + 2] += (wz * ww - vel[i3 + 2]) * k;
            // Лек curl: пуфовете не се движат по конец.
            if (ww > 0) {
                const phase = age * 4 + i * 0.37;
                vel[i3] += Math.sin(phase) * 0.5 * dt;
                vel[i3 + 2] += Math.cos(phase * 1.3) * 0.5 * dt;
            }

            pos[i3] += vel[i3] * dt;
            pos[i3 + 1] += vel[i3 + 1] * dt;
            pos[i3 + 2] += vel[i3 + 2] * dt;

            // Отскок от асфалта: искрите рикошират и блещукат, вместо да
            // догарят под пътя (където depthTest ги реже — празен fill).
            const bounce = this.bounce[i];
            if (bounce > 0 && pos[i3 + 1] < this.ground[i]) {
                pos[i3 + 1] = this.ground[i];
                vel[i3 + 1] = -vel[i3 + 1] * bounce;
                vel[i3] *= 0.7;
                vel[i3 + 2] *= 0.7;
                if (vel[i3 + 1] < 0.25) {
                    vel[i3 + 1] = 0;
                    this.bounce[i] = 0;
                }
            }

            const fadeIn = Math.min(1, age * 10);
            const fadeOut = this.fade[i] === 1 ? Math.min(1, t * 5) : t;
            params[i4] += this.growth[i] * dt;
            params[i4 + 1] = this.baseAlpha[i] * fadeIn * fadeOut;
            params[i4 + 2] += this.spin[i] * dt;
            params[i4 + 3] = age;

            const x = pos[i3];
            const y = pos[i3 + 1];
            const z = pos[i3 + 2];
            if (x < minX) minX = x;
            if (x > maxX) maxX = x;
            if (y < minY) minY = y;
            if (y > maxY) maxY = y;
            if (z < minZ) minZ = z;
            if (z > maxZ) maxZ = z;
        }

        // Празен pool = нула GPU трафик и нула draw call-ове. Умиращите частици
        // пак стигат до GPU: в кадъра на смъртта alive още брои останалите или
        // spawned е вдигнат от същия кадър.
        const previouslyVisible = this.mesh.visible;
        this.alive = alive;
        this.mesh.visible = alive > 0;
        if (alive === 0 && !this.spawned && !previouslyVisible) {
            return;
        }

        if (alive > 0) {
            const sphere = this.mesh.boundingSphere;
            sphere.center.set((minX + maxX) * 0.5, (minY + maxY) * 0.5, (minZ + maxZ) * 0.5);
            // Плюс най-големия възможен билборд (пораснал пуф ≈ 4 m).
            sphere.radius = Math.hypot(maxX - minX, maxY - minY, maxZ - minZ) * 0.5 + 4;
        }

        const attributes = this.mesh.geometry.attributes;
        attributes.aPos.needsUpdate = true;
        attributes.aParam.needsUpdate = true;
        if (this.uploadsVelocity) {
            attributes.aVel.needsUpdate = true;
        }
        // Цвят и спрайт се пишат само в spawn() — качват се само при нови.
        if (this.spawned) {
            attributes.aColor.needsUpdate = true;
            attributes.aSprite.needsUpdate = true;
            if (!this.uploadsVelocity) {
                attributes.aVel.needsUpdate = true;
            }
            this.spawned = false;
        }
    }

    /**
     * Смяна на SOFT режима (депт текстура). Рядко: при setDepth, не по кадър.
     *
     * @param {THREE.Texture|null} texture
     */
    setDepthTexture(texture) {
        const material = this.mesh.material;
        if (this.uniforms.uDepth.value === texture) {
            return;
        }
        this.uniforms.uDepth.value = texture;
        const soft = texture !== null;
        if (soft !== ('SOFT' in material.defines)) {
            if (soft) {
                material.defines.SOFT = '';
            } else {
                delete material.defines.SOFT;
            }
            material.needsUpdate = true;
        }
    }

    dispose() {
        this.mesh.geometry.dispose();
        this.mesh.material.dispose();
        this.mesh.dispose();
    }
}

// ─── Общи евристики за гумите (споделени със skidmarks.js) ───────────────────

/**
 * @typedef {object} TyreSignals
 * @property {number} speed |vForward|
 * @property {number} slip state.slip
 * @property {number} brake 0..1
 * @property {number} throttle 0..1
 * @property {number} gLong Изгладено надлъжно ускорение, m/s² (отрицателно при спиране)
 * @property {number} rear 0..1 — плъзгане/буксуване на задните гуми
 * @property {number} frontLock 0..1 — заключени предни
 * @property {number} understeer 0..1 — предните се плъзгат, задните държат
 * @property {number} inside −1|1 — локалният x на вътрешното колело в завоя
 * @property {boolean} hasOut Дали телеметрията на sim-v3 е налична
 */

/** @returns {TyreSignals & {_prevV: number}} */
export function createTyreSignals() {
    return {
        speed: 0,
        slip: 0,
        brake: 0,
        throttle: 0,
        gLong: 0,
        rear: 0,
        frontLock: 0,
        understeer: 0,
        inside: 1,
        hasOut: false,
        _prevV: 0,
    };
}

/**
 * Изчислява сигналите от sim-v3 телеметрията (state.out), а без нея — от
 * slip/input евристики. Праговете (brake>0.85, v>35, gLong<−30 за lock-up)
 * са същите, които sound.js ползва за lockLevel — визия и звук се
 * задействат заедно.
 *
 * @param {TyreSignals} sig
 * @param {number} dt
 * @param {{vForward: number, yawRate?: number, slip?: number}} state
 * @param {{brake?: number, throttle?: number}|null} input
 * @param {object|undefined} out sim.state.out
 * @returns {TyreSignals}
 */
export function updateTyreSignals(sig, dt, state, input, out) {
    const v = state.vForward ?? 0;
    const speed = Math.abs(v);
    sig.speed = speed;
    sig.slip = state.slip ?? 0;
    sig.brake = input?.brake ?? 0;
    sig.throttle = input?.throttle ?? 0;

    const rawLong = dt > 0 ? Math.max(-50, Math.min(50, (v - sig._prevV) / dt)) : 0;
    sig._prevV = v;
    sig.gLong += (rawLong - sig.gLong) * (1 - Math.exp(-6 * dt));

    const slipTerm = Math.min(1, Math.max(0, (sig.slip - 0.25) / 0.5));
    sig.hasOut = out !== undefined && out !== null;
    if (sig.hasOut) {
        const rhoR = out.rhoR ?? 0;
        const rhoF = out.rhoF ?? 0;
        sig.rear = Math.max(slipTerm, Math.min(1, Math.max(0, (rhoR - 0.85) / 0.15)), out.spin ?? 0, out.lockR ?? 0);
        sig.frontLock = out.lockF ?? 0;
        sig.understeer = rhoF > 0.97 && rhoR < 0.8 ? Math.min(1, (rhoF - 0.97) / 0.03) : 0;
    } else {
        sig.rear = slipTerm;
        sig.frontLock = sig.brake > 0.85 && speed > 35 && sig.gLong < -30 ? 1 : 0;
        sig.understeer = 0;
    }

    // Вътрешното колело: обратно на страничното ускорение (виж updateCarRig —
    // кренът е навън, т.е. положителен yawRate·v ляга на +x → вътре е −x).
    const lateralAccel = (state.yawRate ?? 0) * v;
    if (Math.abs(lateralAccel) > 2) {
        sig.inside = lateralAccel > 0 ? -1 : 1;
    }
    return sig;
}

// ─── Емитер за една кола ─────────────────────────────────────────────────────

/** Оси на GLB болида (car-model пакетът ги излага като rig.axles; skidmarks.js ползва същия резерв). */
export const DEFAULT_AXLES = Object.freeze({
    front: { z: 1.21, x: 0.7, radius: 0.34 },
    rear: { z: -1.83, x: 0.7, radius: 0.36 },
});

/** Съперници по-далеч от това от камерата не емитират (нищо не се вижда). */
const CULL_DISTANCE = 120;

/**
 * Цветове на праха по повърхност (линейни). По-тъмни от настилката: прахът е
 * сянката ѝ, осветен отгоре от шейдъра — със самия цвят на чакъла (0.73)
 * облакът излиза избелял.
 */
const DUST = Object.freeze({
    gravel: [0.6, 0.54, 0.38],
    grass: [0.4, 0.36, 0.25],
    sand: [0.78, 0.68, 0.48],
    asphalt: [0.45, 0.45, 0.45],
});

class CarEmitter {
    /**
     * @param {ParticleEffects} effects
     * @param {{axles?: object}|null} rig
     */
    constructor(effects, rig) {
        this.effects = effects;
        // Rig-ът, не rig.axles: car.js ПОДМЕНЯ обекта axles, когато GLB-то
        // пристигне (процедурни 1.55 → GLB 1.21/−1.83) — четем го при всеки emit.
        this.rig = rig;
        /** Сигналите от последния emit() — skidmarks.js ги чете, не ги смята втори път. */
        this.sig = createTyreSignals();
        /** Ниво на lock-up-а отпред 0..1 за този кадър (телеметрия или евристика с таймер). */
        this.lock = 0;

        // Спаун акумулатори — темпото не зависи от кадровата честота.
        this.accum = { smoke: 0, lock: 0, scrub: 0, spark: 0, dust: 0, debris: 0, spray: 0, wake: 0, scrape: 0 };
        this.lockTimer = 0;
        this.sparkBurst = 0;
        this.wasBrakingHard = false;
        this.prevGradient = 0;
        this.hasGradient = false;
        this.vertCurv = 0;
        this.sideToggle = 1;
    }

    /**
     * Ефектите на една кола за този кадър. Чете, не пише.
     *
     * @param {number} dt
     * @param {{x: number, z: number, heading: number, vForward: number, yawRate?: number, slip?: number, out?: object}} state
     *        Интерполираното състояние (render) на колата
     * @param {{height: number, gradient?: number, bank?: number}} surface sim.surface
     * @param {{offSurface?: string|null, onKerb?: boolean}|null} sim Симулацията (или shim)
     * @param {{brake?: number, throttle?: number, steer?: number}|null} [input]
     * @param {object|undefined} [out] sim.state.out (по подразбиране state.out)
     */
    emit(dt, state, surface, sim, input = null, out = state.out) {
        const fx = this.effects;
        const sig = updateTyreSignals(this.sig, dt, state, input, out);
        const speed = sig.speed;
        const gradient = surface.gradient ?? 0;
        const bank = surface.bank ?? 0;

        // Вертикална кривина на пътя (1/m): производна на наклона по изминатия
        // път, изгладена — гърбиците и компресиите изкарват искри от планката.
        const ds = speed * dt;
        if (this.hasGradient && ds > 0.05) {
            const raw = (gradient - this.prevGradient) / ds;
            this.vertCurv += (raw - this.vertCurv) * (1 - Math.exp(-4 * dt));
        }
        this.prevGradient = gradient;
        this.hasGradient = true;
        this.lock = 0;

        if (dt <= 0) {
            return;
        }

        // Далечни съперници: нищо не се вижда — спестяваме fill и pool място.
        let lod = 1;
        if (fx.hasCamera) {
            const d2 = (state.x - fx.camPos.x) ** 2 + (state.z - fx.camPos.z) ** 2;
            if (d2 > CULL_DISTANCE * CULL_DISTANCE) {
                return;
            }
            lod = d2 < 1600 ? 1 : 1 - 0.65 * Math.min(1, (Math.sqrt(d2) - 40) / (CULL_DISTANCE - 40));
        }
        const density = fx.density * lod;

        const sin = Math.sin(state.heading);
        const cos = Math.cos(state.heading);
        const fwdX = sin;
        const fwdZ = cos;
        const latX = cos;
        const latZ = -sin;
        const cx = state.x;
        const cz = state.z;
        const height = surface.height;
        const axles = this.rig?.axles ?? DEFAULT_AXLES;
        const offSurface = sim?.offSurface ?? null;
        const onKerb = sim?.onKerb === true;
        const night = fx.night;
        const smoke = fx.smoke;
        const sparks = fx.sparks;

        // Локални → световни; y по наклона на оста и банкинга. surface.height
        // е под ЦЕНТЪРА на колата: на Eau Rouge (18 %) асфалтът под задната ос
        // е 0.33 m по-ниско, а банкингът на Зандвоорт мести колелото с 0.2 m.
        // Знакът на банкинга: нормалата на трасето е (−dz, dx) = локалното −x,
        // а лентата слиза по нормалата (y −= lateral·bank) → +x се качва.
        const wx = (lx, lz) => cx + fwdX * lz + latX * lx;
        const wz = (lx, lz) => cz + fwdZ * lz + latZ * lx;
        const wy = (lx, lz) => height + lz * gradient + lx * bank;

        // ── Дим от задните гуми (плъзгане/буксуване) ─────────────────────
        const rear = sig.rear;
        if (rear > 0.04 && speed > 6) {
            this.accum.smoke += dt * (45 + 45 * rear) * density;
            const size = 0.45 + 0.5 * rear;
            const alpha = (0.2 + 0.16 * rear) * (fx.wet ? 0.5 : 1);
            const grey = fx.wet ? 0.6 : 0.42;
            while (this.accum.smoke >= 1) {
                this.accum.smoke -= 1;
                this.sideToggle = -this.sideToggle;
                const lx = this.sideToggle * (axles.rear.x + 0.1);
                const lz = axles.rear.z - 0.2;
                const g = grey + Math.random() * 0.12;
                smoke.spawn(
                    wx(lx, lz) + (Math.random() - 0.5) * 0.3,
                    wy(lx, lz) + axles.rear.radius * 0.5,
                    wz(lx, lz) + (Math.random() - 0.5) * 0.3,
                    (Math.random() - 0.5) * 1.5 - fwdX * speed * 0.12 + latX * this.sideToggle * 0.8,
                    0.8 + Math.random() * 0.8 + rear,
                    (Math.random() - 0.5) * 1.5 - fwdZ * speed * 0.12 + latZ * this.sideToggle * 0.8,
                    PRESET.smoke, size, alpha, g, g, g, Math.random() * 6.28, height
                );
            }
        } else {
            this.accum.smoke = 0;
        }

        // ── Lock-up на вътрешното предно колело ──────────────────────────
        // Без sim-v3 телеметрия lock-up-ът е евристика (спирачка + силно
        // отрицателно gLong) и трае 0.3-0.8 s; с lockF е непрекъснат.
        let lock = 0;
        if (sig.hasOut) {
            lock = sig.frontLock > 0.3 ? sig.frontLock : 0;
        } else {
            if (sig.frontLock > 0 && this.lockTimer <= 0 && !this.wasBrakingHard) {
                this.lockTimer = 0.3 + Math.random() * 0.5;
            }
            this.wasBrakingHard = sig.frontLock > 0;
            if (this.lockTimer > 0) {
                this.lockTimer -= dt;
                lock = sig.brake > 0.5 ? 1 : 0;
            }
        }
        this.lock = lock;
        if (lock > 0 && speed > 8) {
            this.accum.lock += dt * 80 * lock * density;
            const lx = sig.inside * (axles.front.x + 0.12);
            const lz = axles.front.z;
            while (this.accum.lock >= 1) {
                this.accum.lock -= 1;
                const w = 0.84 + Math.random() * 0.08;
                smoke.spawn(
                    wx(lx, lz) + (Math.random() - 0.5) * 0.25,
                    wy(lx, lz) + 0.15,
                    wz(lx, lz) + (Math.random() - 0.5) * 0.25,
                    fwdX * speed * 0.08 + (Math.random() - 0.5) * 1.2 + latX * sig.inside * 0.6,
                    1.2 + Math.random() * 0.6,
                    fwdZ * speed * 0.08 + (Math.random() - 0.5) * 1.2 + latZ * sig.inside * 0.6,
                    PRESET.lockSmoke, 0.45, 0.32 * (fx.wet ? 0.5 : 1), w, w, w, Math.random() * 6.28, height
                );
            }
        } else {
            this.accum.lock = 0;
        }

        // ── Подзавиване: дребни сиви пуфове от двете предни ───────────────
        if (sig.understeer > 0.05 && speed > 10) {
            this.accum.scrub += dt * 30 * sig.understeer * density;
            while (this.accum.scrub >= 1) {
                this.accum.scrub -= 1;
                this.sideToggle = -this.sideToggle;
                const lx = this.sideToggle * (axles.front.x + 0.1);
                const lz = axles.front.z;
                const g = 0.5 + Math.random() * 0.1;
                smoke.spawn(
                    wx(lx, lz), wy(lx, lz) + 0.12, wz(lx, lz),
                    (Math.random() - 0.5) * 1.0 - fwdX * speed * 0.1,
                    0.6 + Math.random() * 0.5,
                    (Math.random() - 0.5) * 1.0 - fwdZ * speed * 0.1,
                    PRESET.scrub, 0.3, 0.15, g, g, g, Math.random() * 6.28, height
                );
            }
        } else {
            this.accum.scrub = 0;
        }

        // ── Титаниеви искри от планката ──────────────────────────────────
        // Непрекъснато над 72 m/s (пружините са долу на пода), плюс при спиране
        // от висока скорост, компресии/гърбици и кербове над 62 m/s; ×6 burst
        // 0.3 s при натискане на спирачката от максимална скорост.
        let sparkRate = 0;
        if (speed > 72) {
            sparkRate = 10 + 40 * smoothstep(72, 90, speed);
        }
        if (speed > 62 && (sig.gLong < -22 || Math.abs(this.vertCurv) > 0.004 || onKerb)) {
            sparkRate = Math.max(sparkRate, 90 + 60 * smoothstep(62, 85, speed));
        }
        if (speed > 72 && sig.gLong < -25 && this.sparkBurst <= 0 && sig.brake > 0.5) {
            this.sparkBurst = 0.3;
        }
        if (this.sparkBurst > 0) {
            this.sparkBurst -= dt;
            sparkRate = Math.max(sparkRate, 60) * 6;
        }
        if (night) {
            sparkRate *= 1.5;
        }
        if (sparkRate > 0) {
            this.accum.spark += dt * sparkRate * density;
            while (this.accum.spark >= 1) {
                this.accum.spark -= 1;
                this.#spark(
                    wx, wy, wz, fwdX, fwdZ, latX, latZ,
                    (Math.random() - 0.5) * 1.0, -1.2 + Math.random() * 2.2, speed, height
                );
            }
        } else {
            this.accum.spark = 0;
        }

        // ── Стъргане по стена (sim-v3 wallHit) ───────────────────────────
        const wall = out?.wallHit ?? null;
        if (wall !== null && wall !== undefined && speed > 4) {
            const nLocal = wall.nx * latX + wall.nz * latZ;
            const side = nLocal > 0 ? -1 : 1;
            this.accum.scrape += dt * 140 * Math.min(1, 0.4 + wall.impulse / 6) * density;
            while (this.accum.scrape >= 1) {
                this.accum.scrape -= 1;
                this.#spark(
                    wx, wy, wz, fwdX, fwdZ, latX, latZ,
                    side * 0.95, -1.5 + Math.random() * 3, speed, height
                );
            }
        } else {
            this.accum.scrape = 0;
        }

        // ── Прах и отломки по повърхност (4-те колела) ───────────────────
        if (offSurface !== null && offSurface !== 'asphalt' && speed > 5) {
            const surfaceKind = fx.terrainKind === 'sand' && offSurface === 'grass' ? 'sand' : offSurface;
            const colour = DUST[surfaceKind] ?? DUST.grass;
            this.accum.dust += dt * 40 * Math.min(2, speed / 40) * density;
            while (this.accum.dust >= 1) {
                this.accum.dust -= 1;
                const w = Math.floor(Math.random() * 4);
                const axle = w < 2 ? axles.rear : axles.front;
                const lx = (w & 1 ? 1 : -1) * axle.x;
                const lz = axle.z;
                const tint = 0.9 + Math.random() * 0.2;
                smoke.spawn(
                    wx(lx, lz), wy(lx, lz) + 0.2, wz(lx, lz),
                    (Math.random() - 0.5) * 2 - fwdX * speed * 0.25 + latX * Math.sign(lx) * 1.2,
                    1.0 + Math.random() * 1.5,
                    (Math.random() - 0.5) * 2 - fwdZ * speed * 0.25 + latZ * Math.sign(lx) * 1.2,
                    PRESET.dust, 1.2, 0.22, colour[0] * tint, colour[1] * tint, colour[2] * tint, Math.random() * 6.28, height
                );
            }

            // Камъни (чакъл) или туфи (трева): твърди, отскачат, тумбят се.
            const debrisPreset = offSurface === 'gravel' ? PRESET.stone : PRESET.clump;
            const debrisRate = offSurface === 'gravel' ? 45 : 14;
            this.accum.debris += dt * debrisRate * Math.min(1.5, speed / 30) * density;
            while (this.accum.debris >= 1) {
                this.accum.debris -= 1;
                const lx = (Math.random() > 0.5 ? 1 : -1) * (axles.rear.x + (Math.random() - 0.5) * 0.4);
                const lz = axles.rear.z + 0.2;
                const tint = 0.85 + Math.random() * 0.3;
                const c = offSurface === 'gravel' ? DUST.gravel : [0.3, 0.42, 0.14];
                smoke.spawn(
                    wx(lx, lz), wy(lx, lz) + 0.1, wz(lx, lz),
                    (Math.random() - 0.5) * 4 - fwdX * speed * 0.35,
                    2 + Math.random() * 3.5,
                    (Math.random() - 0.5) * 4 - fwdZ * speed * 0.35,
                    debrisPreset, offSurface === 'gravel' ? 0.14 + Math.random() * 0.1 : 0.22 + Math.random() * 0.12,
                    1, c[0] * tint, c[1] * tint, c[2] * tint, Math.random() * 6.28, wy(lx, lz)
                );
            }
        } else {
            this.accum.dust = 0;
            this.accum.debris = 0;
        }

        // ── Пясъчна диря по асфалта на пустинните писти ──────────────────
        if (fx.terrainKind === 'sand' && offSurface === null && speed > 40 && !fx.wet) {
            this.accum.wake += dt * 8 * density;
            while (this.accum.wake >= 1) {
                this.accum.wake -= 1;
                const lx = (Math.random() > 0.5 ? 1 : -1) * axles.rear.x;
                const lz = axles.rear.z - 0.5;
                smoke.spawn(
                    wx(lx, lz), wy(lx, lz) + 0.1, wz(lx, lz),
                    (Math.random() - 0.5) * 1.0, 0.3 + Math.random() * 0.4, (Math.random() - 0.5) * 1.0,
                    PRESET.wake, 1.0, 0.06, DUST.sand[0], DUST.sand[1], DUST.sand[2], Math.random() * 6.28, height
                );
            }
        } else {
            this.accum.wake = 0;
        }

        // ── Спрей в дъжд (weather-wet: setWet) ───────────────────────────
        const spray = fx.spray;
        if (spray !== null && fx.wet && speed > 20 && offSurface === null) {
            this.accum.spray += dt * 220 * Math.min(1.5, speed / 80) * density;
            while (this.accum.spray >= 1) {
                this.accum.spray -= 1;
                const rearWheel = Math.random() < 0.75;
                const axle = rearWheel ? axles.rear : axles.front;
                const lx = (Math.random() > 0.5 ? 1 : -1) * axle.x;
                const lz = axle.z - 0.3;
                spray.spawn(
                    wx(lx, lz) + (Math.random() - 0.5) * 0.2,
                    wy(lx, lz) + 0.1,
                    wz(lx, lz) + (Math.random() - 0.5) * 0.2,
                    (Math.random() - 0.5) * 3 - fwdX * speed * 0.5 + latX * Math.sign(lx) * 1.5,
                    1.0 + Math.random() * 2.0,
                    (Math.random() - 0.5) * 3 - fwdZ * speed * 0.5 + latZ * Math.sign(lx) * 1.5,
                    PRESET.spray, 0.25, 0.25, 0.85, 0.88, 0.92, 0, height
                );
            }
        } else {
            this.accum.spray = 0;
        }
    }

    /**
     * Една искра от пода: назад по посоката на движение, леко встрани и нагоре,
     * HDR цвят (bloom-ва нощем), отскача от асфалта.
     */
    #spark(wx, wy, wz, fwdX, fwdZ, latX, latZ, lx, lz, speed, height) {
        const back = speed * (0.8 + Math.random() * 0.15);
        const side = (Math.random() - 0.5) * 3;
        const heat = 0.8 + Math.random() * 0.4;
        this.effects.sparks.spawn(
            wx(lx, lz), wy(lx, lz) + 0.03, wz(lx, lz),
            -fwdX * back + latX * side,
            0.3 + Math.random() * 2.2,
            -fwdZ * back + latZ * side,
            PRESET.spark, 0.035 + Math.random() * 0.02, 1,
            3.0 * heat, 1.8 * heat, 0.6 * heat, 0, wy(lx, lz) + 0.01
        );
    }
}

// ─── Фасада ──────────────────────────────────────────────────────────────────

/** Доля на слънчевия цвят/интензитет, която стига до пуфа (иначе прегаря). */
const SUN_TO_PARTICLE = 0.45;

export class ParticleEffects {
    /**
     * @param {THREE.Scene} scene
     * @param {{lowPower?: boolean, quality?: {particles?: number}, circuit?: object,
     *          sunDir?: THREE.Vector3, sunColor?: THREE.Color|number, sunIntensity?: number}} [options]
     */
    constructor(scene, options = {}) {
        this.scene = scene;
        this.lowPower = options.lowPower === true;
        const particles = options.quality?.particles ?? (this.lowPower ? 0.5 : 1);
        this.density = Math.max(0.25, Math.min(1.5, particles));
        this.capacityScale = Math.max(0.5, Math.min(1, particles));

        const circuit = options.circuit ?? null;
        const look = circuit?.look ?? {};
        this.night = circuit?.atmosphere?.night === true;
        this.terrainKind = look.terrain?.kind ?? (circuit?.dust === 'sand' ? 'sand' : 'grass');
        this.wet = false;
        this.hasCamera = false;
        this.camPos = new THREE.Vector3();
        this.sunDir = new THREE.Vector3(0, 1, 0);
        this.depthSource = null;

        this.sheet = buildSpriteSheet();
        const cap = (n) => Math.round(n * this.capacityScale);
        this.smoke = new ParticlePool(cap(512), this.sheet, {
            blending: THREE.NormalBlending,
            unlit: false,
            additive: false,
            uploadsVelocity: false,
        });
        this.sparks = new ParticlePool(cap(192), this.sheet, {
            blending: THREE.AdditiveBlending,
            unlit: true,
            additive: true,
            uploadsVelocity: true,
        });
        // Спреят се създава лениво при setWet(true): сух ден не плаща буфера.
        this.spray = null;
        this.pools = [this.smoke, this.sparks];

        for (const pool of this.pools) {
            pool.mesh.onBeforeRender = this.#beforeRender;
            scene.add(pool.mesh);
        }

        this.setSun(options.sunDir ?? this.sunDir, options.sunColor ?? 0xfff2dc, options.sunIntensity ?? 2);
        this.setNight(this.night);
        const wind = look.wind ?? null;
        if (wind) {
            this.setWind(wind.x * (wind.speed ?? 1), wind.z * (wind.speed ?? 1));
        }

        this.emitters = [];
        this._legacyEmitter = null;
        this._legacySurface = { height: 0, gradient: 0, bank: 0 };
    }

    /**
     * Веднъж на рендер на всеки pool: пиксели/метър от реалната проекция и
     * буфер (FOV анимацията и governor-ът се отчитат сами), слънцето във
     * view space, депт текстурата за меките частици.
     *
     * @type {(renderer: THREE.WebGLRenderer, scene: THREE.Scene, camera: THREE.Camera, geometry: THREE.BufferGeometry, material: THREE.ShaderMaterial) => void}
     */
    #beforeRender = (renderer, scene, camera, geometry, material) => {
        const target = renderer.getRenderTarget();
        let width;
        let height;
        if (target !== null) {
            width = target.width;
            height = target.height;
        } else {
            renderer.getDrawingBufferSize(_v2);
            width = _v2.x;
            height = _v2.y;
        }
        const u = material.uniforms;
        u.uPixelScale.value = camera.projectionMatrix.elements[5] * height * 0.5;
        u.uResolution.value.set(width, height);
        u.uNear.value = camera.near;
        u.uFar.value = camera.far;
        u.uSunView.value.copy(_sunView.copy(this.sunDir).transformDirection(camera.matrixWorldInverse));

        // Депт текстурата на composer-а се пресъздава при setQuality — getter
        // вместо референция държи връзката жива без втори integration point.
        const source = this.depthSource;
        const depth = typeof source === 'function' ? (source() ?? null) : source;
        for (const pool of this.pools) {
            if (pool.mesh.material === material) {
                pool.setDepthTexture(this.lowPower ? null : depth);
            }
        }
    };

    /**
     * Емитер за една кола. rig.axles (car-model) дава осите; без rig — GLB
     * стойностите по подразбиране.
     *
     * @param {{axles?: object}|null} [rig]
     * @returns {CarEmitter}
     */
    createEmitter(rig = null) {
        const emitter = new CarEmitter(this, rig);
        this.emitters.push(emitter);
        return emitter;
    }

    /**
     * @param {CarEmitter} emitter
     */
    removeEmitter(emitter) {
        const i = this.emitters.indexOf(emitter);
        if (i >= 0) {
            this.emitters.splice(i, 1);
        }
    }

    /**
     * Остаряване на всички pool-ове + позиция на камерата за LOD/cull. Веднъж
     * на кадър, СЛЕД emit() на всички коли.
     *
     * LEGACY (до интеграцията): update(dt, render, groundY, state, sim) —
     * старият еднокаров вход; емитира за играча през вътрешен емитер.
     *
     * @param {number} dt
     * @param {THREE.Camera|object|null} [camera]
     */
    update(dt, camera = null, groundY, state, sim) {
        if (groundY !== undefined) {
            // `camera` тук е render-обектът на играча (носи и полетата на state).
            this._legacyEmitter ??= this.createEmitter(null);
            this._legacySurface.height = groundY;
            this._legacyEmitter.emit(dt, camera, this._legacySurface, sim, null, state.out);
            this.tick(dt);
            return;
        }

        if (camera !== null && camera.isCamera === true) {
            camera.getWorldPosition(this.camPos);
            this.hasCamera = true;
        }
        this.tick(dt);
    }

    /**
     * Само остаряване, без нови спаунове — за ТВ реплея, където димът от
     * последния жив кадър трябва да догори, не да замръзне.
     *
     * @param {number} dt
     */
    tick(dt) {
        for (const pool of this.pools) {
            pool.update(dt);
        }
    }

    /**
     * Депт текстура за меки частици: THREE.DepthTexture, null (твърди) или
     * функция, връщаща текущата (composerTarget.depthTexture се пресъздава при
     * setQuality). На lowPower винаги твърди.
     *
     * @param {THREE.Texture|(() => THREE.Texture|null)|null} source
     */
    setDepth(source) {
        this.depthSource = source;
    }

    /**
     * @param {THREE.Vector3} dirWorld Единичен вектор КЪМ слънцето
     * @param {THREE.Color|number} color
     * @param {number} [intensity]
     */
    setSun(dirWorld, color, intensity = 1) {
        this.sunDir.copy(dirWorld).normalize();
        for (const pool of this.pools) {
            pool.uniforms.uSunColor.value.set(color).multiplyScalar(intensity * SUN_TO_PARTICLE);
        }
    }

    /**
     * @param {THREE.Color|number} color
     * @param {number} [intensity]
     */
    setAmbient(color, intensity = 1) {
        for (const pool of this.pools) {
            pool.uniforms.uAmbient.value.set(color).multiplyScalar(intensity);
        }
    }

    /** @param {boolean} night */
    setNight(night) {
        this.night = night === true;
        if (this.night) {
            this.setAmbient(0x3a4460, 0.35);
        } else {
            this.setAmbient(0xc0c8d8, 0.4);
        }
    }

    /**
     * Мокра писта: спрей зад колите, по-блед дим. Създава spray pool-а лениво.
     *
     * @param {boolean} wet
     */
    setWet(wet) {
        this.wet = wet === true;
        if (this.wet && this.spray === null) {
            this.spray = new ParticlePool(Math.round(512 * this.capacityScale), this.sheet, {
                blending: THREE.NormalBlending,
                unlit: false,
                additive: false,
                uploadsVelocity: true,
            });
            this.spray.uniforms.uSunColor.value.copy(this.smoke.uniforms.uSunColor.value);
            this.spray.uniforms.uAmbient.value.copy(this.smoke.uniforms.uAmbient.value);
            this.spray.wind = this.smoke.wind;
            this.spray.mesh.onBeforeRender = this.#beforeRender;
            this.pools.push(this.spray);
            this.scene.add(this.spray.mesh);
        }
    }

    /**
     * Вятър (m/s, световни оси) — димът и прахът дрейфват с него.
     *
     * @param {number} x
     * @param {number} z
     */
    setWind(x, z) {
        // Половината скорост: пълният вятър отвява пуфа, преди да е пораснал.
        this.smoke.wind.x = x * 0.5;
        this.smoke.wind.z = z * 0.5;
        this.sparks.wind = this.smoke.wind;
        if (this.spray !== null) {
            this.spray.wind = this.smoke.wind;
        }
    }

    /**
     * Множител на темпото на спауновете (quality.particles). Капацитетът е
     * фиксиран при създаване.
     *
     * @param {number} density
     */
    setDensity(density) {
        this.density = Math.max(0.25, Math.min(1.5, density));
    }

    /**
     * LEGACY (до интеграцията): размерът вече е в метри и идва от проекцията.
     */
    setScale() {}

    /**
     * Взрив при контакт между коли: искри + прах.
     *
     * @param {number} x @param {number} y @param {number} z
     * @param {number} strength 0..1
     */
    burst(x, y, z, strength) {
        const count = Math.round((6 + strength * 14) * this.density);
        for (let i = 0; i < count; i++) {
            const heat = 0.8 + Math.random() * 0.4;
            this.sparks.spawn(
                x + (Math.random() - 0.5) * 1.2,
                y + Math.random() * 0.5,
                z + (Math.random() - 0.5) * 1.2,
                (Math.random() - 0.5) * 10,
                1 + Math.random() * 4,
                (Math.random() - 0.5) * 10,
                PRESET.spark, 0.04, 1, 3.0 * heat, 1.8 * heat, 0.6 * heat, 0, y - 0.4
            );
        }
        const puffs = Math.round((2 + strength * 4) * this.density);
        for (let i = 0; i < puffs; i++) {
            const g = 0.45 + Math.random() * 0.1;
            this.smoke.spawn(
                x + (Math.random() - 0.5) * 0.8, y, z + (Math.random() - 0.5) * 0.8,
                (Math.random() - 0.5) * 2, 0.8 + Math.random(), (Math.random() - 0.5) * 2,
                PRESET.scrub, 0.4, 0.2, g, g, g, Math.random() * 6.28, y - 0.4
            );
        }
    }

    /**
     * Насочени искри при удар в стена/кола: нормалата (nx, nz) сочи ОТ
     * препятствието към колата; искрите излитат по нея и назад.
     *
     * @param {number} x @param {number} y @param {number} z
     * @param {number} nx @param {number} nz
     * @param {number} strength 0..1
     */
    impactSparks(x, y, z, nx, nz, strength) {
        const count = Math.round((8 + strength * 24) * this.density);
        const tx = -nz;
        const tz = nx;
        for (let i = 0; i < count; i++) {
            const along = (Math.random() - 0.5) * 12;
            const out = 1 + Math.random() * 4 * strength;
            const heat = 0.8 + Math.random() * 0.4;
            this.sparks.spawn(
                x + tx * (Math.random() - 0.5) * 0.8,
                y + Math.random() * 0.3,
                z + tz * (Math.random() - 0.5) * 0.8,
                nx * out + tx * along,
                0.5 + Math.random() * 3,
                nz * out + tz * along,
                PRESET.spark, 0.04, 1, 3.0 * heat, 1.8 * heat, 0.6 * heat, 0, y - 0.3
            );
        }
    }

    /**
     * Конфети: залп от (x, y, z) — финал, рекорд, подиум. Пърхат надолу ~5 s.
     *
     * @param {number} x @param {number} y @param {number} z
     * @param {number} [strength] 0..1 (≈ 60·strength парчета)
     * @param {number} [spread] Хоризонтален радиус на залпа, m
     */
    confetti(x, y, z, strength = 1, spread = 2) {
        const count = Math.round(60 * strength * this.density);
        for (let i = 0; i < count; i++) {
            const c = CONFETTI_COLORS[Math.floor(Math.random() * CONFETTI_COLORS.length)];
            const ang = Math.random() * 6.28;
            const r = Math.random() * spread;
            this.smoke.spawn(
                x + Math.cos(ang) * r, y + Math.random() * 0.5, z + Math.sin(ang) * r,
                (Math.random() - 0.5) * 5, 2 + Math.random() * 6, (Math.random() - 0.5) * 5,
                PRESET.confetti, 0.12 + Math.random() * 0.06, 1, c[0], c[1], c[2], Math.random() * 6.28, y - 20
            );
        }
    }

    dispose() {
        for (const pool of this.pools) {
            this.scene.remove(pool.mesh);
            pool.dispose();
        }
        this.pools.length = 0;
        this.emitters.length = 0;
        this.sheet.dispose();
    }
}
