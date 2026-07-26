import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { route, ZiggyVue } from '../../vendor/tightenco/ziggy';
// Генерира се от `php artisan ziggy:generate` (виж npm script "build").
// В Node няма глобален Ziggy обект от @routes, затова се внася явно.
import { Ziggy } from './ziggy';

// В браузъра @routes дефинира глобалния `route()`. В Node го няма, а
// resources/js/utils/routes.js го вика извън компонентен контекст — без това
// hasRoute() винаги връща false и навигацията изчезва от SSR HTML-а.
globalThis.route = (name, params, absolute) => route(name, params, absolute, Ziggy);

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        // БЕЗ title callback: заглавието идва от app.blade.php (App\Support\Seo).
        // Ако Inertia емитира и свое през @inertiaHead, страницата получава
        // два <title> тага и Google чете грешния.
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
