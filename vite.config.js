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
        // public/.vite/manifest.json: Einstieg → gehashte Dateinamen. Wird
        // serverseitig gelesen (App\Support\ViteAssets für die Backend-Seite,
        // ext/inquies/pokerth/event/listener.php fürs Forum).
        manifest: true,
        rollupOptions: {
            input: {
                pth: 'resources/js/app.js',
                spectool: 'resources/js/spectool.js',
            },
            output: {
                // Alles mit Content-Hash im Namen: eine neue Version hat eine
                // neue URL, Browser und Cloudflare können nichts Veraltetes
                // ausliefern, ein Cache-Purge ist nie nötig.
                entryFileNames: 'js/[name]-[hash].js',
                chunkFileNames: 'js/chunks/[name]-[hash].js',
                assetFileNames: (assetInfo) => {
                    const name = assetInfo.names?.[0] ?? assetInfo.name ?? ''
                    if (name.endsWith('.css')) {
                        return 'css/[name]-[hash][extname]'
                    }
                    return 'assets/[name]-[hash][extname]'
                },
            },
        },
    },
})
