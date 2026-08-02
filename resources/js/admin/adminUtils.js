import { ElMessage, ElMessageBox } from 'element-plus'

/** Alle Backend-Routen hängen unter /pthranking. */
export const API_BASE = '/pthranking'

/**
 * `state`-Spalte von reported_gamename / reported_avatar.
 * Die Werte sind in der DB als Spaltenkommentar dokumentiert.
 */
export const REPORT_STATES = {
    0: { label: 'New', type: 'danger' },
    1: { label: 'Ignored', type: 'info' },
    2: { label: 'Warned', type: 'warning' },
    3: { label: 'Banned', type: 'success' },
    4: { label: 'Confirmed', type: 'primary' },
    5: { label: 'Report spam', type: 'info' },
}

export const STATE_NEW = 0
export const STATE_IGNORED = 1
export const STATE_WARNED = 2
export const STATE_BANNED = 3
export const STATE_REPORTER_SPAM = 5

/** Ab so vielen Meldungen gilt ein Spieler als Wiederholungstäter. */
export const REPEAT_THRESHOLD = 3

export function stateMeta(state) {
    return REPORT_STATES[state] ?? { label: 'Unknown', type: 'info' }
}

export function notice(message, type = 'success') {
    ElMessage({ message, type, offset: 70, showClose: true })
}

export function confirmAction(message, title = 'Please confirm', options = {}) {
    return ElMessageBox.confirm(message, title, {
        confirmButtonText: 'OK',
        cancelButtonText: 'Cancel',
        type: 'warning',
        ...options,
    })
}

/** Fehler einheitlich melden statt nur in die Konsole zu loggen. */
export function reportError(err, fallback = 'Request failed.') {
    console.error(err)
    const msg = err?.response?.data?.msg || err?.response?.statusText || err?.message
    notice(msg ? `${fallback} (${msg})` : fallback, 'error')
}

export async function apiGet(path, params = {}) {
    const res = await window.axios.get(API_BASE + path, { params })
    return res.data
}

export async function apiPost(path, payload = {}) {
    const form = new FormData()
    for (const [key, value] of Object.entries(payload)) {
        if (Array.isArray(value)) value.forEach((v) => form.append(`${key}[]`, v))
        else if (value !== null && value !== undefined) form.append(key, value)
    }
    const res = await window.axios.post(API_BASE + path, form)
    return res.data
}

/** "2026-07-31 18:23:34" → "2026-07-31 18:23" (ohne Zeitzonen-Umrechnung). */
export function formatDateTime(value) {
    if (!value) return '—'
    const m = String(value).match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/)
    if (m) return `${m[1]} ${m[2]}`
    const d = String(value).match(/^(\d{4}-\d{2}-\d{2})/)
    return d ? d[1] : String(value)
}

export function formatDate(value) {
    if (!value) return '—'
    const d = String(value).match(/^(\d{4}-\d{2}-\d{2})/)
    return d ? d[1] : String(value)
}

/**
 * Gemeinsame Liste-/Such-/Blätter-Logik aller Backend-Tabellen, damit sich alle
 * Seiten gleich verhalten. Komponenten überschreiben `matches(row, term)`.
 */
export const listMixin = {
    data() {
        return {
            rows: [],
            loading: false,
            search: '',
            page: 1,
            pageSize: 25,
            sortProp: null,
            sortOrder: null,
        }
    },
    computed: {
        filteredRows() {
            const term = this.search.trim().toLowerCase()
            const rows = this.baseRows
            if (!term) return rows
            return rows.filter((row) => this.matches(row, term))
        },
        /**
         * Sortiert wird über den kompletten Datensatz, nicht über die aktuelle
         * Seite – deshalb `sortable="custom"` in den Tabellen.
         */
        sortedRows() {
            if (!this.sortProp || !this.sortOrder) return this.filteredRows
            const dir = this.sortOrder === 'ascending' ? 1 : -1
            const prop = this.sortProp
            return [...this.filteredRows].sort((a, b) => compareValues(a[prop], b[prop]) * dir)
        },
        pagedRows() {
            const start = (this.page - 1) * this.pageSize
            return this.sortedRows.slice(start, start + this.pageSize)
        },
        /** Hook für zusätzliche Filter vor der Textsuche. */
        baseRows() {
            return this.rows
        },
    },
    watch: {
        search() {
            this.page = 1
        },
        filteredRows(rows) {
            const maxPage = Math.max(1, Math.ceil(rows.length / this.pageSize))
            if (this.page > maxPage) this.page = maxPage
        },
    },
    methods: {
        matches() {
            return true
        },
        onPageSizeChange(size) {
            this.pageSize = size
            this.page = 1
        },
        onSortChange({ prop, order }) {
            this.sortProp = order ? prop : null
            this.sortOrder = order
            this.page = 1
        },
    },
}

function compareValues(a, b) {
    if (a === b) return 0
    if (a === null || a === undefined) return -1
    if (b === null || b === undefined) return 1
    if (typeof a === 'number' && typeof b === 'number') return a - b
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' })
}
