import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    // SSR bundle-ът е самодостатъчен: без това Vite externalize-ва vue и
    // @inertiajs/vue3, демонът иска жив node_modules по време на работа и
    // `npm ci --omit=dev` го чупи с ERR_MODULE_NOT_FOUND.
    ssr: {
        noExternal: true,
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            ssr: 'resources/js/ssr.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
