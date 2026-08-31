/**
 * Частици: дим от гумите, чакълен спрей и искри от кербовете.
 *
 * Два pool-а (Points) с общ шейдър — един draw call за дим/чакъл (normal
 * blending) и един за искрите (additive). Всички буфери са заделени веднъж;
 * spawn-ът върти ring указател, мъртвите частици са с размер 0. Нула
 * алокации на кадър — същата дисциплина като _render обекта в Game.js.
 */

import * as THREE from 'three';

const VERTEX = /* glsl */ `
    uniform float uScale;
    attribute float aSize;
    attribute float aAlpha;
    varying float vAlpha;
    varying vec3 vColor;

    void main() {
        vColor = color;
        vAlpha = aAlpha;
        vec4 mv = modelViewMatrix * vec4(position, 1.0);
        // Перспективно смаляване; 150 е емпиричен мащаб за FOV ~62-84.
        // gl_PointSize е в БУФЕРНИ пиксели: uScale = реален DPR × renderScale
        // (Game го подава) — така размерът спрямо колата е еднакъв на всяко
        // устройство и не подскача при стъпка на резолюционния governor.
        gl_PointSize = aSize * (150.0 * uScale / max(1.0, -mv.z));
        gl_Position = projectionMatrix * mv;
    }
`;

const FRAGMENT = /* glsl */ `
    varying float vAlpha;
    varying vec3 vColor;

    void main() {
        float d = length(gl_PointCoord - vec2(0.5));
        float mask = smoothstep(0.5, 0.12, d);
        gl_FragColor = vec4(vColor, mask * vAlpha);
    }
`;

class ParticlePool {
    /**
     * @param {number} capacity
     * @param {THREE.Blending} blending
     */
    constructor(capacity, blending) {
        this.capacity = capacity;
        this.head = 0;

        this.positions = new Float32Array(capacity * 3);
        this.colors = new Float32Array(capacity * 3);
        this.sizes = new Float32Array(capacity);
        this.alphas = new Float32Array(capacity);

        // Симулационно състояние (не отива към GPU).
        this.velocity = new Float32Array(capacity * 3);
        this.life = new Float32Array(capacity);
        this.maxLife = new Float32Array(capacity);
        this.growth = new Float32Array(capacity);
        this.gravity = new Float32Array(capacity);
        this.baseAlpha = new Float32Array(capacity);

        const geometry = new THREE.BufferGeometry();
        // Пренаписват се при всеки жив кадър — DynamicDraw подсказва на
        // драйвера да не ги третира като статични буфери (мобилните GPU-та
        // могат да блокират на STATIC_DRAW + чест bufferSubData).
        geometry.setAttribute(
            'position',
            new THREE.BufferAttribute(this.positions, 3).setUsage(THREE.DynamicDrawUsage)
        );
        geometry.setAttribute(
            'color',
            new THREE.BufferAttribute(this.colors, 3).setUsage(THREE.DynamicDrawUsage)
        );
        geometry.setAttribute(
            'aSize',
            new THREE.BufferAttribute(this.sizes, 1).setUsage(THREE.DynamicDrawUsage)
        );
        geometry.setAttribute(
            'aAlpha',
            new THREE.BufferAttribute(this.alphas, 1).setUsage(THREE.DynamicDrawUsage)
        );
        // Частиците са около колата — сферата се сеща веднъж, широко.
        geometry.boundingSphere = new THREE.Sphere(new THREE.Vector3(), 1e6);

        this.mesh = new THREE.Points(
            geometry,
            new THREE.ShaderMaterial({
                vertexShader: VERTEX,
                fragmentShader: FRAGMENT,
                uniforms: {
                    // 2 = десктоп DPR по подразбиране; Game сетва реалната
                    // стойност през ParticleEffects.setScale.
                    uScale: { value: 2 },
                },
                vertexColors: true,
                transparent: true,
                depthWrite: false,
                blending,
            })
        );
        this.mesh.frustumCulled = false;
        this.mesh.renderOrder = 3;

        // GPU буферите се качват само при живи частици/спаунове (виж update).
        this.spawned = false;
    }

    /**
     * @param {number} x @param {number} y @param {number} z
     * @param {number} vx @param {number} vy @param {number} vz
     * @param {object} opts {life, size, growth, gravity, r, g, b, alpha}
     */
    spawn(x, y, z, vx, vy, vz, opts) {
        const i = this.head;
        this.head = (this.head + 1) % this.capacity;

        this.positions[i * 3] = x;
        this.positions[i * 3 + 1] = y;
        this.positions[i * 3 + 2] = z;
        this.velocity[i * 3] = vx;
        this.velocity[i * 3 + 1] = vy;
        this.velocity[i * 3 + 2] = vz;
        this.colors[i * 3] = opts.r;
        this.colors[i * 3 + 1] = opts.g;
        this.colors[i * 3 + 2] = opts.b;
        this.life[i] = opts.life;
        this.maxLife[i] = opts.life;
        this.sizes[i] = opts.size;
        this.growth[i] = opts.growth;
        this.gravity[i] = opts.gravity;
        this.baseAlpha[i] = opts.alpha;
        this.alphas[i] = opts.alpha;
        this.spawned = true;
    }

    /** @param {number} dt */
    update(dt) {
        const n = this.capacity;
        let anyAlive = false;

        for (let i = 0; i < n; i++) {
            if (this.life[i] <= 0) {
                continue;
            }
            anyAlive = true;

            this.life[i] -= dt;

            if (this.life[i] <= 0) {
                this.sizes[i] = 0;
                this.alphas[i] = 0;
                continue;
            }

            this.velocity[i * 3 + 1] -= this.gravity[i] * dt;
            this.positions[i * 3] += this.velocity[i * 3] * dt;
            this.positions[i * 3 + 1] += this.velocity[i * 3 + 1] * dt;
            this.positions[i * 3 + 2] += this.velocity[i * 3 + 2] * dt;

            const t = this.life[i] / this.maxLife[i];
            this.alphas[i] = this.baseAlpha[i] * t;
            this.sizes[i] += this.growth[i] * dt;
        }

        // Празен pool (никой жив, нищо спаунато) = нула GPU трафик. Умиращите
        // частици пак стигат до GPU: в кадъра на смъртта anyAlive още е true.
        if (!anyAlive && !this.spawned) {
            return;
        }

        const geometry = this.mesh.geometry;
        geometry.attributes.position.needsUpdate = true;
        geometry.attributes.aSize.needsUpdate = true;
        geometry.attributes.aAlpha.needsUpdate = true;
        // Цветът се пише само в spawn() — качва се само при нови частици.
        if (this.spawned) {
            geometry.attributes.color.needsUpdate = true;
            this.spawned = false;
        }
    }

    dispose() {
        this.mesh.geometry.dispose();
        this.mesh.material.dispose();
    }
}

export class ParticleEffects {
    /** @param {THREE.Scene} scene */
    constructor(scene) {
        // Дим + чакъл: плътни, normal blending. Искри: адитивни.
        this.smoke = new ParticlePool(192, THREE.NormalBlending);
        this.sparks = new ParticlePool(64, THREE.AdditiveBlending);
        scene.add(this.smoke.mesh);
        scene.add(this.sparks.mesh);

        // Спаун темпо (акумулатори — независими от кадровата честота).
        this.smokeAccum = 0;
        this.gravelAccum = 0;
        this.sparkAccum = 0;
    }

    /**
     * Мащаб на точките в буферни пиксели: реален DPR × renderScale. Game го
     * подава при създаване и при всяка стъпка на резолюционния governor.
     *
     * @param {number} scale
     */
    setScale(scale) {
        this.smoke.mesh.material.uniforms.uScale.value = scale;
        this.sparks.mesh.material.uniforms.uScale.value = scale;
    }

    /**
     * Ефектите на болида за този кадър. Всичко е ЧИСТО визуално — чете
     * състоянието, не го пипа.
     *
     * @param {number} dt
     * @param {object} render Интерполираното състояние {x, z, heading, vForward}
     * @param {number} groundY Височина на асфалта под колата
     * @param {{slip: number}} state
     * @param {{offSurface: string|null, onKerb: boolean}} sim
     */
    update(dt, render, groundY, state, sim) {
        const speed = Math.abs(render.vForward);
        const sin = Math.sin(render.heading);
        const cos = Math.cos(render.heading);

        // Локални → световни: задните колела са на (±0.82, -1.55).
        const wheelX = (lx, lz) => render.x + sin * lz + cos * lx;
        const wheelZ = (lx, lz) => render.z + cos * lz - sin * lx;

        // ── Дим от гумите при плъзгане ───────────────────────────────────
        if (state.slip > 0.3 && speed > 15) {
            this.smokeAccum += dt * 60;
            while (this.smokeAccum >= 1) {
                this.smokeAccum -= 1;
                const side = Math.random() > 0.5 ? 0.82 : -0.82;
                const g = 0.42 + Math.random() * 0.12;
                this.smoke.spawn(
                    wheelX(side, -1.55),
                    groundY + 0.25,
                    wheelZ(side, -1.55),
                    (Math.random() - 0.5) * 1.5 - sin * speed * 0.12,
                    0.8 + Math.random() * 0.8,
                    (Math.random() - 0.5) * 1.5 - cos * speed * 0.12,
                    { life: 0.9, size: 0.8, growth: 2.2, gravity: -0.4, r: g, g, b: g, alpha: 0.3 }
                );
            }
        }

        // ── Чакълен спрей ────────────────────────────────────────────────
        if (sim.offSurface === 'gravel' && speed > 6) {
            this.gravelAccum += dt * 90;
            while (this.gravelAccum >= 1) {
                this.gravelAccum -= 1;
                const side = (Math.random() - 0.5) * 1.7;
                this.smoke.spawn(
                    wheelX(side, -1.4),
                    groundY + 0.15,
                    wheelZ(side, -1.4),
                    (Math.random() - 0.5) * 4 - sin * speed * 0.35,
                    2 + Math.random() * 3.5,
                    (Math.random() - 0.5) * 4 - cos * speed * 0.35,
                    // Цветът на чакъла (DECOR.gravel), лек шум.
                    { life: 0.55, size: 0.22, growth: 0, gravity: 14, r: 0.72, g: 0.66, b: 0.53, alpha: 0.85 }
                );
            }
        }

        // ── Искри от кербовете ───────────────────────────────────────────
        if (sim.onKerb && speed > 40) {
            this.sparkAccum += dt * 50;
            while (this.sparkAccum >= 1) {
                this.sparkAccum -= 1;
                const side = Math.random() > 0.5 ? 0.85 : -0.85;
                this.sparks.spawn(
                    wheelX(side, -1.5 + Math.random() * 3),
                    groundY + 0.06,
                    wheelZ(side, -1.5 + Math.random() * 3),
                    (Math.random() - 0.5) * 3 - sin * speed * 0.55,
                    0.6 + Math.random() * 1.6,
                    (Math.random() - 0.5) * 3 - cos * speed * 0.55,
                    { life: 0.3, size: 0.14, growth: 0, gravity: 22, r: 1.0, g: 0.62, b: 0.2, alpha: 1.0 }
                );
            }
        }

        this.smoke.update(dt);
        this.sparks.update(dt);
    }

    /**
     * Взрив при контакт между коли (вика се от Game при силен удар).
     *
     * @param {number} x @param {number} y @param {number} z
     * @param {number} strength 0..1
     */
    burst(x, y, z, strength) {
        const count = Math.round(4 + strength * 10);
        for (let i = 0; i < count; i++) {
            this.sparks.spawn(
                x + (Math.random() - 0.5) * 1.2,
                y + 0.3 + Math.random() * 0.5,
                z + (Math.random() - 0.5) * 1.2,
                (Math.random() - 0.5) * 9,
                1 + Math.random() * 4,
                (Math.random() - 0.5) * 9,
                { life: 0.4, size: 0.16, growth: 0, gravity: 18, r: 1.0, g: 0.7, b: 0.3, alpha: 1.0 }
            );
        }
    }

    /**
     * Само остаряване на живите частици, без нови спаунове — за ТВ реплея,
     * където димът от последния жив кадър трябва да догори, не да замръзне.
     *
     * @param {number} dt
     */
    tick(dt) {
        this.smoke.update(dt);
        this.sparks.update(dt);
    }

    dispose() {
        this.smoke.dispose();
        this.sparks.dispose();
    }
}
