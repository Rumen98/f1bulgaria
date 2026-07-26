import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
// Генерира се от `php artisan ziggy:generate` (виж npm script "build").
// В Node няма глобален Ziggy обект от @routes, затова се внася явно.
import { Ziggy } from './ziggy';

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => `${title} - Падок`,
        resolve: (name) => {
            const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
            return pages[`./Pages/${name}.vue`];
        },
        setup({ App, props, plugin }) {
            return createSSRApp({ render: () => h(App, props) })
                .use(plugin)
                .use(ZiggyVue, {
                    ...Ziggy,
                    location: new URL(page.props.ziggy?.location ?? Ziggy.url),
                });
        },
    }),
);
