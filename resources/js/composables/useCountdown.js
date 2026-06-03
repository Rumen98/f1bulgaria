import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * Реактивен обратен брояч до целеви ISO datetime.
 *
 * @param {() => (string|null)} targetGetter  функция, връщаща ISO стринг (или null)
 * @returns {{days, hours, minutes, seconds, finished}}  реактивни computed стойности
 */
export function useCountdown(targetGetter) {
    const now = ref(Date.now());
    let timer = null;

    onMounted(() => {
        timer = setInterval(() => {
            now.value = Date.now();
        }, 1000);
    });

    onUnmounted(() => {
        if (timer) {
            clearInterval(timer);
        }
    });

    const diff = computed(() => {
        const target = targetGetter();
        if (!target) {
            return null;
        }
        return Math.max(0, new Date(target).getTime() - now.value);
    });

    const finished = computed(() => diff.value !== null && diff.value <= 0);

    const days = computed(() => (diff.value === null ? 0 : Math.floor(diff.value / 86_400_000)));
    const hours = computed(() => (diff.value === null ? 0 : Math.floor((diff.value % 86_400_000) / 3_600_000)));
    const minutes = computed(() => (diff.value === null ? 0 : Math.floor((diff.value % 3_600_000) / 60_000)));
    const seconds = computed(() => (diff.value === null ? 0 : Math.floor((diff.value % 60_000) / 1_000)));

    return { days, hours, minutes, seconds, finished };
}
