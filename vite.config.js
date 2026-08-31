import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ isSsrBuild }) => ({
    // SSR bundle-ът е самодостатъчен: без това Vite externalize-ва vue и
    // @inertiajs/vue3, демонът иска жив node_modules по време на работа и
    // `npm ci --omit=dev` го чупи с ERR_MODULE_NOT_FOUND.
    ssr: {
        noExternal: true,
    },
    // game/device.js се дели СТАТИЧНО между страницата на играта и lazy game
    // chunk-а (Game.js). Без собствен чънк Rollup сгъва цялата страница в
    // споделен `_Index` чънк — тя изпада от Vite manifest-а и @vite() в
    // blade-а гърми с 500. Само за клиентския билд (SSR не ползва manifest-а).
    build: isSsrBuild
        ? {}
        : {
            rollupOptions: {
                output: {
                    manualChunks: (id) =>
                        id.replaceAll('\\', '/').includes('resources/js/game/device.js')
                            ? 'game-device'
                            : undefined,
                },
            },
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
}));
