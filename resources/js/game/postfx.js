/**
 * Post-processing на десктоп: „broadcast" грейд + ефекти в ЕДИН fullscreen
 * проход върху линейния HDR буфер на composer-а, ПРЕДИ OutputPass (tone
 * mapping + sRGB). Телефонът няма composer (виж Game.js) — там няма и грейд.
 *
 * Всичко е uniform, за да го пълнят другите пакети без нов шейдър:
 *   - atmosphere задава грейда по писта (circuit.look.grade → setGrade);
 *   - car-model пълни uHaze (x, y, радиус, амплитуда — в UV на екрана) за
 *     трептенето над ауспуха;
 *   - Game.js пълни uSpeed/uCenter/uTime/uFrame/uAspect всеки кадър, а
 *     HUD-ът гаси motion blur/зърно през Game.setQuality.
 * Стойностите по подразбиране възпроизвеждат днешната картина 1:1 при
 * uSpeed = 0 (без размазване, без аберация, дитърът е под прага на окото).
 *
 * Ред на операциите във фрагмента:
 *   1. heat haze — UV трептене около uHaze.xy със шум от tNoise;
 *   2. радиален speed blur: 8 tap-а от uCenter навън, маска ∝ uSpeed², плюс
 *      хроматична аберация R/B по същата посока — само в размазаната зона,
 *      т.е. при покой пикселът се чете веднъж, без аберация;
 *   3. контраст около средно сиво (0.18 в линейно пространство — не 0.5,
 *      което линейно е почти бяло), наситеност по luma, топъл баланс
 *      (uWarm), повдигане на сенките (uLift), тониране на светлините
 *      (uSplitHigh, мултипликативно — не мести черното);
 *   4. винетка, по-плътна със скоростта;
 *   5. дитър ±½ LSB на ИЗХОДА. Проходът е преди sRGB кодирането, чийто
 *      наклон в сенките е ~6× по-стръмен от този в средните тонове —
 *      константен дитър в линейно пространство би бил или зърно в нощното
 *      небе, или нищо срещу бандинга на дневното. Амплитудата се
 *      компенсира по luma (обратната производна на sRGB: ∝ L^0.58).
 */

import * as THREE from 'three';
import { ShaderPass } from 'three/addons/postprocessing/ShaderPass.js';
import { getNoiseTexture } from './noiseTex.js';

/**
 * Днешният вид на грейда. atmosphere пакетът подава частичен обект със
 * същите ключове (circuit.look.grade); липсващите вземат тези стойности.
 *
 * @type {{sat: number, warm: [number, number, number], lift: [number, number, number],
 *         splitHigh: [number, number, number], contrast: number, vignette: number,
 *         grain: number, aberration: number}}
 */
export const GRADE_DEFAULTS = Object.freeze({
    sat: 1.08,
    warm: [1.02, 1.0, 0.985],
    lift: [0.0, 0.0015, 0.004],
    splitHigh: [0.0, 0.0, 0.0],
    contrast: 1.0,
    vignette: 0.16,
    grain: 1.0,
    aberration: 0.004,
});

export const GRADE_SHADER = {
    name: 'PadokGrade',
    uniforms: {
        tDiffuse: { value: null },
        tNoise: { value: null },
        uSat: { value: GRADE_DEFAULTS.sat },
        uWarm: { value: new THREE.Vector3().fromArray(GRADE_DEFAULTS.warm) },
        uLift: { value: new THREE.Vector3().fromArray(GRADE_DEFAULTS.lift) },
        uSplitHigh: { value: new THREE.Vector3().fromArray(GRADE_DEFAULTS.splitHigh) },
        uContrast: { value: GRADE_DEFAULTS.contrast },
        uVignette: { value: GRADE_DEFAULTS.vignette },
        uGrain: { value: GRADE_DEFAULTS.grain },
        uAberration: { value: GRADE_DEFAULTS.aberration },
        uSpeed: { value: 0 },
        uCenter: { value: new THREE.Vector2(0.5, 0.42) },
        uHaze: { value: new THREE.Vector4(0, 0, 0, 0) },
        uTime: { value: 0 },
        uFrame: { value: 0 },
        uAspect: { value: 16 / 9 },
    },
    vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
            vUv = uv;
            gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }
    `,
    fragmentShader: /* glsl */ `
        uniform sampler2D tDiffuse;
        uniform sampler2D tNoise;
        uniform float uSat;
        uniform vec3 uWarm;
        uniform vec3 uLift;
        uniform vec3 uSplitHigh;
        uniform float uContrast;
        uniform float uVignette;
        uniform float uGrain;
        uniform float uAberration;
        uniform float uSpeed;
        uniform vec2 uCenter;
        uniform vec4 uHaze;
        uniform float uTime;
        uniform float uFrame;
        uniform float uAspect;
        varying vec2 vUv;

        const vec3 LUMA = vec3(0.2126, 0.7152, 0.0722);

        // pcg3d (Jarzynski & Olano, 2020): целочислен хеш, стабилен при
        // големи координати/кадри — sin-базираните се разпадат на шарки,
        // щом аргументът надхвърли точността на float.
        float hashPixel(vec2 p, float frame) {
            uvec3 v = uvec3(uvec2(p), uint(frame));
            v = v * 1664525u + 1013904223u;
            v.x += v.y * v.z;
            v.y += v.z * v.x;
            v.z += v.x * v.y;
            v ^= v >> 16u;
            v.x += v.y * v.z;
            return float(v.x) / 4294967296.0;
        }

        void main() {
            vec2 uv = vUv;

            // 1. Heat haze над ауспуха: uHaze = (x, y, радиус, амплитуда).
            //    Ръбовете на smoothstep са ВЪЗХОДЯЩИ (GLSL ES 3.00 §8.3:
            //    edge0 >= edge1 е недефинирано), а радиус 0 се прескача —
            //    иначе делението вътре дава NaN, който тече в UV-то и във
            //    всеки следващ tap от tDiffuse.
            if (uHaze.w > 0.0 && uHaze.z > 0.0) {
                vec2 d = (vUv - uHaze.xy) * vec2(uAspect, 1.0);
                float m = uHaze.w * (1.0 - smoothstep(uHaze.z * 0.3, uHaze.z, length(d)));
                vec2 n = texture2D(tNoise, vUv * vec2(6.0, 12.0) + vec2(0.0, -uTime * 1.6)).xy - 0.5;
                uv += n * 0.018 * m;
            }

            // 2. Радиално размазване + хроматична аберация (само със скорост).
            vec2 dir = uv - uCenter;
            float mask = smoothstep(0.12, 0.6, length(dir * vec2(uAspect, 1.0))) * uSpeed * uSpeed;
            vec3 col;
            if (mask > 0.002) {
                vec2 ca = dir * uAberration * mask;
                col = vec3(0.0);
                for (int i = 0; i < 8; i++) {
                    vec2 o = uv - dir * (float(i) / 8.0) * 0.045 * mask;
                    col += vec3(
                        texture2D(tDiffuse, o - ca).r,
                        texture2D(tDiffuse, o).g,
                        texture2D(tDiffuse, o + ca).b
                    );
                }
                col *= 0.125;
            } else {
                col = texture2D(tDiffuse, uv).rgb;
            }

            // 3. Грейд.
            col = max(mix(vec3(0.18), col, uContrast), 0.0);
            float luma = dot(col, LUMA);
            col = mix(vec3(luma), col, uSat);
            col *= uWarm;
            col += uLift * max(0.0, 1.0 - luma);
            col *= 1.0 + uSplitHigh * smoothstep(0.5, 1.5, luma);

            // 4. Винетка.
            float d = distance(vUv, vec2(0.5));
            col *= 1.0 - smoothstep(0.55, 0.95, d) * (uVignette + 0.14 * uSpeed);

            // 5. Дитър ±½ LSB на изхода (виж заглавния коментар).
            float outLuma = max(dot(col, LUMA), 1e-4);
            float lsb = (1.0 / 255.0) * clamp(2.3 * pow(outLuma, 0.58), 0.15, 1.5);
            col += (hashPixel(gl_FragCoord.xy, uFrame) - 0.5) * lsb * uGrain;

            gl_FragColor = vec4(col, 1.0);
        }
    `,
};

/**
 * Грейдът на пистата: първо новата таблица circuit.look (atmosphere
 * пакетът), после старото място в atmosphere, иначе празно (= defaults).
 *
 * @param {object} circuit Стилът от circuits.js
 * @returns {object} Частичен грейд със същите ключове като GRADE_DEFAULTS
 */
export function gradeFor(circuit) {
    return circuit?.look?.grade ?? circuit?.atmosphere?.grade ?? {};
}

/**
 * Създава ShaderPass-а на грейда с шумовата текстура и стойностите на пистата.
 *
 * @param {{grade?: object, saturationScale?: number, aspect?: number}} [options]
 *        saturationScale компенсира tone mapping-а (AgX десатурира спрямо ACES);
 *        aspect = ширина/височина на canvas-а (Game.resize го обновява).
 * @returns {ShaderPass}
 */
export function createGradePass({ grade = {}, saturationScale = 1, aspect = 16 / 9 } = {}) {
    const pass = new ShaderPass(GRADE_SHADER);
    // Текстурата се закача СЛЕД клонирането на uniform-ите: UniformsUtils.clone
    // клонира и Texture обекти, а ние искаме споделения от noiseTex.js.
    pass.uniforms.tNoise.value = getNoiseTexture(64);
    pass.uniforms.uAspect.value = aspect;
    setGrade(pass, grade, saturationScale);

    return pass;
}

/**
 * Прилага (частичен) грейд върху жив pass — без нова програма, само uniform-и.
 *
 * @param {ShaderPass} pass
 * @param {object} [grade] Частичен обект с ключовете на GRADE_DEFAULTS
 * @param {number} [saturationScale=1]
 */
export function setGrade(pass, grade = {}, saturationScale = 1) {
    const resolved = { ...GRADE_DEFAULTS, ...grade };
    const u = pass.uniforms;
    u.uSat.value = resolved.sat * saturationScale;
    u.uWarm.value.fromArray(resolved.warm);
    u.uLift.value.fromArray(resolved.lift);
    u.uSplitHigh.value.fromArray(resolved.splitHigh);
    u.uContrast.value = resolved.contrast;
    u.uVignette.value = resolved.vignette;
    u.uGrain.value = resolved.grain;
    u.uAberration.value = resolved.aberration;
}
