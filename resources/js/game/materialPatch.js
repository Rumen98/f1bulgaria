/**
 * Композируеми onBeforeCompile „кръпки" за материалите на three.js.
 *
 * ЗАЩО: three дава ЕДИН material.onBeforeCompile. Пет пакета искат да пипат
 * едни и същи материали (мъгла, анти-тайлинг, гума, облачни сенки, вятър,
 * мокро) — последният записал би изтрил останалите. Тук всеки пакет
 * регистрира ИМЕНУВАНА кръпка; модулът сглобява един hook, който ги прилага
 * по реда на добавяне, и подменя customProgramCacheKey, така че материали с
 * различен набор кръпки да НЕ споделят GLSL програма. (three кешира
 * програмите по ключ; по подразбиране ключът е onBeforeCompile.toString(),
 * който за общия ни hook е еднакъв навсякъде — без подмяна вторият материал
 * би получил програмата на първия, с чужди uniform-и.)
 *
 * ДОГОВОР:
 *
 *   applyPatch(material, {
 *       name: 'fog',                               // уникално в материала; повторно applyPatch със същото име ЗАМЕНЯ
 *       uniforms: { uFogHeight: { value: 12 } },   // СПОДЕЛЕНИ обекти: пазиш референцията и сменяш .value
 *       defines: { USE_FOO: '' },                  // по избор; влизат в ключа на програмата
 *       vertexHead: 'varying float vWorldY;',      // преди void main() на вертекс шейдъра
 *       vertexMain: 'vWorldY = (modelMatrix * vec4(transformed, 1.0)).y;',
 *                                                  // в КРАЯ на main() — transformed/mvPosition/gl_Position са сметнати
 *       fragmentHead: 'varying float vWorldY;',    // преди void main() на фрагмента
 *       fragmentMain: 'gl_FragColor.rgb *= tint;', // в КРАЯ на main() — след fog/dithering chunk-овете
 *       replace: [['fog_fragment', '…glsl…']],     // подменя `#include <fog_fragment>` в който и да е стадий
 *   });
 *   removePatch(material, 'fog');
 *   hasPatch(material, 'fog');
 *   installPatchHook(material);   // само ако чужд код е презаписал onBeforeCompile СЛЕД кръпките (виж по-долу)
 *
 * Правила:
 *   - replace търси `#include <име>` и заменя ПЪРВОТО срещане във всеки
 *     стадий. Заместващият GLSL може сам да съдържа `#include <…>` — three
 *     резолвва include-овете СЛЕД onBeforeCompile — така две кръпки се
 *     верижат върху един chunk, ако първата го запази в текста си
 *     (`'#include <begin_vertex>\n transformed += wind;'`). Липсващ chunk =
 *     throw при компилация: това е програмна грешка на пакета, не runtime
 *     ситуация, и трябва да гръмне в разработка, а не да се скрие тихо.
 *   - Кръпките се прилагат в реда на добавяне. Ако материалът вече е имал
 *     чужд onBeforeCompile (напр. от CSM.setupMaterial), той се пази като
 *     base и се вика ПЪРВИ. Ако чужда библиотека презапише onBeforeCompile
 *     СЛЕД нашите кръпки, извикай installPatchHook(material): прихваща
 *     чуждия hook като base и връща композирания отгоре.
 *   - material.userData.patches е масивът с кръпките (само за четене). Той е
 *     НЕизброимо свойство: Material.copy прави JSON.parse(JSON.stringify(
 *     userData)), а JSON.stringify вика Texture.toJSON за всеки uniform с
 *     текстура — за DataTexture сериализира целия image.data (256² шум ≈ 1 MB
 *     JSON), за <img>/ImageBitmap прави canvas.toDataURL (PNG кодиране на
 *     1024² PBR карта) — на ВСЕКИ клонинг (buildOpponentRig: 5 коли × 4
 *     материала). Неизброимото свойство просто не влиза в JSON-а.
 *   - material.clone() НЕ пренася кръпките: клонингът няма userData.patches
 *     изобщо и не копира own onBeforeCompile — прилагай ги наново върху него.
 *   - applyPatch вдига material.needsUpdate (нова програма). Викай го при
 *     подготовка на сцената, никога по кадър.
 *   - Работи и за ShaderMaterial (uniforms там са material.uniforms — three
 *     подава същия обект, така че патч uniform-ите се добавят в него).
 */

import * as THREE from 'three';

const DEFAULT_HOOK = THREE.Material.prototype.onBeforeCompile;
const DEFAULT_CACHE_KEY = THREE.Material.prototype.customProgramCacheKey;

/**
 * @typedef {object} MaterialPatch
 * @property {string} name
 * @property {Record<string, {value: unknown}>} [uniforms]
 * @property {Record<string, string|number>} [defines]
 * @property {string} [vertexHead]
 * @property {string} [vertexMain]
 * @property {string} [fragmentHead]
 * @property {string} [fragmentMain]
 * @property {Array<[string, string]>} [replace]
 */

/**
 * @typedef {object} PatchState
 * @property {Array<MaterialPatch & {key: string, replace: Array<[string, string]>}>} patches  Каноничният списък (userData.patches е изглед към него)
 * @property {(shader: object, renderer: THREE.WebGLRenderer) => void} hook  Композираният onBeforeCompile
 * @property {Function|null} base           Чужд onBeforeCompile, заварен на материала
 * @property {string} baseKey               Хеш на чуждия hook за ключа на програмата
 * @property {Function|null} baseCacheKey   Чужд customProgramCacheKey, заварен на материала
 * @property {(() => string)|null} cacheKeyFn  Нашият customProgramCacheKey (за разпознаване на презапис)
 */

/** @type {WeakMap<THREE.Material, PatchState>} */
const states = new WeakMap();

/**
 * Добавя (или заменя по име) кръпка към материала.
 *
 * @param {THREE.Material} material
 * @param {MaterialPatch} patch
 * @returns {THREE.Material} Същият материал (за верижно писане)
 */
export function applyPatch(material, patch) {
    if (!material?.isMaterial) {
        throw new TypeError('applyPatch: очаква three.js Material');
    }
    if (typeof patch?.name !== 'string' || patch.name === '') {
        throw new TypeError('applyPatch: кръпката трябва да има непразно name');
    }

    const { patches } = installPatchHook(material);
    const normalized = normalizePatch(patch);
    const index = patches.findIndex((existing) => existing.name === normalized.name);
    if (index >= 0) {
        patches[index] = normalized;
    } else {
        patches.push(normalized);
    }
    material.needsUpdate = true;

    return material;
}

/**
 * Маха кръпка по име.
 *
 * @param {THREE.Material} material
 * @param {string} name
 * @returns {boolean} Дали е имало такава
 */
export function removePatch(material, name) {
    const patches = states.get(material)?.patches;
    const index = patches ? patches.findIndex((patch) => patch.name === name) : -1;
    if (index < 0) {
        return false;
    }
    patches.splice(index, 1);
    material.needsUpdate = true;

    return true;
}

/**
 * @param {THREE.Material} material
 * @param {string} name
 * @returns {boolean}
 */
export function hasPatch(material, name) {
    return states.get(material)?.patches.some((patch) => patch.name === name) ?? false;
}

/**
 * Инсталира композирания hook върху материала (идемпотентно). Заварен чужд
 * onBeforeCompile/customProgramCacheKey се пазят и се викат отдолу.
 * applyPatch я вика сам; ръчно трябва само след библиотека, която презаписва
 * onBeforeCompile (CSM.setupMaterial).
 *
 * @param {THREE.Material} material
 * @returns {PatchState}
 */
export function installPatchHook(material) {
    let state = states.get(material);
    if (!state) {
        state = { patches: [], hook: null, base: null, baseKey: '', baseCacheKey: null, cacheKeyFn: null };
        state.hook = (shader, renderer) => runPatches(material, state, shader, renderer);
        states.set(material, state);
        // Изглед за четене; неизброим, за да не влиза в JSON копието при
        // clone() (виж заглавния коментар). configurable: презаписва и
        // евентуален стар изброим ключ, оставен от чужд код.
        Object.defineProperty(material.userData, 'patches', {
            value: state.patches,
            enumerable: false,
            configurable: true,
            writable: false,
        });
    }

    if (material.onBeforeCompile !== state.hook) {
        const current = material.onBeforeCompile;
        state.base = current === DEFAULT_HOOK ? null : current;
        state.baseKey = state.base ? hashString(state.base.toString()) : '';
        material.onBeforeCompile = state.hook;
        material.needsUpdate = true;
    }

    if (material.customProgramCacheKey !== state.cacheKeyFn) {
        const current = material.customProgramCacheKey;
        state.baseCacheKey = current === DEFAULT_CACHE_KEY ? null : current;
        state.cacheKeyFn = () => programCacheKey(material, state);
        material.customProgramCacheKey = state.cacheKeyFn;
    }

    return state;
}

/**
 * Ключът на програмата: чуждият ключ (ако има) + хеш на чуждия hook + ключ
 * на всяка кръпка (име + хеш на GLSL/defines). Материали с еднакъв набор
 * кръпки споделят програма (същите декларации), различните — не.
 *
 * @param {THREE.Material} material
 * @param {PatchState} state
 * @returns {string}
 */
function programCacheKey(material, state) {
    const parts = [state.baseCacheKey ? String(state.baseCacheKey.call(material)) : '', state.baseKey];
    for (const patch of state.patches) {
        parts.push(patch.key);
    }

    return parts.join('|');
}

/**
 * @param {THREE.Material} material
 * @param {PatchState} state
 * @param {object} shader Параметрите от WebGLPrograms: uniforms, vertexShader, fragmentShader, defines…
 * @param {THREE.WebGLRenderer} renderer
 */
function runPatches(material, state, shader, renderer) {
    state.base?.call(material, shader, renderer);
    for (const patch of state.patches) {
        applyToShader(material, patch, shader);
    }
}

/**
 * @param {THREE.Material} material
 * @param {MaterialPatch & {key: string, replace: Array<[string, string]>}} patch
 * @param {object} shader
 */
function applyToShader(material, patch, shader) {
    if (patch.uniforms) {
        for (const name of Object.keys(patch.uniforms)) {
            shader.uniforms[name] = patch.uniforms[name];
        }
    }
    if (patch.defines) {
        // Нов обект: shader.defines може да е самият material.defines.
        shader.defines = Object.assign({}, shader.defines, patch.defines);
    }

    for (const [chunk, glsl] of patch.replace) {
        const include = `#include <${chunk}>`;
        const inVertex = shader.vertexShader.includes(include);
        const inFragment = shader.fragmentShader.includes(include);
        if (!inVertex && !inFragment) {
            throw new Error(`materialPatch „${patch.name}": chunk <${chunk}> липсва в шейдъра на ${material.type}`);
        }
        // Функция вместо низ: `$&`/`$1` в GLSL не бива да се тълкуват като шаблони.
        if (inVertex) {
            shader.vertexShader = shader.vertexShader.replace(include, () => glsl);
        }
        if (inFragment) {
            shader.fragmentShader = shader.fragmentShader.replace(include, () => glsl);
        }
    }

    if (patch.vertexHead) {
        shader.vertexShader = insertBeforeMain(shader.vertexShader, patch.vertexHead, patch, material, 'vertex');
    }
    if (patch.vertexMain) {
        shader.vertexShader = insertAtMainEnd(shader.vertexShader, patch.vertexMain);
    }
    if (patch.fragmentHead) {
        shader.fragmentShader = insertBeforeMain(shader.fragmentShader, patch.fragmentHead, patch, material, 'fragment');
    }
    if (patch.fragmentMain) {
        shader.fragmentShader = insertAtMainEnd(shader.fragmentShader, patch.fragmentMain);
    }
}

/**
 * @param {string} source
 * @param {string} glsl
 * @param {MaterialPatch} patch
 * @param {THREE.Material} material
 * @param {string} stage
 * @returns {string}
 */
function insertBeforeMain(source, glsl, patch, material, stage) {
    const index = source.indexOf('void main()');
    if (index < 0) {
        throw new Error(`materialPatch „${patch.name}": ${stage} шейдърът на ${material.type} няма void main()`);
    }

    return `${source.slice(0, index)}${glsl}\n${source.slice(index)}`;
}

/**
 * Вмъква преди последната затваряща скоба — при вградените шейдъри на three
 * main() е последната функция.
 *
 * @param {string} source
 * @param {string} glsl
 * @returns {string}
 */
function insertAtMainEnd(source, glsl) {
    const index = source.lastIndexOf('}');

    return `${source.slice(0, index)}${glsl}\n${source.slice(index)}`;
}

/**
 * Копира полетата на кръпката (uniforms остават СЪЩИЯТ обект) и смята
 * ключа ѝ: име + FNV-1a на всичко, което влиза в GLSL текста.
 *
 * @param {MaterialPatch} patch
 * @returns {MaterialPatch & {key: string, replace: Array<[string, string]>}}
 */
function normalizePatch(patch) {
    const replace = (patch.replace ?? []).map(([chunk, glsl]) => {
        if (typeof chunk !== 'string' || typeof glsl !== 'string') {
            throw new TypeError(`applyPatch „${patch.name}": replace очаква двойки [chunkName, glsl]`);
        }

        return [chunk, glsl];
    });
    const source = [
        patch.vertexHead ?? '',
        patch.vertexMain ?? '',
        patch.fragmentHead ?? '',
        patch.fragmentMain ?? '',
        JSON.stringify(patch.defines ?? null),
        ...replace.flat(),
    ].join('\u0000');

    return {
        name: patch.name,
        key: `${patch.name}#${hashString(source).toString(16)}`,
        uniforms: patch.uniforms ?? null,
        defines: patch.defines ?? null,
        vertexHead: patch.vertexHead ?? '',
        vertexMain: patch.vertexMain ?? '',
        fragmentHead: patch.fragmentHead ?? '',
        fragmentMain: patch.fragmentMain ?? '',
        replace,
    };
}

/**
 * FNV-1a хеш на низ.
 *
 * @param {string} value
 * @returns {number}
 */
function hashString(value) {
    let hash = 2166136261;

    for (let i = 0; i < value.length; i++) {
        hash ^= value.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }

    return hash >>> 0;
}
