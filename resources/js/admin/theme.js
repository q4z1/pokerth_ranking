/**
 * Theme-Umschaltung für das Laravel-Backend (/pthranking/).
 *
 * Es werden zwei Marker auf <html> gesetzt:
 *   class="dark"        → Element Plus Dark Mode (theme-chalk/dark/css-vars.css)
 *   data-theme="dark"   → --pth-* Variablen aus resources/css/colors.css
 *
 * Der gewählte Wert landet in localStorage; welcome.blade.php wendet ihn vor
 * dem ersten Paint an, damit es nicht kurz aufblitzt.
 */
export const THEME_KEY = 'pth-admin-theme'

export function preferredTheme() {
    try {
        const stored = window.localStorage.getItem(THEME_KEY)
        if (stored === 'dark' || stored === 'light') return stored
    } catch {
        /* localStorage kann blockiert sein */
    }
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export function applyTheme(theme) {
    const html = document.documentElement
    html.classList.toggle('dark', theme === 'dark')
    html.setAttribute('data-theme', theme)
    try {
        window.localStorage.setItem(THEME_KEY, theme)
    } catch {
        /* ignore */
    }
    return theme
}
