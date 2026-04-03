import '../css/pth.css'
import './countries.js'

console.log('[pth.js] loaded')

// Element Plus Dark Mode: html.fd_dark → html.dark synchronisieren
// Außerdem data-theme für pth CSS-Variablen setzen
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
setTimeout(syncDarkMode, 100)
const observer = new MutationObserver(syncDarkMode)
observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

import { createApp } from 'vue'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import 'element-plus/theme-chalk/dark/css-vars.css'

import axios from 'axios'
window.axios = axios

// Alle Vue-Komponenten per glob importieren
const componentModules = import.meta.glob('./components/*.vue', { eager: true })

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
function mountComponentOn(childEl) {
    const tag = childEl.tagName.toLowerCase()

    // Passende Komponente suchen
    let rootComponent = null
    for (const path in componentModules) {
        const fileName = path.split('/').pop().replace(/\.vue$/, '')
        if (toKebab(fileName) === tag) {
            rootComponent = componentModules[path].default
            break
        }
    }
    if (!rootComponent) return // kein Vue-Komponenten-Tag → unberührt lassen

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
    // Alle Komponenten global registrieren (für verschachtelte Verwendung)
    for (const path in componentModules) {
        const fileName = path.split('/').pop().replace(/\.vue$/, '')
        app.component(fileName, componentModules[path].default)
        app.component(toKebab(fileName), componentModules[path].default)
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
;['#vue1', '#vue2', '#vue3', '#vue4'].forEach(sel => mountApp(sel))