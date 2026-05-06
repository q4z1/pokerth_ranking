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
                manualChunks(id) {
                    // Move pinia + vue-router into a separate hashed chunk so that
                    // lazy route chunks never need to import from "../spectool.js".
                    // Without this, the browser treats spectool.js (loaded via script
                    // tag as spectool.js?v=...) and spectool.js (imported by chunks
                    // without the query param) as two separate module instances.
                    // The second instance never runs DOMContentLoaded, so createPinia()
                    // is never called → activePinia = undefined → "_s" TypeError.
                    if (
                        id.includes('node_modules/pinia') ||
                        id.includes('node_modules/vue-router') ||
                        id.includes('node_modules/@vue/devtools-api')
                    ) {
                        return 'spectool-vendor'
                    }
                    // Stores, services, composables and constants are used by both
                    // the entry (App.vue) and the lazy route chunks.  Placing them in
                    // a hashed chunk means both import from spectool-logic-[hash].js
                    // rather than from spectool.js, eliminating the URL mismatch.
                    if (
                        id.includes('/resources/spectool/stores') ||
                        id.includes('/resources/spectool/services') ||
                        id.includes('/resources/spectool/composables') ||
                        id.includes('/resources/spectool/constants')
                    ) {
                        return 'spectool-logic'
                    }
                },
            },
        },
    },
})
