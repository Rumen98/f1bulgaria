import '../css/app.css';
import 'flag-icons/css/flag-icons.min.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createSSRApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// Заглавието е сървърно (App\Support\Seo) — при SPA навигация просто
// прилагаме новото от props-а, за да съвпада с това, което вижда Google.
router.on('navigate', (event) => {
    const title = event.detail.page?.props?.seoTitle;

    if (title) {
        document.title = title;
    }
});

createInertiaApp({
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    // createSSRApp (не createApp) — хидратира сървърно рендерирания HTML,
    // вместо да го рендира наново. Работи и без SSR демона.
    setup({ el, App, props, plugin }) {
        return createSSRApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
