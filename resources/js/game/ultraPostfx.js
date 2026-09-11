/**
 * Скъпите post effects, които се зареждат само при изрично избрано Ultra.
 *
 * GTAO използва depth texture-а на основния MSAA render target. Нормалите се
 * възстановяват от дълбочината, така че няма второ рисуване на сцената. Самият
 * AO и bilateral denoise са на половин резолюция; един лек fullscreen pass ги
 * смесва обратно в линейния HDR кадър преди bloom/tone mapping.
 */

import * as THREE from 'three';
import { FullScreenQuad, Pass } from 'three/addons/postprocessing/Pass.js';
import { generateMagicSquareNoise, GTAOShader } from 'three/addons/shaders/GTAOShader.js';
import {
    generatePdSamplePointInitializer,
    PoissonDenoiseShader,
} from 'three/addons/shaders/PoissonDenoiseShader.js';

const RESOLUTION_SCALE = 0.5;
const GTAO_SAMPLES = 8;
const DENOISE_SAMPLES = 8;

const COMPOSITE_SHADER = {
    name: 'RacingUltraGtaoComposite',
    uniforms: {
        tDiffuse: { value: null },
        tAo: { value: null },
        // Финален мек слой: дори напълно затворен пиксел не може да потъмнее
        // с повече от ~23%, за да не изглежда дневната сцена мръсна.
        uIntensity: { value: 0.52 },
        uMinAo: { value: 0.55 },
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
        uniform sampler2D tAo;
        uniform float uIntensity;
        uniform float uMinAo;
        varying vec2 vUv;

        void main() {
            vec4 base = texture2D(tDiffuse, vUv);
            float ao = max(texture2D(tAo, vUv).r, uMinAo);
            base.rgb *= mix(1.0, ao, uIntensity);
            gl_FragColor = base;
        }
    `,
};

/**
 * @param {string} name
 * @returns {THREE.WebGLRenderTarget}
 */
function makeHalfFloatTarget(name) {
    const target = new THREE.WebGLRenderTarget(1, 1, {
        type: THREE.HalfFloatType,
        format: THREE.RGBAFormat,
        minFilter: THREE.LinearFilter,
        magFilter: THREE.LinearFilter,
        depthBuffer: false,
        stencilBuffer: false,
    });
    target.texture.name = name;
    target.texture.generateMipmaps = false;

    return target;
}

/**
 * Малка детерминирана RGBA noise текстура за Poisson ротацията. Създава се
 * само след lazy import-а на Ultra модула.
 *
 * @param {number} size
 * @returns {THREE.DataTexture}
 */
function makeDenoiseNoise(size = 32) {
    const data = new Uint8Array(size * size * 4);
    let state = 0x6d2b79f5;
    for (let i = 0; i < data.length; i += 1) {
        state ^= state << 13;
        state ^= state >>> 17;
        state ^= state << 5;
        data[i] = state & 0xff;
    }
    const texture = new THREE.DataTexture(data, size, size, THREE.RGBAFormat, THREE.UnsignedByteType);
    texture.name = 'ultra-gtao-denoise-noise';
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.minFilter = THREE.NearestFilter;
    texture.magFilter = THREE.NearestFilter;
    texture.generateMipmaps = false;
    texture.needsUpdate = true;

    return texture;
}

/**
 * GTAOPass от three r180 не може безопасно да се resize/dispose-не със supplied
 * depth texture: normalRenderTarget остава undefined, но двата lifecycle метода
 * го използват без guard. Този pass съдържа само нужните три fullscreen стъпки
 * и никога не създава normal target или normal prepass.
 */
class HalfResolutionGtaoPass extends Pass {
    /**
     * @param {THREE.PerspectiveCamera} camera
     * @param {THREE.DepthTexture} depthTexture
     */
    constructor(camera, depthTexture) {
        super();
        this.name = 'ultra-half-resolution-gtao';
        this.camera = camera;
        this.depthTexture = depthTexture;
        this.width = 1;
        this.height = 1;
        this.disposed = false;

        this.aoTarget = makeHalfFloatTarget('ultra-gtao-raw');
        this.denoiseTarget = makeHalfFloatTarget('ultra-gtao-denoised');
        this.gtaoNoise = generateMagicSquareNoise();
        this.denoiseNoise = makeDenoiseNoise();

        this.gtaoMaterial = new THREE.ShaderMaterial({
            name: 'ultra-gtao-8-sample',
            defines: {
                ...GTAOShader.defines,
                SAMPLES: GTAO_SAMPLES,
                NORMAL_VECTOR_TYPE: 0,
                DEPTH_SWIZZLING: 'x',
                SCREEN_SPACE_RADIUS: 0,
            },
            uniforms: THREE.UniformsUtils.clone(GTAOShader.uniforms),
            vertexShader: GTAOShader.vertexShader,
            fragmentShader: GTAOShader.fragmentShader,
            depthTest: false,
            depthWrite: false,
            blending: THREE.NoBlending,
            toneMapped: false,
        });
        const gtao = this.gtaoMaterial.uniforms;
        gtao.tDepth.value = depthTexture;
        gtao.tNormal.value = null;
        gtao.tNoise.value = this.gtaoNoise;
        gtao.radius.value = 1.05;
        gtao.distanceExponent.value = 1.55;
        gtao.thickness.value = 0.85;
        gtao.distanceFallOff.value = 0.85;
        gtao.scale.value = 0.9;

        this.denoiseMaterial = new THREE.ShaderMaterial({
            name: 'ultra-gtao-denoise-8-sample',
            defines: {
                ...PoissonDenoiseShader.defines,
                SAMPLES: DENOISE_SAMPLES,
                SAMPLE_VECTORS: generatePdSamplePointInitializer(DENOISE_SAMPLES, 2, 1.5),
                NORMAL_VECTOR_TYPE: 0,
                DEPTH_VALUE_SOURCE: 0,
            },
            uniforms: THREE.UniformsUtils.clone(PoissonDenoiseShader.uniforms),
            vertexShader: PoissonDenoiseShader.vertexShader,
            fragmentShader: PoissonDenoiseShader.fragmentShader,
            depthTest: false,
            depthWrite: false,
            blending: THREE.NoBlending,
            toneMapped: false,
        });
        const denoise = this.denoiseMaterial.uniforms;
        denoise.tDiffuse.value = this.aoTarget.texture;
        denoise.tDepth.value = depthTexture;
        denoise.tNormal.value = null;
        denoise.tNoise.value = this.denoiseNoise;
        denoise.lumaPhi.value = 2;
        denoise.depthPhi.value = 0.8;
        denoise.normalPhi.value = 12;
        denoise.radius.value = 3.5;
        denoise.index.value = 0;

        this.compositeMaterial = new THREE.ShaderMaterial({
            name: COMPOSITE_SHADER.name,
            uniforms: THREE.UniformsUtils.clone(COMPOSITE_SHADER.uniforms),
            vertexShader: COMPOSITE_SHADER.vertexShader,
            fragmentShader: COMPOSITE_SHADER.fragmentShader,
            depthTest: false,
            depthWrite: false,
            blending: THREE.NoBlending,
            toneMapped: false,
        });
        this.compositeMaterial.uniforms.tAo.value = this.denoiseTarget.texture;
        this.quad = new FullScreenQuad(null);
        this.clearColor = new THREE.Color();
    }

    /** EffectComposer подава физическите пиксели; вътрешните targets са 50%. */
    setSize(width, height) {
        const halfWidth = Math.max(1, Math.ceil(width * RESOLUTION_SCALE));
        const halfHeight = Math.max(1, Math.ceil(height * RESOLUTION_SCALE));
        if (halfWidth === this.width && halfHeight === this.height) {
            return;
        }
        this.width = halfWidth;
        this.height = halfHeight;
        this.aoTarget.setSize(halfWidth, halfHeight);
        this.denoiseTarget.setSize(halfWidth, halfHeight);
        this.gtaoMaterial.uniforms.resolution.value.set(halfWidth, halfHeight);
        this.denoiseMaterial.uniforms.resolution.value.set(halfWidth, halfHeight);
    }

    render(renderer, writeBuffer, readBuffer) {
        if (this.disposed) {
            return;
        }
        const camera = this.camera;
        const gtao = this.gtaoMaterial.uniforms;
        gtao.cameraNear.value = camera.near;
        gtao.cameraFar.value = camera.far;
        gtao.cameraProjectionMatrix.value.copy(camera.projectionMatrix);
        gtao.cameraProjectionMatrixInverse.value.copy(camera.projectionMatrixInverse);
        gtao.cameraWorldMatrix.value.copy(camera.matrixWorld);
        this.denoiseMaterial.uniforms.cameraProjectionMatrixInverse.value.copy(camera.projectionMatrixInverse);
        this.compositeMaterial.uniforms.tDiffuse.value = readBuffer.texture;

        const clearColor = renderer.getClearColor(this.clearColor);
        const clearAlpha = renderer.getClearAlpha();
        const autoClear = renderer.autoClear;
        renderer.autoClear = false;
        try {
            // Discard-натият фон остава бял (= без AO), не черен ореол.
            renderer.setClearColor(0xffffff, 1);
            renderer.setRenderTarget(this.aoTarget);
            renderer.clear(true, false, false);
            this.quad.material = this.gtaoMaterial;
            this.quad.render(renderer);

            renderer.setRenderTarget(this.denoiseTarget);
            renderer.clear(true, false, false);
            this.quad.material = this.denoiseMaterial;
            this.quad.render(renderer);

            renderer.setRenderTarget(this.renderToScreen ? null : writeBuffer);
            this.quad.material = this.compositeMaterial;
            this.quad.render(renderer);
        } finally {
            renderer.autoClear = autoClear;
            renderer.setClearColor(clearColor, clearAlpha);
        }
    }

    dispose() {
        if (this.disposed) {
            return;
        }
        this.disposed = true;
        this.aoTarget.dispose();
        this.denoiseTarget.dispose();
        this.gtaoNoise.dispose();
        this.denoiseNoise.dispose();
        this.gtaoMaterial.dispose();
        this.denoiseMaterial.dispose();
        this.compositeMaterial.dispose();
        // FullScreenQuad използва споделената Pass.js геометрия; тя се пази за
        // останалите passes и се освобождава с WebGL контекста.
        this.quad.material = null;
    }
}

/**
 * @param {{camera: THREE.PerspectiveCamera, depthTexture: THREE.DepthTexture}} options
 * @returns {Pass}
 */
export function createUltraGtaoPass({ camera, depthTexture } = {}) {
    if (!camera?.isPerspectiveCamera) {
        throw new TypeError('createUltraGtaoPass: camera must be a PerspectiveCamera');
    }
    if (!depthTexture?.isDepthTexture) {
        throw new TypeError('createUltraGtaoPass: depthTexture must be a DepthTexture');
    }

    return new HalfResolutionGtaoPass(camera, depthTexture);
}
