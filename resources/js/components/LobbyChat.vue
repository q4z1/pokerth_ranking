<template>
    <admin-panel
        title="Lobby chat"
        :subtitle="subtitle"
        :count="filteredRows.length"
        :loading="loading"
        @refresh="load"
    >
        <template #toolbar>
            <el-input
                v-model="search"
                class="admin-toolbar__search"
                placeholder="Search nickname or text"
                :prefix-icon="Search"
                clearable
            />
            <el-select v-model="limit" class="admin-toolbar__select" @change="load">
                <el-option v-for="n in limits" :key="n" :label="`Last ${n} lines`" :value="n" />
            </el-select>
            <el-tooltip content="Target language for 🌐" placement="top">
                <el-select v-model="targetLang" class="admin-toolbar__select" filterable>
                    <el-option v-for="l in languages" :key="l.value" :label="l.label" :value="l.value" />
                </el-select>
            </el-tooltip>
        </template>

        <div ref="box" v-loading="loading" class="lobbychat">
            <div v-if="!filteredRows.length" class="lobbychat__empty admin-muted">{{ emptyText }}</div>
            <div v-for="row in filteredRows" :key="row.key" class="lobbychat__line" :class="{ 'is-translated': row.shown }">
                <span class="lobbychat__time" :title="row.time">{{ formatTime(row.ts) }}</span>
                <a class="lobbychat__name" href="#" title="Filter by this player" @click.prevent="search = row.name">{{ row.name }}:</a>
                <span class="lobbychat__msg">
                    <i v-if="row.shown">{{ row.translation }}</i>
                    <template v-else>{{ row.msg }}</template>
                </span>
                <button
                    type="button"
                    class="lobbychat__globe"
                    :class="{ 'is-busy': row.busy }"
                    :title="row.shown ? 'Show original' : 'Translate'"
                    :disabled="row.busy"
                    @click="toggleTranslation(row)"
                >{{ row.busy ? '⏳' : '🌐' }}</button>
            </div>
        </div>
    </admin-panel>
</template>

<script>
import { Search } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import { apiGet, notice, reportError } from '../admin/adminUtils.js'
import { TRANSLATE_LANGUAGES, normalizeLangCode, translateText } from '../admin/chatTranslator.js'

const LANG_KEY = 'pth-lobbychat-lang'
const POLL_MS = 30000

function storedLang() {
    try {
        const v = localStorage.getItem(LANG_KEY)
        if (v) return v
    } catch (e) {
        // localStorage gesperrt - Browsersprache reicht.
    }
    return normalizeLangCode(navigator.language)
}

export default {
    name: 'LobbyChat',
    components: { AdminPanel },
    data() {
        return {
            rows: [],
            loading: false,
            search: '',
            limit: 1000,
            limits: [500, 1000, 5000, 20000],
            targetLang: storedLang(),
            languages: TRANSLATE_LANGUAGES,
            modified: null,
            Search,
        }
    },
    computed: {
        filteredRows() {
            const term = this.search.trim().toLowerCase()
            if (!term) return this.rows
            return this.rows.filter((r) => r.name.toLowerCase().includes(term) || r.msg.toLowerCase().includes(term))
        },
        subtitle() {
            const last = this.modified ? ' · last write ' + this.formatTime(this.modified) : ''
            return 'local time' + last
        },
        emptyText() {
            return this.loading ? 'Loading…' : 'No chat messages.'
        },
    },
    watch: {
        targetLang(value) {
            try {
                localStorage.setItem(LANG_KEY, value)
            } catch (e) {
                // egal, gilt dann nur für diese Sitzung
            }
            // Übersetzungen gelten nur für die Sprache, in der sie geholt wurden.
            this.rows.forEach((r) => {
                r.translation = null
                r.shown = false
            })
        },
        search() {
            this.scrollToBottom()
        },
    },
    mounted() {
        this.load()
        this.timer = setInterval(() => {
            if (!document.hidden) this.poll()
        }, POLL_MS)
        document.addEventListener('visibilitychange', this.onVisible)
    },
    beforeUnmount() {
        clearInterval(this.timer)
        document.removeEventListener('visibilitychange', this.onVisible)
    },
    methods: {
        onVisible() {
            if (!document.hidden) this.poll()
        },
        formatTime(ts) {
            if (!ts) return '—'
            const d = new Date(ts * 1000)
            const p = (n) => String(n).padStart(2, '0')
            return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
        },
        isAtBottom() {
            const box = this.$refs.box
            return !box || box.scrollHeight - box.scrollTop - box.clientHeight < 40
        },
        /**
         * Stabiler Key je Zeile (statt Index), damit Übersetzungen einen
         * Reload überleben, auch wenn vorne Zeilen herausrotieren.
         */
        buildRows(list) {
            const old = new Map(this.rows.map((r) => [r.key, r]))
            const seen = new Map()
            return list.map((r) => {
                const base = `${r.ts}|${r.name}|${r.msg}`
                const n = (seen.get(base) || 0) + 1
                seen.set(base, n)
                const key = `${base}|${n}`
                const prev = old.get(key)
                return { ...r, key, translation: prev?.translation ?? null, shown: prev?.shown ?? false, busy: prev?.busy ?? false }
            })
        },
        scrollToBottom() {
            this.$nextTick(() => {
                const box = this.$refs.box
                if (box) box.scrollTop = box.scrollHeight
            })
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/lobbychat', { limit: this.limit })
                if (data.success) {
                    this.rows = this.buildRows(data.list)
                    this.modified = data.modified
                    this.scrollToBottom()
                } else {
                    this.rows = []
                    notice(data.msg || 'Could not load the lobby chat.', 'warning')
                }
            } catch (err) {
                reportError(err, 'Could not load the lobby chat.')
            } finally {
                this.loading = false
            }
        },
        /**
         * Stiller 30s-Reload: kein Spinner, keine Fehler-Toasts. Gescrollt
         * wird nur, wenn man ohnehin unten stand - wer hochgescrollt hat
         * und liest, wird nicht weggerissen.
         */
        async poll() {
            if (this.loading) return
            try {
                const data = await apiGet('/lobbychat', { limit: this.limit })
                if (!data.success || data.modified === this.modified) return
                const stick = this.isAtBottom()
                this.rows = this.buildRows(data.list)
                this.modified = data.modified
                if (stick) this.scrollToBottom()
            } catch (err) {
                console.warn('LobbyChat: refresh failed', err)
            }
        },
        /** Wie in den Clients: 🌐 schaltet zwischen Übersetzung und Original um, ⏳ = läuft. */
        async toggleTranslation(row) {
            if (row.shown) {
                row.shown = false
                return
            }
            if (row.translation !== null) {
                row.shown = true
                return
            }
            const lang = this.targetLang
            row.busy = true
            try {
                const text = await translateText(row.msg, lang)
                if (lang !== this.targetLang) return
                row.translation = text
                row.shown = true
            } catch (err) {
                // Original bleibt stehen, 🌐 erlaubt einen neuen Versuch.
                console.warn('ChatTranslator: translation failed', err)
                notice('Translation failed, please try again later.', 'warning')
            } finally {
                row.busy = false
            }
        },
    },
}
</script>
