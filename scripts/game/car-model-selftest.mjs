/**
 * Регресия: бавното зареждане на модела не оставя играча с резервен болид.
 *   node scripts/game/car-model-selftest.mjs
 */

import assert from 'node:assert/strict';
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { attachCarModel, buildCar } from '../../resources/js/game/car.js';

const originalLoad = GLTFLoader.prototype.load;
const originalSetTimeout = globalThis.setTimeout;
const originalClearTimeout = globalThis.clearTimeout;
const timers = new Map();
const rigs = [];
let clock = 0;
let timerId = 0;
let missingAsset = true;

function createRig() {
    const rig = buildCar();
    rigs.push(rig);
    return rig;
}

function modelScene() {
    const scene = new THREE.Group();
    const material = new THREE.MeshStandardMaterial();
    const body = new THREE.Mesh(new THREE.BoxGeometry(1, 0.6, 4.6), material);
    body.name = 'appliances_appliances_0';
    body.position.y = 0.5;
    scene.add(body);
    for (const [name, z] of [['frontWheel_L_frontWheel_0', 1.5], ['rearWheel_L_rearWheel_0', -1.5]]) {
        const left = new THREE.BoxGeometry(0.35, 0.7, 0.7).translate(-0.9, 0.35, z);
        const right = new THREE.BoxGeometry(0.35, 0.7, 0.7).translate(0.9, 0.35, z);
        const pair = new THREE.Mesh(mergeGeometries([left, right]), material);
        pair.name = name;
        scene.add(pair);
        left.dispose();
        right.dispose();
    }
    return scene;
}

async function advance(milliseconds) {
    clock += milliseconds;
    for (const [id, timer] of timers) {
        if (timer.at <= clock) {
            timers.delete(id);
            timer.callback();
        }
    }
    // Изчакваме веригите load → template → rig → Game.ready.
    for (let i = 0; i < 6; i++) {
        await Promise.resolve();
    }
}

try {
    globalThis.setTimeout = (callback, delay) => {
        timers.set(++timerId, { callback, at: clock + delay });
        return timerId;
    };
    globalThis.clearTimeout = (id) => timers.delete(id);
    GLTFLoader.prototype.load = (_url, onLoad, _onProgress, onError) => {
        setTimeout(() => missingAsset ? onError(new Error('missing model')) : onLoad({ scene: modelScene() }), 16000);
    };

    const fallback = createRig();
    const failed = attachCarModel(fallback, () => false);
    await advance(16000);
    await failed;
    assert.equal(fallback.model, null, 'Липсващ асет запазва процедурния болид.');
    assert.ok(fallback.body.children.every((child) => child.visible));

    missingAsset = false;
    const player = createRig();
    const oldBody = [...player.body.children];
    const oldWheels = [...player.wheels];
    const abandoned = createRig();
    let stale = false;
    let ready = false;
    const attached = attachCarModel(player, () => false).then(() => { ready = true; });
    const cancelled = attachCarModel(abandoned, () => stale);

    await advance(15000);
    assert.equal(ready, false, 'Готовността трябва да чака модела, а не 15-секунден timeout.');
    stale = true;
    await advance(1000);
    await Promise.all([attached, cancelled]);

    assert.equal(player.model?.name, 'car-glb', 'Болидът се подменя и при изтегляне над 15 секунди.');
    assert.ok(oldBody.every((child) => !child.visible), 'Резервното тяло е скрито.');
    assert.ok(oldWheels.every((wheel) => !wheel.steer.visible), 'Резервните колела са скрити.');
    assert.equal(player.wheels.length, 4);
    assert.ok(player.wheels.every((wheel) => wheel.steer.parent === player.unsprung));
    assert.equal(abandoned.model, null, 'Започнала или освободена игра не получава късна подмяна.');
    assert.ok(abandoned.body.children.every((child) => child.visible));

    console.log('СЕЛФТЕСТ ОК: бавен модел, липсващ асет и отменена подмяна.');
} finally {
    GLTFLoader.prototype.load = originalLoad;
    globalThis.setTimeout = originalSetTimeout;
    globalThis.clearTimeout = originalClearTimeout;
    for (const rig of rigs) {
        rig.dispose();
    }
}
