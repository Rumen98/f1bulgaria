<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stats: { type: Object, required: true },
    // Нови точки от току-що приключилия кръг; null извън режим резултат.
    newPoints: { type: Number, default: null },
    authenticated: { type: Boolean, default: false },
});

const pct = computed(() => (props.stats.available ? Math.round((props.stats.points / props.stats.available) * 100) : 0));
const remaining = computed(() => Math.max(0, props.stats.available - props.stats.points));
</script>

<template>
    <!-- Гост: точките са причината да си направи акаунт. -->
    <section v-if="!authenticated" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="font-semibold text-white">Точките ти не се записват</p>
                <p class="mt-0.5 text-sm text-zinc-400">
                    Влез, за да трупаш точки от {{ stats.available }} въпроса и да влезеш в класацията.
                </p>
            </div>
            <Link
                :href="route('register')"
                class="shrink-0 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-500"
            >
                Регистрирай се
            </Link>
        </div>
    </section>

    <section v-else class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-sm font-black uppercase tracking-wide text-zinc-300">Твоите точки</h2>
            <p
                v-if="newPoints"
                class="rounded-full bg-emerald-500/15 px-2.5 py-0.5 text-xs font-bold text-emerald-400"
            >
                +{{ newPoints }} {{ newPoints === 1 ? 'нова точка' : 'нови точки' }}
            </p>
        </div>

        <div class="flex items-end gap-2">
            <span class="font-display text-4xl font-black leading-none tabular-nums text-white">{{ stats.points }}</span>
            <span class="pb-1 text-sm text-zinc-500">/ {{ stats.available }} въпроса</span>
        </div>

        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-800">
            <div
                class="h-full bg-gradient-to-r from-red-600 to-amber-400 transition-all duration-700"
                :style="{ width: pct + '%' }"
            />
        </div>

        <p class="mt-2 text-xs text-zinc-500">
            <template v-if="remaining > 0">
                Всеки въпрос дава точка при първия верен отговор. Остават ти
                <span class="font-semibold text-zinc-300">{{ remaining }}</span>.
            </template>
            <template v-else>
                Покори всички въпроси. Нови се добавят редовно — върни се за още.
            </template>
        </p>

        <dl v-if="stats.attempts" class="mt-3 flex gap-5 border-t border-zinc-800 pt-3 text-xs">
            <div>
                <dt class="text-zinc-500">Изиграни кръгове</dt>
                <dd class="font-display font-black tabular-nums text-zinc-200">{{ stats.attempts }}</dd>
            </div>
            <div v-if="stats.best_score !== null">
                <dt class="text-zinc-500">Най-добър кръг</dt>
                <dd class="font-display font-black tabular-nums text-zinc-200">{{ stats.best_score }}/{{ stats.best_total }}</dd>
            </div>
        </dl>
    </section>
</template>
