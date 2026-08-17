// Element Plus samt eigenem CSS in einem eigenen Modul.
//
// Warum eine separate Datei: würden die CSS-Importe in app.js stehen, zöge
// Vite sie in pth.css – und die hängt als <link> im <head> auf jeder Seite,
// also render-blockierend, mit 377 KB. Seit die Komponenten lazy sind, wird
// Element Plus aber nur noch geladen, wenn wirklich eine montiert wird.
//
// Als dynamischer Import landen JS und CSS zusammen in einem Chunk. Vite
// schiebt das zugehörige <link> beim Laden des Chunks nach und wartet, bis es
// da ist, bevor das Modul auflöst – die Komponente mountet also nie vor ihrem
// Stylesheet.
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import 'element-plus/theme-chalk/dark/css-vars.css'

export default ElementPlus
