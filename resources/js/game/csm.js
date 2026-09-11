import * as THREE from 'three';
import { CSM } from 'three/addons/csm/CSM.js';
import { applyPatch, removePatch } from './materialPatch.js';

const MATERIAL_PATCH = 'cascaded-shadows';
const MATERIAL_REFRESH_FRAMES = 120;
const EMPTY_LIGHTS = Object.freeze([]);

// The map size is deliberately below 2K on every tier. Three 1536px depth maps
// cost about 27 MiB and leave a GTX 1650 enough bandwidth for the post stack.
const QUALITY_PROFILES = Object.freeze({
    low: Object.freeze({ enabled: false, cascades: 0, mapSize: 0, maxFar: 0 }),
    medium: Object.freeze({
        enabled: true,
        cascades: 2,
        mapSize: 1024,
        maxFar: 550,
        lightMargin: 90,
        normalBias: 0.06,
        fade: false,
    }),
    auto: Object.freeze({
        enabled: true,
        cascades: 2,
        mapSize: 1536,
        maxFar: 850,
        lightMargin: 120,
        normalBias: 0.045,
        fade: true,
    }),
    high: Object.freeze({
        enabled: true,
        cascades: 2,
        mapSize: 1536,
        maxFar: 850,
        lightMargin: 120,
        normalBias: 0.045,
        fade: true,
    }),
    ultra: Object.freeze({
        enabled: true,
        cascades: 3,
        mapSize: 1536,
        maxFar: 1100,
        lightMargin: 150,
        normalBias: 0.04,
        fade: true,
    }),
});

// CSM mutates the process-wide ShaderChunk table and does not undo that in
// dispose(). Keep the mutation alive while at least one controller needs it,
// then restore the exact chunks that preceded the first controller.
const chunkState = {
    references: 0,
    originalFragment: null,
    originalPars: null,
    installedFragment: null,
    installedPars: null,
};

/**
 * Cascaded shadows for the racing scene.
 *
 * Stock CSM cannot make its shadows attenuate a different DirectionalLight:
 * each cascade light owns both its direct-light term and its shadow sample.
 * The controller therefore keeps the existing sun in the scene as a fallback,
 * copies its colour/intensity to the cascade lights, hides it while CSM is
 * active, and restores it verbatim on disable/dispose.
 *
 * `lightDirection` follows the Three CSM convention: the direction travelled
 * by the light rays (light -> track). If a primary DirectionalLight is already
 * in `scene`, the sign is corrected against that light automatically, so the
 * game's sunDir (track -> sun) is also safe to pass directly.
 *
 * @param {object} options
 * @param {THREE.Camera} options.camera
 * @param {THREE.Scene} options.scene
 * @param {THREE.WebGLRenderer} options.renderer
 * @param {THREE.Vector3|{x:number,y:number,z:number}|number[]} options.lightDirection
 * @param {boolean} [options.lowPower=false]
 * @param {string|object} [options.quality='auto']
 * @param {THREE.Object3D} [options.shadowProxy]
 * @returns {{
 *   readonly active: boolean,
 *   readonly cascades: number,
 *   readonly lights: THREE.DirectionalLight[],
 *   readonly primaryLight: THREE.DirectionalLight|null,
 *   readonly quality: string,
 *   update: (carPosition?: THREE.Vector3|{x:number,y:number,z:number}) => void,
 *   setQuality: (quality: string|object) => object,
 *   refreshMaterials: () => void,
 *   dispose: () => void,
 * }}
 */
export function createCascadedShadows({
    camera,
    scene,
    renderer,
    lightDirection,
    lowPower = false,
    quality = 'auto',
    shadowProxy = null,
} = {}) {
    // This branch intentionally precedes validation and scene inspection: on a
    // low-power device the factory allocates no lights, maps, patches or GPU work.
    if (lowPower) {
        return createInertController(normalizeQualityName(quality));
    }

    if (!camera?.isCamera) {
        throw new TypeError('createCascadedShadows: camera must be a Three.js Camera');
    }
    if (!scene?.isScene) {
        throw new TypeError('createCascadedShadows: scene must be a Three.js Scene');
    }
    if (!renderer?.isWebGLRenderer) {
        throw new TypeError('createCascadedShadows: renderer must be a Three.js WebGLRenderer');
    }

    const requestedDirection = readDirection(lightDirection);
    const materials = new Set();
    const proxyStates = new Map();
    let primaryState = null;
    let instance = null;
    let profile = null;
    let uniforms = null;
    let projectionState = null;
    let refreshIn = 0;
    let disposed = false;
    let rendererShadowState = null;
    let failure = null;

    const controller = {
        get active() {
            return instance !== null;
        },
        get cascades() {
            return instance?.cascades ?? 0;
        },
        get lights() {
            return instance?.lights ?? EMPTY_LIGHTS;
        },
        get primaryLight() {
            return primaryState?.light ?? null;
        },
        get quality() {
            return profile?.name ?? normalizeQualityName(quality);
        },
        get error() {
            return failure;
        },

        update(carPosition) {
            if (disposed || !instance) return;

            // Renderer.updateMatrixWorld happens after the game update. CSM uses
            // camera.matrixWorld immediately, so make the current camera pose explicit.
            camera.updateMatrixWorld();
            if (carPosition && shadowProxy?.updateWorldMatrix) {
                // The position is an API anchor for Game; the proxy remains parented to
                // the car, but its matrix must be current before the shadow pass.
                shadowProxy.updateWorldMatrix(true, true);
            }

            adoptPrimaryLight();
            syncPrimaryAppearance();

            if (projectionNeedsRefresh(camera, projectionState)) {
                projectionState = readProjectionState(camera);
                instance.updateFrustums();
                updateUniformValues();
            }

            if (refreshIn <= 0) {
                refreshMaterials();
                refreshIn = MATERIAL_REFRESH_FRAMES;
            } else {
                refreshIn -= 1;
            }

            instance.update();
        },

        setQuality(nextQuality) {
            if (disposed) return controller;
            quality = nextQuality;
            const nextProfile = resolveProfile(nextQuality, camera, scene, renderer);

            if (!nextProfile.enabled) {
                deactivate(true);
                profile = nextProfile;
                failure = null;
                return controller;
            }

            if (instance && sameProfile(profile, nextProfile)) {
                profile = nextProfile;
                applyCascadeAppearance();
                return controller;
            }

            deactivate(false);
            profile = nextProfile;
            failure = null;

            try {
                activate();
            } catch (error) {
                failure = error;
                deactivate(true);
                // A CSM failure must leave the original sun usable rather than abort
                // the game. The error is exposed on controller.error for diagnostics.
                console.warn('Cascaded shadows disabled; using the primary sun.', error);
            }

            return controller;
        },

        refreshMaterials,

        dispose() {
            if (disposed) return;
            deactivate(true);
            materials.clear();
            proxyStates.clear();
            disposed = true;
        },
    };

    function activate() {
        adoptPrimaryLight();
        const direction = orientedDirection(requestedDirection, primaryState?.light);

        rendererShadowState ??= { enabled: renderer.shadowMap.enabled };
        renderer.shadowMap.enabled = true;

        instance = createCsmInstance({
            camera,
            parent: scene,
            cascades: profile.cascades,
            maxFar: profile.maxFar,
            mode: 'practical',
            shadowMapSize: profile.mapSize,
            shadowBias: -0.0002,
            lightDirection: direction,
            lightIntensity: primaryState?.intensity ?? profile.lightIntensity,
            lightNear: 1,
            // Low sun angles project the wide far plane deeply into light space.
            // 2.4x covers that span without inheriting the camera's 2200 m sky far.
            lightFar: Math.max(900, profile.maxFar * 2.4),
            lightMargin: profile.lightMargin,
        });
        instance.fade = profile.fade;
        instance.updateFrustums();

        // Enabled → enabled profile changes can alter CSM_CASCADES. Keep the
        // uniform objects stable so renderer-side cached uniform lists see the
        // same vec2[] grow from two to three entries before the new program is
        // uploaded. Replacing this object left a stale two-entry array attached
        // to some compiled materials and WebGLUniforms.flatten read array[2].
        uniforms ??= {
            CSM_cascades: { value: [] },
            cameraNear: { value: camera.near },
            shadowFar: { value: Math.min(camera.far, profile.maxFar) },
        };
        updateUniformValues();
        applyCascadeAppearance();
        disablePrimaryLight();
        setShadowProxyEnabled(true);
        for (const material of materials) {
            patchMaterial(material);
        }
        refreshMaterials();
        projectionState = readProjectionState(camera);
        refreshIn = MATERIAL_REFRESH_FRAMES;
        camera.updateMatrixWorld();
        instance.update();
    }

    function deactivate(removeMaterialPatches) {
        if (instance) {
            syncPrimaryAppearance();
        }
        if (instance) {
            instance.remove();
            for (const light of instance.lights) {
                light.shadow?.dispose?.();
            }
            instance.dispose();
            releaseShaderChunks();
            instance = null;
        }

        if (removeMaterialPatches) {
            for (const material of materials) {
                if (removePatch(material, MATERIAL_PATCH)) {
                    // Clear renderer-side uniforms/program bindings as well as
                    // the source patch. Material.dispose() keeps the material
                    // and its textures usable; the next render recompiles it.
                    material.dispose();
                }
            }
            materials.clear();
            // A disabled/disposed controller owns no live shader bindings. A
            // later re-enable receives a fresh uniform object during activate().
            uniforms = null;
        }
        restorePrimaryLight();
        setShadowProxyEnabled(false);

        if (rendererShadowState) {
            renderer.shadowMap.enabled = rendererShadowState.enabled;
            rendererShadowState = null;
        }
    }

    function adoptPrimaryLight() {
        if (primaryState) return;
        const light = findPrimaryDirectionalLight(scene);
        if (!light) return;

        primaryState = {
            light,
            visible: light.visible,
            castShadow: light.castShadow,
            intensity: light.intensity,
            color: light.color.clone(),
        };

        if (instance) {
            instance.lightDirection.copy(orientedDirection(requestedDirection, light));
            applyCascadeAppearance();
            disablePrimaryLight();
            instance.updateFrustums();
            updateUniformValues();
        }
    }

    function disablePrimaryLight() {
        if (!primaryState) return;
        primaryState.light.castShadow = false;
        primaryState.light.visible = false;
    }

    function syncPrimaryAppearance() {
        if (!primaryState) return;
        const { light } = primaryState;
        if (light.intensity === primaryState.intensity && light.color.equals(primaryState.color)) return;

        // Night lighting can scale the retained sun after CSM was created. The
        // module never changes these two fields, so a difference is authoritative.
        primaryState.intensity = light.intensity;
        primaryState.color.copy(light.color);
        applyCascadeAppearance();
    }

    function restorePrimaryLight() {
        if (!primaryState) return;
        const { light } = primaryState;
        light.visible = primaryState.visible;
        light.castShadow = primaryState.castShadow;
        light.intensity = primaryState.intensity;
        light.color.copy(primaryState.color);
    }

    function applyCascadeAppearance() {
        if (!instance) return;
        const color = profile.lightColor ?? primaryState?.color ?? new THREE.Color(0xffffff);
        const intensity = profile.lightIntensity ?? primaryState?.intensity ?? 1;

        for (let index = 0; index < instance.lights.length; index += 1) {
            const light = instance.lights[index];
            light.name = `CSM cascade ${index + 1}/${instance.lights.length}`;
            light.userData.racingCsmCascade = true;
            light.color.copy(color);
            light.intensity = intensity;
            light.castShadow = true;
            light.shadow.bias = -0.0002;
            light.shadow.normalBias = profile.normalBias;
            light.shadow.radius = profile.fade ? 1.5 : 1;
            light.shadow.mapSize.set(profile.mapSize, profile.mapSize);
        }
    }

    function refreshMaterials() {
        if (disposed) return;

        const reachable = new Set();

        scene.traverse((object) => {
            const objectMaterials = Array.isArray(object.material)
                ? object.material
                : object.material ? [object.material] : EMPTY_LIGHTS;

            for (const material of objectMaterials) {
                if (supportsCsm(material)) {
                    reachable.add(material);
                }
            }
        });

        // Opponents and replacement ghosts own short-lived materials. Remove
        // their patches and strong references when their rigs leave the scene.
        for (const material of materials) {
            if (!reachable.has(material)) {
                if (removePatch(material, MATERIAL_PATCH)) {
                    material.dispose();
                }
                materials.delete(material);
            }
        }

        if (!instance || !uniforms) return;
        for (const material of reachable) {
            if (materials.has(material)) continue;
            materials.add(material);
            patchMaterial(material);
        }
    }

    function patchMaterial(material) {
        // CSM.setupMaterial() replaces onBeforeCompile. The game's wet surface,
        // cloud-shadow and material-detail patches share that hook, so reproduce
        // CSM's three defines/uniforms through the existing patch compositor.
        applyPatch(material, {
            name: MATERIAL_PATCH,
            uniforms,
            defines: {
                USE_CSM: 1,
                CSM_CASCADES: profile.cascades,
                ...(profile.fade ? { CSM_FADE: '' } : {}),
            },
        });
        // A previously compiled two/three-cascade variant can otherwise be
        // reused from Three's per-material program cache without rerunning
        // onBeforeCompile, retaining the uniform object from an older profile.
        // dispose() only releases renderer properties/programs; the material,
        // maps and application-owned patch state remain valid.
        material.dispose();
    }

    function updateUniformValues() {
        if (!instance || !uniforms) return;
        const breaks = instance.breaks;
        const cascadeUniform = uniforms.CSM_cascades.value;

        while (cascadeUniform.length < breaks.length) {
            cascadeUniform.push(new THREE.Vector2());
        }
        // Do not shrink on 3 → 2 either. A cached three-cascade program can
        // still upload this binding until Three swaps programs; an extra valid
        // Vector2 is ignored by the new two-entry uniform, while a missing third
        // entry crashes WebGLUniforms.flatten(). Disable/dispose discards the
        // entire uniforms object, so retained tails do not outlive the controller.

        for (let index = 0; index < breaks.length; index += 1) {
            cascadeUniform[index].set(breaks[index - 1] ?? 0, breaks[index]);
        }
        uniforms.cameraNear.value = camera.near;
        uniforms.shadowFar.value = Math.min(camera.far, instance.maxFar);
    }

    function setShadowProxyEnabled(enabled) {
        if (!shadowProxy?.traverse) return;
        shadowProxy.traverse((object) => {
            if (!object.isMesh) return;
            if (!proxyStates.has(object)) proxyStates.set(object, object.castShadow);
            object.castShadow = enabled ? true : proxyStates.get(object);
        });
    }

    controller.setQuality(quality);
    return controller;
}

function createInertController(quality) {
    const controller = {
        active: false,
        cascades: 0,
        lights: EMPTY_LIGHTS,
        primaryLight: null,
        quality,
        error: null,
        update() {},
        setQuality() { return controller; },
        refreshMaterials() {},
        dispose() {},
    };
    return Object.freeze(controller);
}

function resolveProfile(quality, camera, scene, renderer) {
    const name = normalizeQualityName(quality);
    const base = QUALITY_PROFILES[name] ?? QUALITY_PROFILES.auto;
    const settings = quality && typeof quality === 'object' ? quality : {};
    const disabled = quality === false || settings.csm === false || settings.cascadedShadows === false;

    if (disabled || !base.enabled) {
        return { ...QUALITY_PROFILES.low, name };
    }

    const maxTextureSize = Math.max(512, Math.min(2048, renderer.capabilities.maxTextureSize ?? 2048));
    const requestedCascades = finiteOr(settings.cascades ?? settings.csmCascades, base.cascades);
    const cascadeLimit = name === 'ultra' ? 3 : 2;
    const cascades = THREE.MathUtils.clamp(Math.round(requestedCascades), 1, cascadeLimit);
    const requestedMapSize = finiteOr(settings.shadowMapSize ?? settings.csmMapSize, base.mapSize);
    const mapSize = THREE.MathUtils.clamp(Math.round(requestedMapSize), 512, maxTextureSize);
    const fogFar = Number.isFinite(scene.fog?.far) ? scene.fog.far : camera.far;
    const requestedFar = finiteOr(settings.shadowFar ?? settings.csmFar, base.maxFar);
    const maxFar = Math.max(camera.near + 10, Math.min(requestedFar, fogFar, camera.far));
    const lightColor = settings.lightColor === undefined ? null : new THREE.Color(settings.lightColor);
    const lightIntensity = Number.isFinite(settings.lightIntensity) && settings.lightIntensity >= 0
        ? settings.lightIntensity
        : null;

    return {
        ...base,
        name,
        cascades,
        mapSize,
        maxFar,
        fade: settings.csmFade ?? base.fade,
        lightColor,
        lightIntensity,
    };
}

function normalizeQualityName(quality) {
    if (quality === false) return 'low';
    if (typeof quality === 'string') {
        const name = quality.toLowerCase();
        return QUALITY_PROFILES[name] ? name : 'auto';
    }
    if (!quality || typeof quality !== 'object') return 'auto';

    const candidate = quality.csmQuality
        ?? quality.preset
        ?? quality.level
        ?? quality.name
        ?? quality.shadows;
    if (typeof candidate !== 'string') return 'auto';
    const name = candidate.toLowerCase();
    return QUALITY_PROFILES[name] ? name : 'auto';
}

function sameProfile(left, right) {
    if (!left || !right) return false;
    return left.enabled === right.enabled
        && left.cascades === right.cascades
        && left.mapSize === right.mapSize
        && left.maxFar === right.maxFar
        && left.lightMargin === right.lightMargin
        && left.normalBias === right.normalBias
        && left.fade === right.fade
        && left.lightIntensity === right.lightIntensity
        && colorsEqual(left.lightColor, right.lightColor);
}

function colorsEqual(left, right) {
    if (left === right) return true;
    if (!left || !right) return false;
    return left.equals(right);
}

function supportsCsm(material) {
    return Boolean(material?.isMaterial && (
        material.isMeshStandardMaterial
        || material.isMeshPhysicalMaterial
        || material.isMeshLambertMaterial
        || material.isMeshPhongMaterial
        || material.isMeshToonMaterial
    ));
}

function findPrimaryDirectionalLight(scene) {
    let best = null;
    let bestScore = -Infinity;
    scene.traverse((object) => {
        if (!object.isDirectionalLight || object.userData?.racingCsmCascade) return;
        const score = (object.castShadow ? 4 : 0) + (object.visible ? 2 : 0) + (object.intensity > 0 ? 1 : 0);
        if (score > bestScore) {
            best = object;
            bestScore = score;
        }
    });
    return best;
}

function orientedDirection(requested, primaryLight) {
    const direction = requested.clone();
    if (!primaryLight) return direction;

    const lightPosition = new THREE.Vector3();
    const targetPosition = new THREE.Vector3();
    primaryLight.getWorldPosition(lightPosition);
    primaryLight.target.getWorldPosition(targetPosition);
    const primaryDirection = targetPosition.sub(lightPosition);
    if (primaryDirection.lengthSq() > 1e-8 && direction.dot(primaryDirection) < 0) {
        direction.negate();
    }
    return direction.normalize();
}

function readDirection(value) {
    const direction = new THREE.Vector3();
    if (value?.isVector3) {
        direction.copy(value);
    } else if (Array.isArray(value)) {
        direction.set(Number(value[0]), Number(value[1]), Number(value[2]));
    } else if (value && typeof value === 'object') {
        direction.set(Number(value.x), Number(value.y), Number(value.z));
    } else {
        direction.set(1, -1, 1);
    }

    if (![direction.x, direction.y, direction.z].every(Number.isFinite) || direction.lengthSq() < 1e-8) {
        throw new TypeError('createCascadedShadows: lightDirection must be a finite, non-zero vector');
    }
    return direction.normalize();
}

function readProjectionState(camera) {
    return {
        near: camera.near,
        far: camera.far,
        fov: camera.fov,
        aspect: camera.aspect,
        zoom: camera.zoom,
    };
}

function projectionNeedsRefresh(camera, previous) {
    if (!previous) return true;
    return Math.abs(camera.near - previous.near) > 1e-6
        || Math.abs(camera.far - previous.far) > 1e-6
        || Math.abs(camera.fov - previous.fov) > 0.5
        || Math.abs(camera.aspect - previous.aspect) > 1e-4
        || Math.abs(camera.zoom - previous.zoom) > 1e-4;
}

function finiteOr(value, fallback) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function createCsmInstance(options) {
    const first = chunkState.references === 0;
    if (first) {
        chunkState.originalFragment = THREE.ShaderChunk.lights_fragment_begin;
        chunkState.originalPars = THREE.ShaderChunk.lights_pars_begin;
    }

    let csm;
    try {
        csm = new CSM(options);
    } catch (error) {
        if (first) {
            THREE.ShaderChunk.lights_fragment_begin = chunkState.originalFragment;
            THREE.ShaderChunk.lights_pars_begin = chunkState.originalPars;
            clearChunkState();
        }
        throw error;
    }

    if (first) {
        chunkState.installedFragment = THREE.ShaderChunk.lights_fragment_begin;
        chunkState.installedPars = THREE.ShaderChunk.lights_pars_begin;
    }
    chunkState.references += 1;
    return csm;
}

function releaseShaderChunks() {
    chunkState.references = Math.max(0, chunkState.references - 1);
    if (chunkState.references !== 0) return;

    // Do not overwrite a later third-party ShaderChunk patch.
    if (THREE.ShaderChunk.lights_fragment_begin === chunkState.installedFragment) {
        THREE.ShaderChunk.lights_fragment_begin = chunkState.originalFragment;
    }
    if (THREE.ShaderChunk.lights_pars_begin === chunkState.installedPars) {
        THREE.ShaderChunk.lights_pars_begin = chunkState.originalPars;
    }
    clearChunkState();
}

function clearChunkState() {
    chunkState.originalFragment = null;
    chunkState.originalPars = null;
    chunkState.installedFragment = null;
    chunkState.installedPars = null;
}
