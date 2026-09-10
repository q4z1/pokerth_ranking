/**
 * Spectator Tool – separate Vite entry
 *
 * Das eigentliche Live-Tool ist narmods Webclient (https://webclient.pokerth.net/live);
 * hier hängt nur noch die Einbettung samt Höhen-Handshake.
 *
 * phpbb-Einbindung:
 *   <script type="module" src="/pthranking/js/spectool.js"></script>
 *   ...auf der Seite: <div id="spectator-app"></div>
 */
import { createApp } from 'vue'
import SpectoolApp from '../spectool/App.vue'

import '../spectool/style.css'

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('spectator-app')
    if (!el) return

    createApp(SpectoolApp).mount(el)
})
