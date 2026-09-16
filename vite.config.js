import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

/**
 * Two frontends on one Laravel install, mirroring the ordering system so the
 * conventions and build output are familiar:
 *
 *   punch   — the public clock-in PWA staff use on their own phones
 *   console — the owner/manager back office, role and outlet gated
 *
 * No webfont plugin here, unlike the ordering system: a shop's punch screen does
 * not need a custom typeface, and skipping the network fetch means a faster
 * clock-in on a weak connection.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                // Public punch PWA (no login)
                'resources/js/punch/main.js',
                // Management console (owner + manager)
                'resources/js/console/main.js',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
