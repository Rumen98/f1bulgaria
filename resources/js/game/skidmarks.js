/**
 * Персистентни следи от гуми на ИГРАЧА: пистата помни къде си плъзгал.
 *
 * Един ring buffer от предварително заделени quad-ове в една BufferGeometry
 * (един draw call). Всеки кадър със задействан trigger добавя по един quad на
 * задно колело — от предишната до текущата му позиция. Най-старите следи
 * изчезват, когато ring-ът ги превърти. Същата рецепта като статичните
 * спирачни следи в decor.js (RGBA vertex цветове, depthWrite off).
 */

import * as THREE from 'three';

/** Максимален брой quad-ове (по 2 на кадър при плъзгане ≈ 5+ обиколки следи). */
const MAX_QUADS = 700;

/** Полуширина на следата, метри. */
const HALF_WIDTH = 0.15;

/** Височина над асфалта — над боядосаната линия (0.006), под кербовете. */
const LIFT = 0.009;

export class SkidMarks {
    /** @param {THREE.Scene} scene */
    constructor(scene) {
        this.head = 0;

        this.positions = new Float32Array(MAX_QUADS * 4 * 3);
        this.colors = new Float32Array(MAX_QUADS * 4 * 4);

        const indices = new Uint32Array(MAX_QUADS * 6);
        for (let q = 0; q < MAX_QUADS; q++) {
            const v = q * 4;
            indices.set([v, v + 1, v + 2, v + 1, v + 3, v + 2], q * 6);
        }

        const geometry = new THREE.BufferGeometry();
        // Атрибутите се качват наново при всеки положен quad — DynamicDraw
        // подсказва на драйвера да не ги пази като статичен буфер.
        geometry.setAttribute(
            'position',
            new THREE.BufferAttribute(this.positions, 3).setUsage(THREE.DynamicDrawUsage)
        );
        geometry.setAttribute(
            'color',
            new THREE.BufferAttribute(this.colors, 4).setUsage(THREE.DynamicDrawUsage)
        );
        geometry.setIndex(new THREE.BufferAttribute(indices, 1));
        geometry.boundingSphere = new THREE.Sphere(new THREE.Vector3(), 1e6);

        this.mesh = new THREE.Mesh(
            geometry,
            new THREE.MeshBasicMaterial({
                vertexColors: true,
                transparent: true,
                depthWrite: false,
                // Напречната ос на колата е ОБРАТНА на нормалата на трасето при
                // движение напред → winding-ът на quad-а гледа надолу и
                // FrontSide би отрязал следата точно в основния ѝ случай.
                side: THREE.DoubleSide,
            })
        );
        this.mesh.frustumCulled = false;
        this.mesh.renderOrder = 1;
        scene.add(this.mesh);

        // Последните световни позиции на двете задни колела: два преизползвани
        // буфера, разменяни всеки кадър (ping-pong) — нула алокации в hot path-а.
        this._pointsA = [{ x: 0, y: 0, z: 0 }, { x: 0, y: 0, z: 0 }];
        this._pointsB = [{ x: 0, y: 0, z: 0 }, { x: 0, y: 0, z: 0 }];
        this.last = null;
    }

    /**
     * Вика се всеки кадър. Когато trigger-ът е вдигнат, полага следа от
     * предишната позиция на колелата до текущата.
     *
     * @param {boolean} laying Дали в момента се плъзга/заключва
     * @param {object} render {x, z, heading}
     * @param {number} groundY Височина на асфалта под колата
     * @param {number} bank Напречен наклон (surface.bank)
     */
    update(laying, render, groundY, bank) {
        if (!laying) {
            this.last = null;
            return;
        }

        const sin = Math.sin(render.heading);
        const cos = Math.cos(render.heading);

        // Задните колела: локално (±0.82, -1.55) → световно; банкингът
        // накланя платното по локалната напречна ос. Пише се в свободния
        // от двата преизползвани буфера — не в нов обект.
        const current = this.last === this._pointsA ? this._pointsB : this._pointsA;
        for (let w = 0; w < 2; w++) {
            const side = w === 0 ? 0.82 : -0.82;
            const point = current[w];
            point.x = render.x + sin * -1.55 + cos * side;
            point.y = groundY + LIFT - side * bank;
            point.z = render.z + cos * -1.55 - sin * side;
        }

        if (this.last !== null) {
            for (let w = 0; w < 2; w++) {
                this.#quad(this.last[w], current[w], sin, cos);
            }
        }

        this.last = current;
    }

    /**
     * Един quad от a до b, широк 2·HALF_WIDTH напречно на посоката.
     *
     * @param {{x:number,y:number,z:number}} a
     * @param {{x:number,y:number,z:number}} b
     */
    #quad(a, b, sin, cos) {
        // Прекалено къс сегмент (почти спряла кола) само трупа z-fighting.
        if ((b.x - a.x) ** 2 + (b.z - a.z) ** 2 < 0.0004) {
            return;
        }

        const q = this.head;
        this.head = (this.head + 1) % MAX_QUADS;

        // Напречната ос на колата: (cos, -sin) в XZ.
        const px = cos * HALF_WIDTH;
        const pz = -sin * HALF_WIDTH;

        const base = q * 12;
        this.positions.set(
            [
                a.x - px, a.y, a.z - pz,
                a.x + px, a.y, a.z + pz,
                b.x - px, b.y, b.z - pz,
                b.x + px, b.y, b.z + pz,
            ],
            base
        );

        const colorBase = q * 16;
        for (let v = 0; v < 4; v++) {
            this.colors.set([0.03, 0.03, 0.035, 0.34], colorBase + v * 4);
        }

        this.mesh.geometry.attributes.position.needsUpdate = true;
        this.mesh.geometry.attributes.color.needsUpdate = true;
    }

    dispose() {
        this.mesh.geometry.dispose();
        this.mesh.material.dispose();
    }
}
