import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/account-portal/main.tsx',
                'resources/js/dashboard/main.tsx',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        // `make dev` runs Vite inside its own compose container with
        // `--host 0.0.0.0` so the app container and the host browser can
        // both reach it. Vite would then advertise the bind address in
        // `public/hot`, but Chrome blocks 0.0.0.0 fetches — pin the origin
        // to localhost so the browser hits the published 5173 port.
        // The `cors.origin` regex allowlists requests from the Laravel
        // app on http://localhost:<any-port>; without it Vite refuses
        // cross-origin module fetches from http://localhost:8080.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        cors: {
            origin: /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/,
        },
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
