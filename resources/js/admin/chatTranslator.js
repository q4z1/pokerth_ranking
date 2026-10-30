/**
 * Chat-Übersetzung wie in den Clients (src/gui/chattranslator/chattranslatorcore.cpp
 * im pokerth-Repo): erst Googles keyless gtx-Endpoint, bei Fehler MyMemory.
 * Die Anfrage geht direkt vom Browser des Admins raus - beide Dienste senden
 * Access-Control-Allow-Origin: *. Bewusst fetch() statt axios: dessen
 * Default-Header (X-Requested-With, X-CSRF-TOKEN) würden einen Preflight
 * auslösen, den die Dienste nicht beantworten.
 */

/** Zielsprachen-Auswahl im Backend (ISO-Codes wie bei den Diensten). */
export const TRANSLATE_LANGUAGES = [
    { value: 'de', label: 'Deutsch' },
    { value: 'en', label: 'English' },
    { value: 'fr', label: 'Français' },
    { value: 'es', label: 'Español' },
    { value: 'it', label: 'Italiano' },
    { value: 'nl', label: 'Nederlands' },
    { value: 'pl', label: 'Polski' },
    { value: 'pt-PT', label: 'Português' },
    { value: 'pt-BR', label: 'Português (Brasil)' },
    { value: 'ru', label: 'Русский' },
    { value: 'uk', label: 'Українська' },
    { value: 'tr', label: 'Türkçe' },
    { value: 'cs', label: 'Čeština' },
    { value: 'hu', label: 'Magyar' },
    { value: 'ro', label: 'Română' },
    { value: 'el', label: 'Ελληνικά' },
    { value: 'sv', label: 'Svenska' },
    { value: 'da', label: 'Dansk' },
    { value: 'no', label: 'Norsk' },
    { value: 'fi', label: 'Suomi' },
    { value: 'ar', label: 'العربية' },
    { value: 'zh-CN', label: '中文 (简体)' },
    { value: 'zh-TW', label: '中文 (繁體)' },
    { value: 'ja', label: '日本語' },
]

/** Entspricht ChatTranslatorCore::normalizeLangCode für Browser-Locales ("de-DE" → "de"). */
export function normalizeLangCode(raw) {
    const code = String(raw || '').trim().replace(/_/g, '-')
    if (!code) return 'en'
    for (const regional of ['pt-BR', 'pt-PT', 'zh-CN', 'zh-TW']) {
        if (code.toLowerCase().startsWith(regional.toLowerCase())) return regional
    }
    const lang = code.split('-')[0].toLowerCase()
    if (lang === 'pt') return 'pt-PT'
    if (lang === 'zh') return 'zh-CN'
    if (lang === 'nb' || lang === 'nn') return 'no'
    return lang
}

async function viaGoogle(text, target) {
    const params = new URLSearchParams({ client: 'gtx', sl: 'auto', tl: target, dt: 't', q: text })
    const res = await fetch('https://translate.googleapis.com/translate_a/single?' + params)
    if (!res.ok) throw new Error('Google HTTP ' + res.status)
    // Antwort: [[["translation","original",…],[…]], null, "en", …]
    const data = await res.json()
    const result = Array.isArray(data?.[0])
        ? data[0].map((seg) => (Array.isArray(seg) ? seg[0] || '' : '')).join('')
        : ''
    if (!result.trim()) throw new Error('Google: empty translation')
    return result
}

async function viaMyMemory(text, target) {
    const params = new URLSearchParams({ q: text, langpair: 'Autodetect|' + target })
    const res = await fetch('https://api.mymemory.translated.net/get?' + params)
    if (!res.ok) throw new Error('MyMemory HTTP ' + res.status)
    const data = await res.json()
    const result = data?.responseData?.translatedText || ''
    // MyMemory meldet Fehler (Quota, ungültiges Sprachpaar) mit HTTP 200 und
    // schreibt die Warnung in translatedText - responseStatus muss geprüft werden.
    if (parseInt(data?.responseStatus, 10) !== 200) {
        // Quelle == Ziel: nichts zu übersetzen, wie bei Google das Original zeigen.
        if (/DISTINCT LANGUAGES/i.test(result)) return text
        throw new Error('MyMemory: ' + result.slice(0, 120))
    }
    if (!result.trim()) throw new Error('MyMemory: empty translation')
    return result
}

export async function translateText(text, target) {
    try {
        return await viaGoogle(text, target)
    } catch (err) {
        console.warn('ChatTranslator: Google failed, falling back to MyMemory', err)
        return viaMyMemory(text, target)
    }
}
