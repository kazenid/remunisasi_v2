import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import Components from 'unplugin-vue-components/vite';
import { PrimeVueResolver } from '@primevue/auto-import-resolver';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    optimizeDeps: {
        noDiscovery: true,
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            host: 'localhost',
        },
        // Docker Desktop (Windows) tidak meneruskan event inotify dari bind mount,
        // jadi perubahan berkas tidak terdeteksi. Aktif hanya lewat env compose dev.
        watch: {
            usePolling: process.env.VITE_USE_POLLING === 'true',
            interval: 300,
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
        tailwindcss(),
        Components({
            resolvers: [PrimeVueResolver()],
            dirs: ['resources/js/Components', 'resources/js/Layouts'],
            dts: false,
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    css: {
        preprocessorOptions: {
            scss: {
                api: 'modern-compiler',
            },
        },
    },
});
