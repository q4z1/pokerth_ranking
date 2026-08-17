import '../css/pth.css'
import './countries.js'

console.log('[pth.js] loaded')

// Element Plus Dark Mode: html.fd_dark → html.dark synchronisieren
// Außerdem data-theme für pth CSS-Variablen setzen
//
// Ausnahme: Auf der Internals-Seite (Laravel-Backend) gibt es kein phpbb und
// damit kein fd_dark – dort steuert resources/js/admin/theme.js das Theme.
const isInternals = document.documentElement.classList.contains('internals')

function syncDarkMode() {
    const html = document.documentElement
    const shouldBeDark = html.classList.contains('fd_dark')
    const isDark = html.classList.contains('dark')
    if (shouldBeDark && !isDark) html.classList.add('dark')
    if (!shouldBeDark && isDark) html.classList.remove('dark')
    // data-theme für --pth-* CSS-Variablen (colors.css)
    const currentTheme = html.getAttribute('data-theme')
    const targetTheme = shouldBeDark ? 'dark' : 'light'
    if (currentTheme !== targetTheme) html.setAttribute('data-theme', targetTheme)
}
if (!isInternals) {
    setTimeout(syncDarkMode, 100)
    const observer = new MutationObserver(syncDarkMode)
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
}

import { createApp, defineAsyncComponent } from 'vue'
import axios from 'axios'
window.axios = axios

// Komponenten NICHT eager laden.
//
// Mit { eager: true } landeten alle 26 Komponenten samt Element Plus in
// pth.js – 1,19 MB, ausgeliefert auf jeder Seite, auch dort, wo gar keine
// Komponente vorkommt. Das Parsen blockierte den Main-Thread so lange, dass
// forum_fn.js erst nach 14 s die Navbar zusammenklappen konnte.
//
// Ohne eager liefert der glob Loader-Funktionen; Vite legt jede Komponente in
// einen eigenen Chunk und der Browser holt nur die, deren Tag auch wirklich
// im Dokument steht. Element Plus hängt an denselben Chunks und kommt damit
// ebenfalls nur, wenn eine Komponente montiert wird.
const componentModules = import.meta.glob('./components/*.vue')

// Hilfsfunktion: PascalCase → kebab-case
function toKebab(str) {
    return str.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase()
}

/**
 * Durchläuft alle Kind-Elemente eines Containers und mountet jedes
 * bekannte Komponenten-Tag als eigene Vue-App direkt auf dem Element selbst.
 * Statische HTML-Kinder (PayPal, Discord-iframe, etc.) bleiben unberührt.
 *
 * <div id="vue1"><downloads-component></downloads-component></div>
 * <div id="vue2">
 *   <champion-of-day-component></champion-of-day-component>
 *   <div class="paypal_btn">...</div>   ← bleibt unberührt
 *   <div id="discw"><iframe ...></div>  ← bleibt unberührt
 *   <adverts-component position="home"></adverts-component>
 * </div>
 */
async function mountComponentOn(childEl) {
    const tag = childEl.tagName.toLowerCase()

    // Passenden Loader suchen – noch ohne zu laden.
    let loader = null
    for (const path in componentModules) {
        const fileName = path.split('/').pop().replace(/\.vue$/, '')
        if (toKebab(fileName) === tag) {
            loader = componentModules[path]
            break
        }
    }
    if (!loader) return // kein Vue-Komponenten-Tag → unberührt lassen

    // Erst jetzt Komponente und Element Plus holen.
    const [componentModule, elementPlusModule] = await Promise.all([
        loader(),
        import('./element-plus-bundle.js'),
    ])
    const rootComponent = componentModule.default
    const ElementPlus = elementPlusModule.default

    // Attribute als Props übergeben
    const props = {}
    for (const attr of childEl.attributes) {
        if (attr.name.startsWith(':')) {
            try { props[attr.name.slice(1)] = JSON.parse(attr.value) } catch { props[attr.name.slice(1)] = attr.value }
        } else {
            props[attr.name] = attr.value
        }
    }

    const app = createApp(rootComponent, Object.keys(props).length ? props : undefined)
    app.use(ElementPlus)
    app.config.errorHandler = (err, instance, info) => {
        console.error(`[pth.js] Vue error on <${tag}> (${info}):`, err)
    }
    // Alle Komponenten global registrieren (für verschachtelte Verwendung).
    // defineAsyncComponent hält die Registrierung, ohne die Datei zu laden –
    // geholt wird eine verschachtelte Komponente erst, wenn sie im Template
    // tatsächlich gerendert wird.
    for (const path in componentModules) {
        const fileName = path.split('/').pop().replace(/\.vue$/, '')
        const asyncComponent = defineAsyncComponent(componentModules[path])
        app.component(fileName, asyncComponent)
        app.component(toKebab(fileName), asyncComponent)
    }
    app.mount(childEl)
    console.log(`[pth.js] mounted <${tag}>`)
}

function mountApp(selector) {
    const el = document.querySelector(selector)
    if (!el) return
    // Snapshot der Kinder (mount verändert ggf. die Liste)
    Array.from(el.children).forEach(mountComponentOn)
}

// Ranking-Seiten: multiple unabhängige Vue-Instanzen
// #vue-header sitzt im Teaser (overall_header.html) und ist auf jeder Seite da.
;['#vue1', '#vue2', '#vue3', '#vue4', '#vue-header'].forEach(sel => mountApp(sel))