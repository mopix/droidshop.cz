import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // Two independent bundles: the admin is a Vue/Inertia SPA, the
            // storefront is server-rendered HTML with small islands and must
            // not carry a framework runtime (storefront rule, < 100 kB gzip).
            input: [
                'resources/js/app.js',
                'resources/css/storefront.css',
                'resources/js/storefront.js',
                // One entry per theme that has its own typography. Separate
                // bundles so a shop only downloads the webfaces of the theme
                // it actually runs.
                'resources/css/themes/editorial.css',
                'resources/css/themes/retail.css',
            ],
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
