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
    // Тези леки game модули се делят СТАТИЧНО между страницата на играта и
    // lazy game chunk-а (Game.js). Без собствени chunks Rollup сгъва цялата
    // страница в споделен `_Index` chunk — тя изпада от Vite manifest-а и
    // @vite() в blade-а гърми с 500. Само за клиентския билд.
    build: isSsrBuild
        ? {}
        : {
            rollupOptions: {
                output: {
                    manualChunks: (id) => {
                        const normalized = id.replaceAll('\\', '/');

                        if (normalized.includes('resources/js/game/device.js')) {
                            return 'game-device';
                        }

                        if (normalized.includes('resources/js/game/circuits.js')) {
                            return 'game-circuits';
                        }

                        return undefined;
                    },
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
