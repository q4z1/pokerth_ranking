/**
 * Spectator Tool – separate Vite entry
 *
 * Vue, Pinia und vue-router werden hier importiert. Vite extrahiert
 * gemeinsame Dependencies (Vue etc.) automatisch in einen shared chunk,
 * sodass Vue nur einmal im Browser geladen wird (zusammen mit pth.js).
 *
 * phpbb-Einbindung:
 *   <script type="module" src="/pthranking/js/spectool.js"></script>
 *   ...auf der Seite: <div id="spectator-app"></div>
 */
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from '../spectool/router'
import SpectoolApp from '../spectool/App.vue'

// Spectool CSS (Tailwind v4 + pth color tokens)
import '../spectool/style.css'

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('spectator-app')
    if (!el) return

    const app = createApp(SpectoolApp)
    app.use(createPinia())
    app.use(router)
    app.mount(el)
})
