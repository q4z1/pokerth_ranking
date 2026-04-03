import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

// https://vitejs.dev/config/
export default defineConfig({
    base: '/pthranking/',
    plugins: [
        vue({
            template: {
                // Keine img/video/source src-Attribute in Module-Imports umwandeln –
                // Bilder werden vom phpbb-Webroot geliefert, nicht vom Build.
                transformAssetUrls: { img: [], video: [], source: [] },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/spectool', import.meta.url)),
        },
    },
    build: {
        outDir: 'public',
        emptyOutDir: false,
        rollupOptions: {
            input: {
                pth: 'resources/js/app.js',
                spectool: 'resources/js/spectool.js',
                injections: 'resources/js/injections.js',
            },
            output: {
                // Stable entry filenames so phpbb can hardcode them
                entryFileNames: 'js/[name].js',
                // Shared chunks (Vue, Element Plus) get hashed names
                chunkFileNames: 'js/chunks/[name]-[hash].js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name?.endsWith('.css')) {
                        return 'css/[name][extname]'
                    }
                    return 'assets/[name]-[hash][extname]'
                },
            },
        },
    },
})
