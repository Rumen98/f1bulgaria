import { plot } from '@/utils/chart';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/** Под тази ширина на контейнера графиката минава в тясната геометрия. */
const NARROW = 640;

/**
 * Геометрията на една ръчно рисувана SVG графика според ширината на
 * контейнера ѝ.
 *
 * Проблемът не е в шрифта, а в мащаба: viewBox-ът е 800 единици и се разтяга
 * до ширината на родителя. На телефон полето е ~326 CSS пиксела, тоест мащаб
 * 0,41 — и етикет с font-size 11 се рендира като 4,5 пиксела. По-тесният
 * viewBox вдига мащаба, по-едрият шрифт довършва работата, а по-малкото
 * деления пазят етикетите от застъпване.
 *
 * Мери се КОНТЕЙНЕРЪТ, а не прозорецът: графиките стоят в карти, които на
 * широк екран може да са в две колони — ширината на прозореца не казва колко
 * място е останало за конкретната графика.
 *
 * @param {{width: number, height: number, padding?: object}} desktop
 * @param {{width: number, height: number, padding?: object}} mobile
 * @returns {{host: object, box: object, isNarrow: object, fontSize: object, tickCount: object}}
 *          `host` се закача за кореновия елемент — без него няма какво да се мери.
 */
export function useChartBox(
    desktop = { width: 800, height: 300 },
    mobile = { width: 420, height: 300 },
) {
    const host = ref(null);

    // Началото е ВИНАГИ desktop. Сървърният рендер няма контейнер, който да
    // измери, а различна начална стойност би дала несъвпадение при
    // хидратацията. Смяната е чак в onMounted.
    const isNarrow = ref(false);

    let observer = null;

    onMounted(() => {
        if (host.value && typeof ResizeObserver === 'function') {
            observer = new ResizeObserver(([entry]) => {
                const width = entry.contentRect.width;

                // Нулева ширина значи скрит контейнер, а не телефон.
                if (width > 0) {
                    isNarrow.value = width < NARROW;
                }
            });

            observer.observe(host.value);

            return;
        }

        // Браузърите без ResizeObserver нямат и слушател за MediaQueryList,
        // затова тук се мери веднъж: по-добре разумна геометрия при монтиране,
        // отколкото изключение, което сваля цялата страница.
        isNarrow.value = window.matchMedia(`(max-width: ${NARROW}px)`).matches;
    });

    onUnmounted(() => {
        observer?.disconnect();
        observer = null;
    });

    const geometry = computed(() => (isNarrow.value ? mobile : desktop));

    const box = computed(() => plot(geometry.value.width, geometry.value.height, geometry.value.padding));

    const fontSize = computed(() => (isNarrow.value ? 16 : 11));

    const tickCount = computed(() => (isNarrow.value ? 3 : 5));

    return { host, box, isNarrow, fontSize, tickCount };
}
