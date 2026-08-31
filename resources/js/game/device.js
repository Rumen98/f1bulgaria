/**
 * Обща преценка „това телефон/таблет ли е?" за Game.js И Game/Index.vue.
 *
 * Отделен модул без three.js: Vue страницата го импортира статично (за
 * контролите), без да дърпа целия game chunk в основния бъндъл. Разминаване
 * между двата детектора даваше десктоп рендер с мобилни контроли (iPad).
 */

/**
 * Телефон/таблет: UA токен ИЛИ тъч + coarse pointer. iPadOS 13+ Safari се
 * представя за Macintosh (без 'iPad'/'Mobile' в UA) — тъч проверката хваща
 * и него.
 *
 * @returns {boolean}
 */
export function isMobileDevice() {
    return (
        /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '') ||
        (navigator.maxTouchPoints > 0 && window.matchMedia('(pointer: coarse)').matches)
    );
}
