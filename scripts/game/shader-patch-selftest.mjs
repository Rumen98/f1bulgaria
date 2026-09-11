/**
 * Регресия: кръпките на настилките (surfaceShader.js) трябва да минават
 * през materialPatch за ВСЕКИ клас материал, който играта им подава — и на
 * десктоп, и на телефон. Теренът на телефон е MeshLambertMaterial
 * (terrain.js), който няма <roughnessmap_fragment>; подмяна на липсващ
 * chunk гърми в onBeforeCompile, т.е. вътре в renderer.render, и чупи
 * кадъра на всеки телефон (болидът не се рисува). Пада с код 1.
 *
 *   node scripts/game/shader-patch-selftest.mjs [public/game-tracks/monza.json]
 *
 * Без WebGL: кръпката се пуска върху текста от THREE.ShaderLib точно както
 * WebGLRenderer.getProgram вика material.onBeforeCompile(parameters), после
 * include-овете се резолвват като в WebGLProgram.resolveIncludes.
 */

import { readFileSync } from 'node:fs';

import * as THREE from 'three';

import { circuitFor } from '../../resources/js/game/circuits.js';
import { SURFACE_KINDS, applySurfaceShaders } from '../../resources/js/game/surfaceShader.js';
import { prepareTrack } from '../../resources/js/game/track.js';

const trackFile = process.argv[2] ?? 'public/game-tracks/monza.json';
const trackData = JSON.parse(readFileSync(trackFile, 'utf8'));
const circuit = circuitFor(trackData.slug);
const track = prepareTrack(trackData, circuit);

/** Същата таблица като WebGLPrograms.shaderIDs за класовете, които играта ползва за настилки. */
const SHADER_IDS = {
    MeshStandardMaterial: 'physical',
    MeshPhysicalMaterial: 'physical',
    MeshLambertMaterial: 'lambert',
};

/** Класовете, с които mesh.js / terrain.js създават настилки (Lambert = терен на телефон). */
const MATERIAL_FACTORIES = [
    () => new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 1 }),
    () => new THREE.MeshLambertMaterial({ vertexColors: true, dithering: true }),
];

const includePattern = /^[ \t]*#include +<([\w\d./]+)>/gm;

/**
 * Копие на WebGLProgram.resolveIncludes: непознат include е грешка при
 * компилация в three.
 *
 * @param {string} source
 * @returns {string}
 */
function resolveIncludes(source) {
    return source.replace(includePattern, (match, name) => {
        const chunk = THREE.ShaderChunk[name];
        if (chunk === undefined) {
            throw new Error(`непознат #include <${name}>`);
        }

        return resolveIncludes(chunk);
    });
}

/**
 * Символи от шейдъра на three, които кръпката ползва наготово. Ако кръпката
 * ги споменава, резолвнатият фрагмент трябва да ги декларира.
 */
const BORROWED_FRAGMENT_SYMBOLS = [
    ['vViewPosition', /varying\s+vec3\s+vViewPosition/],
    ['nonPerturbedNormal', /vec3\s+nonPerturbedNormal\s*=/],
    ['roughnessFactor', /float\s+roughnessFactor\s*=/],
    ['reflectedLight', /ReflectedLight\s+reflectedLight\s*=/],
    ['diffuseColor', /vec4\s+diffuseColor\s*=/],
    ['tbn', /mat3\s+tbn\s*=/],
];

let failures = 0;
let checked = 0;

for (const lowPower of [false, true]) {
    for (const factory of MATERIAL_FACTORIES) {
        const materials = {};
        for (const kind of SURFACE_KINDS) {
            materials[kind] = factory();
        }
        const controller = applySurfaceShaders(materials, track, circuit, {
            lowPower,
            cloudStrength: 0.5,
            wet: 0.3,
        });

        for (const kind of SURFACE_KINDS) {
            const material = materials[kind];
            const shaderId = SHADER_IDS[material.type];
            const label = `${lowPower ? 'телефон' : 'десктоп'} / ${kind} / ${material.type}`;
            checked++;

            const parameters = {
                vertexShader: THREE.ShaderLib[shaderId].vertexShader,
                fragmentShader: THREE.ShaderLib[shaderId].fragmentShader,
                uniforms: THREE.UniformsUtils.clone(THREE.ShaderLib[shaderId].uniforms),
                defines: {},
            };

            try {
                material.onBeforeCompile(parameters, null);
            } catch (error) {
                failures++;
                console.error(`СЕЛФТЕСТ: ${label}: onBeforeCompile гръмна — ${error.message}`);
                continue;
            }

            let fragment;
            try {
                resolveIncludes(parameters.vertexShader);
                fragment = resolveIncludes(parameters.fragmentShader);
            } catch (error) {
                failures++;
                console.error(`СЕЛФТЕСТ: ${label}: ${error.message}`);
                continue;
            }

            // Кръпката е между „// surfaceShader:" и void main(); заетите
            // символи трябва да са декларирани от three в същия фрагмент.
            const patchStart = parameters.fragmentShader.indexOf('// surfaceShader:');
            const patchText = parameters.fragmentShader.slice(patchStart);
            for (const [symbol, declaration] of BORROWED_FRAGMENT_SYMBOLS) {
                if (patchText.includes(symbol) && !declaration.test(fragment)) {
                    failures++;
                    console.error(`СЕЛФТЕСТ: ${label}: кръпката ползва ${symbol}, а ${material.type} не го декларира`);
                }
            }

            // Lambert няма roughness — кръпката не бива да го споменава.
            if (!material.isMeshStandardMaterial && /roughnessFactor/.test(patchText)) {
                failures++;
                console.error(`СЕЛФТЕСТ: ${label}: кръпката пише roughnessFactor върху материал без roughness`);
            }
        }

        controller.dispose();
        for (const material of Object.values(materials)) {
            material.dispose();
        }
    }
}

if (failures > 0) {
    console.error(`СЕЛФТЕСТ: ${failures} проблем(а) в ${checked} комбинации`);
    process.exit(1);
}

console.log(`Кръпките на настилките компилират за ${checked} комбинации (вид × клас × устройство).`);
